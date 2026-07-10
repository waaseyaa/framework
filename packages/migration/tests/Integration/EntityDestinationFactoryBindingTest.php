<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Gate\GateInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistryKernelServices;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Migration\Account\MigrationSystemAccount;
use Waaseyaa\Migration\MigrationIdMap;
use Waaseyaa\Migration\Plugin\Destination\EntityDestination;
use Waaseyaa\Migration\Plugin\Destination\EntityDestinationFactory;
use Waaseyaa\Migration\Plugin\DestinationRecord;
use Waaseyaa\Migration\Schema\MigrationIdMapSchema;
use Waaseyaa\Migration\ServiceProvider;
use Waaseyaa\Migration\SourceId;
use Waaseyaa\Migration\Tests\Fixtures\AllowAllPolicy;
use Waaseyaa\Migration\Tests\Fixtures\MigrationTestWidgetType;

/**
 * Adversarial-review follow-up to #1940 (WP-2 rework item 1).
 *
 * {@see EntityDestinationFactory}'s own docblock instructs consumers to
 * `$this->resolve(EntityDestinationFactory::class)->create(...)`, but no
 * ServiceProvider ever bound it — every real call threw
 * `RuntimeException("No binding registered for
 * Waaseyaa\Migration\Plugin\Destination\EntityDestinationFactory.")`.
 *
 * Mirrors {@see LazyRegistryKernelOrderingTest}'s kernel-bus setup: constructs
 * a real {@see ProviderRegistryKernelServices} with a lazy
 * `$accessHandlerAccessor` so the binding closure is proven to run AFTER
 * `discoverAccessPolicies()` makes `GateInterface` live (G-014), not eagerly
 * at `register()`/`boot()` time — resolving the factory before the access
 * handler exists must still fail, exactly like resolving `GateInterface`
 * directly would.
 */
#[CoversNothing]
final class EntityDestinationFactoryBindingTest extends TestCase
{
    #[Test]
    public function factory_is_bound_and_resolves_lazily_after_access_policies_are_discovered(): void
    {
        $db = DBALDatabase::createSqlite();
        $conn = $db->getConnection();
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS "migration_test_widget" ('
            . '"id" INTEGER PRIMARY KEY AUTOINCREMENT, '
            . '"uuid" TEXT, '
            . '"title" TEXT, '
            . '"_data" TEXT DEFAULT \'{}\''
            . ')',
        );
        $conn->executeStatement(MigrationIdMapSchema::createTableSql());

        $entityType = MigrationTestWidgetType::nonRevisionable();
        $typeManager = new EntityTypeManager(new EventDispatcher());
        $typeManager->registerEntityType($entityType);

        $dispatcher = new EventDispatcher();

        // Null until discoverAccessPolicies() runs — mirrors
        // AbstractKernel::$accessHandler / LazyRegistryKernelOrderingTest.
        $accessHandler = null;
        $kernelServices = new ProviderRegistryKernelServices(
            entityTypeManager: $typeManager,
            database: $db,
            dispatcher: $dispatcher,
            logger: new NullLogger(),
            providersAccessor: static fn(): array => [],
            accessHandlerAccessor: static function () use (&$accessHandler): ?EntityAccessHandler {
                return $accessHandler;
            },
        );

        $provider = new ServiceProvider();
        $provider->setKernelContext(projectRoot: \sys_get_temp_dir(), config: [], manifestFormatters: []);
        $provider->setKernelServices($kernelServices);
        $provider->register();
        $provider->boot();

        // Step 1: resolving before access policies are discovered must still
        // fail — GateInterface is not live yet (G-014's degrade-to-null
        // window). This proves the binding closure resolves its
        // collaborators lazily (at first EntityDestinationFactory::class
        // resolve), not eagerly inside register().
        try {
            $provider->resolve(EntityDestinationFactory::class);
            self::fail('Expected resolve() to fail before access policies are discovered.');
        } catch (\RuntimeException) {
            // Expected: GateInterface is not resolvable yet, so building the
            // factory's closure fails exactly like a direct
            // resolve(GateInterface::class) would.
        }

        // Step 2: the access handler becomes live, mirroring
        // AbstractKernel::discoverAccessPolicies().
        $accessHandler = new EntityAccessHandler([new AllowAllPolicy('migration_test_widget')]);

        $factory = $provider->resolve(EntityDestinationFactory::class);
        self::assertInstanceOf(EntityDestinationFactory::class, $factory);

        // Singleton: a second resolve returns the exact same instance.
        self::assertSame($factory, $provider->resolve(EntityDestinationFactory::class));

        $resolver = new SingleConnectionResolver($db);
        $driver = new SqlStorageDriver($resolver, 'id');
        $repository = new EntityRepository(
            entityType: $entityType,
            driver: $driver,
            eventDispatcher: $dispatcher,
        );
        $idMap = new MigrationIdMap($db);

        $destination = $factory->create(
            destinationEntityTypeId: 'migration_test_widget',
            migrationId: 'factory_binding_test',
            idMap: $idMap,
            repository: $repository,
            account: new MigrationSystemAccount(),
        );

        self::assertInstanceOf(EntityDestination::class, $destination);

        // The wired gate/dispatcher are the exact live kernel instances, not
        // stand-ins — assert identity via reflection since EntityDestination
        // exposes no public getters for its collaborators.
        $reflection = new \ReflectionClass($destination);
        $wiredGate = $reflection->getProperty('gate')->getValue($destination);
        $wiredDispatcher = $reflection->getProperty('eventDispatcher')->getValue($destination);

        self::assertSame(
            $kernelServices->get(GateInterface::class),
            $wiredGate,
            'The factory must wire the exact GateInterface instance served by the kernel bus.',
        );
        self::assertSame(
            $dispatcher,
            $wiredDispatcher,
            'The factory must wire the exact dispatcher instance served by the kernel bus.',
        );

        // Prove create() produces a WORKING destination, not just correctly
        // typed collaborators — an end-to-end write through the real
        // persistence pipeline (gate check, entity save, id-map upsert) must
        // succeed.
        $record = new DestinationRecord(
            migrationId: 'factory_binding_test',
            sourceId: new SourceId(sourceType: 'fake_source', keys: ['key' => 'one']),
            values: ['title' => 'Bound via EntityDestinationFactory'],
        );
        $result = $destination->write($record);

        self::assertSame('migration_test_widget', $result->destinationEntityType);
        self::assertNotSame('', $result->destinationUuid);

        $loaded = $repository->findBy(['uuid' => $result->destinationUuid]);
        self::assertCount(1, $loaded);
    }
}
