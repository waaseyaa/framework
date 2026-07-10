<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel\Bootstrap;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Gate\EntityAccessGate;
use Waaseyaa\Access\Gate\GateInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistryKernelServices;
use Waaseyaa\Foundation\Log\NullLogger;

#[CoversClass(ProviderRegistryKernelServices::class)]
final class ProviderRegistryKernelServicesTest extends TestCase
{
    private function services(
        DatabaseInterface $database,
        ?\Closure $accessHandlerAccessor = null,
    ): ProviderRegistryKernelServices {
        $dispatcher = new EventDispatcher();

        return new ProviderRegistryKernelServices(
            entityTypeManager: new EntityTypeManager($dispatcher),
            database: $database,
            dispatcher: $dispatcher,
            logger: new NullLogger(),
            providersAccessor: static fn(): array => [],
            accessHandlerAccessor: $accessHandlerAccessor,
        );
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
}
