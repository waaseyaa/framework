<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel\Bootstrap;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface as PsrEventDispatcherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface as SymfonyContractsEventDispatcherInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Gate\EntityAccessGate;
use Waaseyaa\Access\Gate\GateInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Foundation\Event\EventDispatcherInterface as FoundationEventDispatcherInterface;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistryKernelServices;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

#[CoversClass(ProviderRegistryKernelServices::class)]
final class ProviderRegistryKernelServicesTest extends TestCase
{
    /** @param list<ServiceProvider> $providers */
    private function services(
        DatabaseInterface $database,
        ?\Closure $accessHandlerAccessor = null,
        ?ApplicationSecret $applicationSecret = null,
        ?EntityTypeManager $entityTypeManager = null,
        array $providers = [],
    ): ProviderRegistryKernelServices {
        $dispatcher = new SymfonyEventDispatcherAdapter();

        return new ProviderRegistryKernelServices(
            entityTypeManager: $entityTypeManager ?? new EntityTypeManager($dispatcher),
            database: $database,
            dispatcher: $dispatcher,
            logger: new NullLogger(),
            providersAccessor: static fn(): array => $providers,
            accessHandlerAccessor: $accessHandlerAccessor,
            applicationSecret: $applicationSecret,
        );
    }

    #[Test]
    public function get_field_definition_registry_returns_the_manager_owned_instance(): void
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $registry = new FieldDefinitionRegistry();
        $manager = new EntityTypeManager($dispatcher, fieldRegistry: $registry);
        $services = $this->services(
            DBALDatabase::createSqlite(),
            entityTypeManager: $manager,
        );

        self::assertSame(
            $registry,
            $services->get(FieldDefinitionRegistryInterface::class),
        );
        self::assertSame(
            $manager->getFieldRegistry(),
            $services->get(FieldDefinitionRegistryInterface::class),
        );
    }

    #[Test]
    public function get_field_definition_registry_returns_null_when_the_manager_has_none(): void
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $manager = new EntityTypeManager($dispatcher);
        $services = $this->services(
            DBALDatabase::createSqlite(),
            entityTypeManager: $manager,
        );

        self::assertNull($services->get(FieldDefinitionRegistryInterface::class));
    }

    #[Test]
    public function kernel_field_registry_shadows_a_sibling_provider_binding(): void
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $canonical = new FieldDefinitionRegistry();
        $duplicate = new FieldDefinitionRegistry();
        $manager = new EntityTypeManager($dispatcher, fieldRegistry: $canonical);
        $provider = new class ($duplicate) extends ServiceProvider {
            public function __construct(private readonly FieldDefinitionRegistryInterface $registry) {}

            public function register(): void
            {
                $this->singleton(
                    FieldDefinitionRegistryInterface::class,
                    fn(): FieldDefinitionRegistryInterface => $this->registry,
                );
            }
        };
        $provider->register();
        $services = $this->services(
            DBALDatabase::createSqlite(),
            entityTypeManager: $manager,
            providers: [$provider],
        );

        self::assertSame($canonical, $services->get(FieldDefinitionRegistryInterface::class));
        self::assertNotSame($duplicate, $services->get(FieldDefinitionRegistryInterface::class));
    }

    #[Test]
    public function get_exposes_the_kernel_owned_application_secret(): void
    {
        $secret = ApplicationSecret::fromEnvironmentValue(null, 'testing');
        $services = $this->services(DBALDatabase::createSqlite(), applicationSecret: $secret);

        self::assertSame($secret, $services->get(ApplicationSecret::class));
    }

    /**
     * #1611 part 1 regression: resolving `DatabaseInterface` through the kernel
     * services must return the kernel's single PERSISTENT connection — the same
     * instance on every call — never a freshly built (ephemeral) one. The
     * dangerous alpha.188 behaviour was that a `ServiceProvider::routes()`
     * build-time `resolve(DatabaseInterface::class)` captured an ephemeral DB
     * whose writes silently never reached the file. The kernel now holds one
     * `$this->database` and hands it back verbatim, so a captured reference is
     * the persistent connection.
     */
    #[Test]
    public function get_database_interface_returns_the_same_persistent_instance(): void
    {
        $persistent = DBALDatabase::createSqlite();
        $services = $this->services($persistent);

        self::assertSame($persistent, $services->get(DatabaseInterface::class));
        self::assertSame($persistent, $services->get(DatabaseInterface::class));
    }

    /**
     * A write made through the resolved connection is visible through a second
     * resolve — concrete proof the two share one connection (not independent
     * ephemeral DBs, which is exactly what silently dropped writes in #1611).
     */
    #[Test]
    public function a_write_through_one_resolve_is_visible_through_another(): void
    {
        $services = $this->services(DBALDatabase::createSqlite());

        $writer = $services->get(DatabaseInterface::class);
        self::assertInstanceOf(DatabaseInterface::class, $writer);
        $writer->query('CREATE TABLE persist_probe (id INTEGER PRIMARY KEY, v TEXT)');
        $writer->insert('persist_probe')->values(['v' => 'persisted'])->execute();

        $reader = $services->get(DatabaseInterface::class);
        self::assertInstanceOf(DatabaseInterface::class, $reader);

        $values = [];
        foreach ($reader->select('persist_probe')->execute() as $row) {
            $values[] = $row['v'];
        }

        self::assertSame(['persisted'], $values);
    }

    /**
     * G-014 (#1940): the kernel-services bus binds a real `GateInterface`
     * once the kernel's access handler is available, so consumers resolving
     * `GateInterface::class` get a working `EntityAccessGate` instead of
     * falling back to a deny-all `Gate([])` (the root cause of the
     * Sheguiandah pass-1 512/512 `entity_create_denied` import failure).
     */
    #[Test]
    public function get_gate_interface_returns_an_entity_access_gate_when_the_handler_is_available(): void
    {
        $handler = new EntityAccessHandler();
        $services = $this->services(
            DBALDatabase::createSqlite(),
            accessHandlerAccessor: static fn(): EntityAccessHandler => $handler,
        );

        $gate = $services->get(GateInterface::class);

        self::assertInstanceOf(EntityAccessGate::class, $gate);
    }

    /**
     * Before {@see \Waaseyaa\Foundation\Kernel\AbstractKernel::discoverAccessPolicies()}
     * runs, no access handler accessor is available — `GateInterface`
     * resolution must degrade to `null` (exactly like the existing
     * `EntityAccessHandler::class` case), not throw or return a
     * non-functional gate.
     */
    #[Test]
    public function get_gate_interface_returns_null_when_the_handler_is_not_available(): void
    {
        $services = $this->services(DBALDatabase::createSqlite());

        self::assertNull($services->get(GateInterface::class));
    }

    /**
     * G-025 (#1940): the kernel-services bus must answer the dispatcher under
     * the PSR-14 contract FQCN, not only the Symfony-contracts one. Pass-1
     * symptom: `EntityDestinationFactory` type-hints
     * `Psr\EventDispatcher\EventDispatcherInterface` per its own docblock and
     * got `null` back, so following the docblock threw "No binding
     * registered".
     */
    #[Test]
    public function get_resolves_the_dispatcher_under_the_psr14_contract(): void
    {
        $services = $this->services(DBALDatabase::createSqlite());

        $dispatcher = $services->get(PsrEventDispatcherInterface::class);

        self::assertNotNull($dispatcher);
        self::assertSame($services->get(SymfonyContractsEventDispatcherInterface::class), $dispatcher);
    }

    /**
     * G-025 (#1940): the latent variant — provider `boot()` code type-hinting
     * the foundation-owned `Waaseyaa\Foundation\Event\EventDispatcherInterface`
     * contract silently got `null` back from the bus (documented victims:
     * Media/Field). The bus must answer this FQCN too, with the same
     * dispatcher instance.
     */
    #[Test]
    public function get_resolves_the_dispatcher_under_the_foundation_contract(): void
    {
        $services = $this->services(DBALDatabase::createSqlite());

        $dispatcher = $services->get(FoundationEventDispatcherInterface::class);

        self::assertNotNull($dispatcher);
        self::assertSame($services->get(SymfonyContractsEventDispatcherInterface::class), $dispatcher);
    }
}
