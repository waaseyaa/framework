<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\CLI\Handler\InstallInitHandler;
use Waaseyaa\CLI\Handler\MigrateHandler;
use Waaseyaa\CLI\Handler\MigrateRollbackHandler;
use Waaseyaa\CLI\Handler\MigrateStatusHandler;
use Waaseyaa\CLI\Provider\MigrateServiceProvider;

/**
 * Regression guard for the migrate* command wiring.
 *
 * `MigrateHandler` / `MigrateRollbackHandler` / `MigrateStatusHandler` can NOT
 * be container auto-wired: their `Migrator` dependency's first ctor parameter is
 * a raw `Doctrine\DBAL\Connection` (auto-wire fails with "unresolvable parameter
 * $params"), and each handler additionally requires a `\Closure` migrations
 * provider (a closure can never be reflection-constructed). So the provider MUST
 * bind them explicitly; if it regresses to leaving them to the console handler
 * container's reflection fallback, every `waaseyaa migrate*` invocation dies at
 * command time in every consumer app.
 */
#[CoversClass(MigrateServiceProvider::class)]
final class MigrateServiceProviderTest extends TestCase
{
    #[Test]
    public function resolving_read_only_status_does_not_install_the_migration_ledger(): void
    {
        $databasePath = tempnam(sys_get_temp_dir(), 'waaseyaa_zero_ddl_');
        self::assertIsString($databasePath);

        try {
            $provider = new MigrateServiceProvider();
            $provider->setKernelContext((string) getcwd(), [
                'environment' => 'testing',
                'database' => $databasePath,
            ], []);
            $provider->register();
            $provider->resolve(MigrateStatusHandler::class);

            $connection = DBALDatabase::createSqlite($databasePath, 'testing')->getConnection();
            $schemaObjects = $connection->executeQuery(
                "SELECT name FROM sqlite_master WHERE type IN ('table', 'index') AND name LIKE 'waaseyaa_%' ORDER BY name",
            )->fetchFirstColumn();
            self::assertSame([], $schemaObjects, 'Resolving a read-only migration command created schema objects.');
        } finally {
            @unlink($databasePath);
        }
    }

    /**
     * #2428: install:init is the governed installation phase. It runs only on a
     * fresh site, so a lost declaration or a rebound handler would surface to
     * the first person installing the framework and to nobody before them.
     */
    #[Test]
    public function it_declares_install_init_bound_to_its_handler(): void
    {
        $provider = new MigrateServiceProvider();
        $provider->setKernelContext('', [], []);
        $provider->register();

        $commands = [];
        foreach ($provider->consoleCommands() as $command) {
            $commands[$command->name] = $command;
        }

        self::assertArrayHasKey('install:init', $commands);
        self::assertSame(InstallInitHandler::class, $commands['install:init']->sourceClass());
        self::assertStringContainsString('initial configuration generation', $commands['install:init']->description);

        // Bound lazily, like the migrate handlers: registering the provider must
        // not open a database or resolve the configuration authority.
        $bindings = $provider->getBindings();
        self::assertArrayHasKey(InstallInitHandler::class, $bindings);
        self::assertIsCallable($bindings[InstallInitHandler::class]['concrete']);
    }

    #[Test]
    public function it_binds_the_migrate_command_handlers_so_they_are_never_auto_wired(): void
    {
        $provider = new MigrateServiceProvider();
        $provider->setKernelContext('', [], []);
        $provider->register();

        $bindings = $provider->getBindings();

        // Each handler is bound (so the handler container resolves it from the
        // provider instead of falling through to the failing reflection path)...
        self::assertArrayHasKey(MigrateHandler::class, $bindings);
        self::assertArrayHasKey(MigrateRollbackHandler::class, $bindings);
        self::assertArrayHasKey(MigrateStatusHandler::class, $bindings);

        // ...as a lazy factory closure, so registering the provider never opens
        // the database (the runtime is built only when a migrate* command runs).
        self::assertIsCallable($bindings[MigrateHandler::class]['concrete']);
        self::assertIsCallable($bindings[MigrateRollbackHandler::class]['concrete']);
        self::assertIsCallable($bindings[MigrateStatusHandler::class]['concrete']);
    }

    #[Test]
    public function migrationRuntimeRejectsMemoryOutsideAnExplicitDevelopmentEnvironment(): void
    {
        $provider = new MigrateServiceProvider();
        $provider->setKernelContext(sys_get_temp_dir(), [
            'environment' => 'staging',
            'database' => ':memory:',
        ], []);
        $provider->register();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('S1-DB002');
        $provider->resolve(MigrateStatusHandler::class);
    }
}
