<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Provider;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Handler\InstallInitHandler;
use Waaseyaa\CLI\Handler\MigrateHandler;
use Waaseyaa\CLI\Handler\MigrateRollbackHandler;
use Waaseyaa\CLI\Handler\MigrateStatusHandler;
use Waaseyaa\CLI\Handler\MutationAuthorityBackfillHandler;
use Waaseyaa\CLI\Provider\MigrateServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\CLI\Tests\Fixtures\RootApplicationV2Migration;
use Waaseyaa\Database\DBALDatabase;

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
    public function declared_commands_apply_and_report_root_v2_migrations_through_the_provider(): void
    {
        $root = sys_get_temp_dir() . '/waaseyaa_provider_v2_' . bin2hex(random_bytes(8));
        mkdir($root . '/vendor/composer', 0o777, true);
        file_put_contents($root . '/vendor/composer/installed.json', '{"packages":[]}');
        file_put_contents($root . '/composer.json', json_encode([
            'name' => 'acme/application',
            'extra' => ['waaseyaa' => ['migrations' => ['Waaseyaa\\CLI\\Tests\\Fixtures']]],
        ], JSON_THROW_ON_ERROR));

        // Supply the optimized application classmap required by V2 discovery.
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $loader->addClassMap([
                RootApplicationV2Migration::class => dirname(__DIR__, 2) . '/Fixtures/RootApplicationV2Migration.php',
            ]);
        }

        $databasePath = $root . '/application.sqlite';
        $connection = DBALDatabase::createSqlite($databasePath, 'testing')->getConnection();
        $connection->executeStatement('CREATE TABLE widgets (id INTEGER PRIMARY KEY)');

        try {
            $provider = new MigrateServiceProvider();
            $provider->setKernelContext($root, ['environment' => 'testing', 'database' => $databasePath], []);
            $provider->register();
            $container = new class ($provider) implements ContainerInterface {
                public function __construct(private MigrateServiceProvider $provider) {}

                public function get(string $id): mixed
                {
                    return $this->provider->resolve($id);
                }

                public function has(string $id): bool
                {
                    return isset($this->provider->getBindings()[$id]);
                }
            };
            $commands = [];
            foreach ($provider->consoleCommands() as $command) {
                $commands[$command->name] = $command;
            }

            $status = CliTester::for($commands['migrate:status'], $container)->execute([]);
            self::assertSame(0, $status->getExitCode());
            self::assertStringContainsString('acme/application:v2:add-widget-profile', $status->getStdout());
            self::assertStringContainsString('Pending', $status->getStdout());
            self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM sqlite_master WHERE name = 'waaseyaa_migrations'"));

            $apply = CliTester::for($commands['migrate'], $container)->execute([]);
            self::assertSame(0, $apply->getExitCode(), $apply->getOutput());
            self::assertContains('profile', array_column($connection->fetchAllAssociative('PRAGMA table_info(widgets)'), 'name'));

            $status->execute([]);
            self::assertSame(0, $status->getExitCode());
            self::assertStringContainsString('acme/application:v2:add-widget-profile', $status->getStdout());
            self::assertStringContainsString('Ran', $status->getStdout());
            self::assertStringNotContainsString('Pending', $status->getStdout());
        } finally {
            $connection->close();
            unset($status, $apply, $commands, $command, $container, $provider);
            gc_collect_cycles();
            new Filesystem()->remove($root);
        }
    }

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
    public function it_declares_the_restricted_legacy_authority_repair_with_an_explicit_reason(): void
    {
        $provider = new MigrateServiceProvider();
        $provider->setKernelContext('', [], []);
        $provider->register();

        $commands = [];
        foreach ($provider->consoleCommands() as $command) {
            $commands[$command->name] = $command;
        }

        self::assertArrayHasKey('entity:backfill-mutation-authorities', $commands);
        self::assertSame(MutationAuthorityBackfillHandler::class, $commands['entity:backfill-mutation-authorities']->sourceClass());
        $options = [];
        foreach ($commands['entity:backfill-mutation-authorities']->handlerOptions() as $option) {
            $options[$option->name] = $option;
        }
        self::assertSame('', $options['reason']->default);
        self::assertArrayHasKey('json', $options);

        $bindings = $provider->getBindings();
        self::assertArrayHasKey(MutationAuthorityBackfillHandler::class, $bindings);
        self::assertIsCallable($bindings[MutationAuthorityBackfillHandler::class]['concrete']);
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

    #[Test]
    public function migrationDiagnosticsUseConfiguredProductionPolicyInsteadOfProcessEnvironment(): void
    {
        $databasePath = tempnam(sys_get_temp_dir(), 'waaseyaa_migrate_policy_');
        self::assertIsString($databasePath);
        putenv('APP_ENV=local');
        $_SERVER['APP_ENV'] = 'local';

        try {
            $provider = new MigrateServiceProvider();
            $provider->setKernelContext((string) getcwd(), [
                'environment' => 'production',
                'database' => $databasePath,
            ], []);
            $provider->register();

            $handler = $provider->resolve(MigrateHandler::class);
            $isProduction = new \ReflectionProperty($handler, 'isProduction');

            self::assertTrue($isProduction->getValue($handler));
        } finally {
            putenv('APP_ENV');
            unset($_SERVER['APP_ENV']);
            @unlink($databasePath);
        }
    }
}
