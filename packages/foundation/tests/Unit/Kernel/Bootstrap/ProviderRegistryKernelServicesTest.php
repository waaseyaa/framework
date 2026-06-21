<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel\Bootstrap;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistryKernelServices;
use Waaseyaa\Foundation\Log\NullLogger;

#[CoversClass(ProviderRegistryKernelServices::class)]
final class ProviderRegistryKernelServicesTest extends TestCase
{
    private function services(DatabaseInterface $database): ProviderRegistryKernelServices
    {
        $dispatcher = new EventDispatcher();

        return new ProviderRegistryKernelServices(
            entityTypeManager: new EntityTypeManager($dispatcher),
            database: $database,
            dispatcher: $dispatcher,
            logger: new NullLogger(),
            providersAccessor: static fn(): array => [],
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
}
