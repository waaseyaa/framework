<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Doctrine\DBAL\Connection;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\InstallInitHandler;
use Waaseyaa\CLI\Handler\MigrateDefaultsHandler;
use Waaseyaa\CLI\Handler\MigrateHandler;
use Waaseyaa\CLI\Handler\MigrateRollbackHandler;
use Waaseyaa\CLI\Handler\MigrateStatusHandler;
use Waaseyaa\CLI\Handler\MutationAuthorityBackfillHandler;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\SqliteTopology;
use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;
use Waaseyaa\Foundation\Kernel\Bootstrap\DatabaseBootstrapper;
use Waaseyaa\Foundation\Kernel\EnvLoader;
use Waaseyaa\Foundation\Migration\MigrationLoader;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\Migrator;
use Waaseyaa\Foundation\Schema\Compiler\Sqlite\SqliteCapabilities;
use Waaseyaa\Foundation\Schema\Compiler\Sqlite\SqliteCompiler;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class MigrateServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface
{
    /**
     * Memoised migration runtime: [Migrator, MigrationRepository, MigrationLoader, Connection, database path].
     *
     * @var array{0: Migrator, 1: MigrationRepository, 2: MigrationLoader, 3: Connection, 4: string}|null
     */
    private ?array $runtime = null;

    /**
     * Bind the migrate* command handlers explicitly.
     *
     * `migrate`, `migrate:rollback` and `migrate:status` resolve `MigrateHandler`
     * / `MigrateRollbackHandler` / `MigrateStatusHandler` from the console
     * handler container. Those handlers take a `Migrator` (whose first ctor
     * param is a raw `Doctrine\DBAL\Connection` — unresolvable by auto-wiring,
     * "unresolvable parameter $params") AND a required `\Closure` migrations
     * provider (a closure can never be auto-wired). So leaving them to the
     * container's reflection fallback throws at command time and `migrate` is
     * dead in every consumer app. Bind them here with the migration runtime
     * built the same way `db:init` builds it (DBALDatabase connection +
     * MigrationRepository + MigrationLoader). The factories are lazy singletons,
     * so the database is only opened when a migrate* command actually runs.
     */
    public function register(): void
    {
        $this->singleton(MutationAuthorityBackfillHandler::class, function (): MutationAuthorityBackfillHandler {
            $entityTypeManager = $this->resolve(\Waaseyaa\Entity\EntityTypeManager::class);
            assert($entityTypeManager instanceof \Waaseyaa\Entity\EntityTypeManagerInterface);

            return new MutationAuthorityBackfillHandler($entityTypeManager);
        });

        // install:init (#2428) reuses this provider's migration runtime because
        // preparing schema IS migrate + schema sync; the handler itself only
        // sequences that and the initial activation, which closes the lifecycle
        // transition a fresh site was missing.
        $this->singleton(InstallInitHandler::class, function (): InstallInitHandler {
            [$migrator, , $loader, , $databasePath] = $this->migrationRuntime();
            $entityTypeManager = $this->resolve(\Waaseyaa\Entity\EntityTypeManager::class);
            $database = $this->resolve(\Waaseyaa\Database\DatabaseInterface::class);
            assert($entityTypeManager instanceof \Waaseyaa\Entity\EntityTypeManager);
            assert($database instanceof \Waaseyaa\Database\DatabaseInterface);
            $activator = $this->resolve(\Waaseyaa\Config\Activation\ConfigurationActivatorInterface::class);
            $genesis = $this->resolve(\Waaseyaa\Config\Activation\ConfigurationGenesisActivatorInterface::class);
            $authority = $this->resolve(\Waaseyaa\Config\Authority\ConfigurationAuthorityContext::class);
            assert($activator instanceof \Waaseyaa\Config\Activation\ConfigurationActivatorInterface);
            assert($genesis instanceof \Waaseyaa\Config\Activation\ConfigurationGenesisActivatorInterface);
            assert($authority instanceof \Waaseyaa\Config\Authority\ConfigurationAuthorityContext);

            return new InstallInitHandler(
                prepareSchema: static function () use ($migrator, $loader, $entityTypeManager, $database): void {
                    $migrator->run($loader->loadAll(), $loader->loadAllV2());
                    new \Waaseyaa\EntityStorage\EntitySchemaSyncRunner(
                        $database,
                        $entityTypeManager->getFieldRegistry(),
                    )->run($entityTypeManager->getDefinitions());
                },
                activator: $activator,
                genesis: $genesis,
                authority: $authority,
                databasePath: $databasePath,
            );
        });

        $this->singleton(MigrateHandler::class, function (): MigrateHandler {
            [$migrator, $repository, $loader, $connection] = $this->migrationRuntime();

            return new MigrateHandler(
                $migrator,
                static fn(): array => $loader->loadAll(),
                static fn(): array => $loader->loadAllV2(),
                $repository,
                $this->sqliteCompiler($connection),
                $this->isProduction(),
            );
        });

        $this->singleton(MigrateRollbackHandler::class, function (): MigrateRollbackHandler {
            [$migrator, , $loader] = $this->migrationRuntime();

            return new MigrateRollbackHandler($migrator, static fn(): array => $loader->loadAll());
        });

        $this->singleton(MigrateStatusHandler::class, function (): MigrateStatusHandler {
            [$migrator, , $loader] = $this->migrationRuntime();

            return new MigrateStatusHandler($migrator, static fn(): array => $loader->loadAll());
        });
    }

    /**
     * Build (once) the migration runtime against the app's SQLite database,
     * mirroring DbInitHandler / AbstractKernel::bootMigrations().
     *
     * @return array{0: Migrator, 1: MigrationRepository, 2: MigrationLoader, 3: Connection, 4: string}
     */
    private function migrationRuntime(): array
    {
        if ($this->runtime !== null) {
            return $this->runtime;
        }

        $projectRoot = $this->projectRoot !== '' ? $this->projectRoot : (string) getcwd();
        // The kernel loads .env at boot, but load it defensively (idempotent) so
        // WAASEYAA_DB resolution matches db:init exactly.
        EnvLoader::load($projectRoot . '/.env');
        DatabaseBootstrapper::assertConfiguredPathShape($this->config);
        $dbPath = DatabaseBootstrapper::resolveDatabasePath($projectRoot, $this->config);

        $database = DBALDatabase::createSqlite($dbPath, SqliteTopology::resolveEnvironment($this->config));
        $connection = $database->getConnection();

        $repository = new MigrationRepository($connection);

        $manifest = new PackageManifestCompiler(
            basePath: $projectRoot,
            storagePath: $projectRoot . '/storage',
        )->load();
        $loader = new MigrationLoader($projectRoot, $manifest);

        $migrator = new Migrator($connection, $repository);

        return $this->runtime = [$migrator, $repository, $loader, $connection, $dbPath];
    }

    /**
     * The SQLite schema compiler used by `migrate --dry-run`, built for the
     * live database's SQLite version so capability gating (rename/drop column)
     * matches the real engine.
     */
    private function sqliteCompiler(Connection $connection): SqliteCompiler
    {
        $version = (string) $connection->fetchOne('SELECT sqlite_version()');

        return new SqliteCompiler(SqliteCapabilities::forVersion($version));
    }

    private function isProduction(): bool
    {
        $appEnv = getenv('APP_ENV');
        if (!is_string($appEnv) || $appEnv === '') {
            $fromServer = $_SERVER['APP_ENV'] ?? null;
            $appEnv = is_string($fromServer) && $fromServer !== '' ? $fromServer : 'production';
        }

        return $appEnv === 'production';
    }

    public function consoleCommands(): iterable
    {
        yield new HandlerCommand(
            name: 'entity:backfill-mutation-authorities',
            description: 'Create missing aggregate mutation authorities for legacy persisted entity rows.',
            options: [
                new HandlerOption(
                    name: 'reason',
                    mode: HandlerOptionMode::Required,
                    description: 'Non-empty audit reason for this explicit legacy repair.',
                    default: '',
                ),
                new HandlerOption(
                    name: 'json',
                    mode: HandlerOptionMode::None,
                    description: 'Emit stable machine-readable per-type counts.',
                ),
            ],
            handler: [MutationAuthorityBackfillHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'install:init',
            description: 'Initialize a fresh installation: apply migrations, synchronize schema, and activate the initial configuration generation',
            handler: [InstallInitHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'migrate',
            description: 'Run pending database migrations (use --dry-run to preview, --verify to audit)',
            options: [
                new HandlerOption(
                    name: 'dry-run',
                    mode: HandlerOptionMode::None,
                    description: 'Preview pending migrations without applying any SQL or writing to the ledger.',
                ),
                new HandlerOption(
                    name: 'verify',
                    mode: HandlerOptionMode::None,
                    description: 'Compare ledger checksums against the live source. Read-only.',
                ),
                new HandlerOption(
                    name: 'json',
                    mode: HandlerOptionMode::None,
                    description: 'Emit machine-readable JSON instead of human-readable text.',
                ),
            ],
            handler: [MigrateHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'migrate:rollback',
            description: 'Roll back the last batch of migrations',
            handler: [MigrateRollbackHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'migrate:status',
            description: 'Show the status of each migration',
            handler: [MigrateStatusHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'migrate:defaults',
            description: 'Migrate default content type enablement for tenants',
            options: [
                new HandlerOption(
                    name: 'tenant',
                    mode: HandlerOptionMode::Array_,
                    description: 'Tenant IDs to migrate (repeatable)',
                ),
                new HandlerOption(
                    name: 'enable',
                    mode: HandlerOptionMode::Required,
                    description: 'Type ID to enable for all tenants (e.g. note)',
                    default: '',
                ),
                new HandlerOption(
                    name: 'actor',
                    mode: HandlerOptionMode::Required,
                    description: 'Actor ID for audit log entries',
                    default: 'cli',
                ),
                new HandlerOption(
                    name: 'yes',
                    shortcut: 'y',
                    mode: HandlerOptionMode::None,
                    description: 'Skip confirmation prompts',
                ),
                new HandlerOption(
                    name: 'dry-run',
                    mode: HandlerOptionMode::None,
                    description: 'Report actions without making changes',
                ),
                new HandlerOption(
                    name: 'rollback',
                    mode: HandlerOptionMode::None,
                    description: 'Rollback previous migrate:defaults actions',
                ),
            ],
            handler: [MigrateDefaultsHandler::class, 'execute'],
        );
    }
}
