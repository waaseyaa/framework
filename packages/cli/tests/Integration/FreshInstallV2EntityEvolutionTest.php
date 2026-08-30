<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Integration;

use Composer\Autoload\ClassLoader;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\MigrateHandler;
use Waaseyaa\CLI\Provider\MigrateServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\CLI\Tests\Fixtures\EntityEvolutionV2MigrationAutoloader;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * waaseyaa/framework#2701 — fresh-install semantics for V2 entity evolution.
 *
 * One immutable V2 source catalogue must upgrade an existing site AND initialize
 * a fresh one, converging on the same physical schema and the same ledger
 * evidence. Today the fresh lifecycle diverges, because stock migrations apply
 * before entity schema synchronization creates the entity base table.
 *
 * The `sql-blob` column list asserted below is the observed output of
 * `EntitySchemaSyncRunner::run()` for a registered entity type on the default
 * backend: entity-key columns plus `_data`, and NO per-field columns. That is
 * why `user_id` can only come from this migration, in both lifecycles.
 */
#[CoversNothing]
final class FreshInstallV2EntityEvolutionTest extends TestCase
{
    /** Exactly what entity schema sync materializes for a default-backend type. */
    private const SYNC_CREATED_ACCOUNT_TABLE =
        'CREATE TABLE account (eid INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT, bundle TEXT, label TEXT, langcode TEXT, _data TEXT)';

    private string $root;
    private ?ClassLoader $migrationClassLoader = null;

    protected function setUp(): void
    {
        $this->migrationClassLoader = EntityEvolutionV2MigrationAutoloader::register();

        $this->root = sys_get_temp_dir() . '/waaseyaa_2701_' . uniqid();
        mkdir($this->root . '/vendor/composer', 0o755, true);
        file_put_contents($this->root . '/vendor/composer/installed.json', '{"packages":[]}');
        file_put_contents(
            $this->root . '/composer.json',
            json_encode([
                'name' => 'acme/application',
                'extra' => ['waaseyaa' => [
                    'migrations' => ['Waaseyaa\\CLI\\Tests\\Fixtures'],
                ]],
            ], JSON_THROW_ON_ERROR),
        );
    }

    protected function tearDown(): void
    {
        $this->migrationClassLoader?->unregister();
        try {
            new Filesystem()->remove($this->root);
        } catch (IOException) {
            // The provider retains its SQLite connection for the process lifetime.
        }
    }

    #[Test]
    public function an_existing_site_applies_the_entity_evolution(): void
    {
        $databasePath = $this->root . '/application.sqlite';
        $seed = self::connect($databasePath);
        $seed->executeStatement(self::SYNC_CREATED_ACCOUNT_TABLE);
        $seed->close();

        $exit = $this->runStockMigrate($databasePath);

        self::assertSame(0, $exit, 'stock migrate must succeed on an existing site');
        $read = self::connect($databasePath);
        self::assertContains('user_id', self::columnNames($read, 'account'));
        self::assertSame(
            ['acme/application:v2:add-account-user-id'],
            self::ledgerIds($read),
            'the evolution is recorded under its stable ledger id',
        );
    }

    #[Test]
    public function a_fresh_site_converges_on_the_same_schema_and_ledger(): void
    {
        $databasePath = $this->root . '/application.sqlite';

        // Fresh install: the entity base table does not exist yet when stock
        // migrations apply. Entity schema synchronization runs afterwards.
        $exit = $this->runStockMigrate($databasePath);
        self::assertSame(0, $exit, 'stock migrate must succeed on a fresh install');

        $read = self::connect($databasePath);
        if (!self::tableExists($read, 'account')) {
            $read->executeStatement(self::SYNC_CREATED_ACCOUNT_TABLE);
        }

        self::assertContains(
            'user_id',
            self::columnNames($read, 'account'),
            'a fresh site must converge on the same physical schema as an upgraded site',
        );
        self::assertSame(
            ['acme/application:v2:add-account-user-id'],
            self::ledgerIds($read),
            'a fresh site must converge on the same ledger evidence as an upgraded site',
        );
    }

    private function runStockMigrate(string $databasePath): int
    {
        $provider = new MigrateServiceProvider();
        $provider->setKernelContext($this->root, [
            'environment' => 'testing',
            'database' => $databasePath,
        ], []);
        $provider->setKernelServices(self::servicesWithAccountEntityType());
        $provider->register();
        $handler = $provider->resolve(MigrateHandler::class);
        self::assertInstanceOf(MigrateHandler::class, $handler);

        $tester = CliTester::for(
            new HandlerCommand(
                name: 'migrate',
                description: 'Migrate',
                handler: \Closure::fromCallable([$handler, 'execute']),
            ),
            new class implements ContainerInterface {
                public function get(string $id): mixed
                {
                    throw new \RuntimeException('Not found: ' . $id);
                }

                public function has(string $id): bool
                {
                    return false;
                }
            },
        );
        $tester->execute([]);

        return $tester->getExitCode();
    }

    /**
     * `account` registered as a real entity type, on the framework-default
     * backend, so the canonical materializer owns its base table.
     */
    private static function servicesWithAccountEntityType(): KernelServicesInterface
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        $manager->registerEntityType(new EntityType(
            id: 'account',
            label: 'Account',
            class: \stdClass::class,
            keys: ['id' => 'eid', 'uuid' => 'uuid'],
        ));

        return new class ($manager) implements KernelServicesInterface {
            public function __construct(private EntityTypeManager $manager) {}

            public function get(string $id): ?object
            {
                return $id === EntityTypeManager::class ? $this->manager : null;
            }
        };
    }

    private static function connect(string $path): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
    }

    private static function tableExists(Connection $connection, string $table): bool
    {
        return (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$table],
        ) === 1;
    }

    /** @return list<string> */
    private static function columnNames(Connection $connection, string $table): array
    {
        return array_values(array_column(
            $connection->executeQuery(sprintf('PRAGMA table_info(%s)', $table))->fetchAllAssociative(),
            'name',
        ));
    }

    /** @return list<string> */
    private static function ledgerIds(Connection $connection): array
    {
        return array_values(array_column(
            $connection->fetchAllAssociative('SELECT migration FROM waaseyaa_migrations ORDER BY migration'),
            'migration',
        ));
    }
}
