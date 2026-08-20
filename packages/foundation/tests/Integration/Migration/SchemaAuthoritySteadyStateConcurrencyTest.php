<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Integration\Migration;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\SqliteDriverMiddleware;
use Waaseyaa\Database\SqliteTopology;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\EntitySchemaSync;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\SchemaMutationCoordinator;

/**
 * #2446. `SchemaAuthorityConcurrencyTest` covers only a *first install*, where
 * `CREATE TABLE IF NOT EXISTS waaseyaa_schema_authority` genuinely creates the
 * table and so takes the write lock as the transaction's first act. In steady
 * state that statement is a no-op, `ensureSchemaAuthorityManifestColumns()`'s
 * `PRAGMA table_info` becomes the first database access, and the authority
 * INSERT is a read-to-write snapshot upgrade — which SQLite refuses outright.
 *
 * These tests are deterministic: the interleaving is driven by a driver hook,
 * not by timing, and the competing worker uses `busy_timeout = 0` so it either
 * takes the write lock instantly or is refused instantly. Nothing sleeps.
 */
final class SchemaAuthoritySteadyStateConcurrencyTest extends TestCase
{
    private string $databasePath = '';

    protected function tearDown(): void
    {
        foreach ([$this->databasePath, $this->databasePath . '-wal', $this->databasePath . '-shm'] as $path) {
            if ($path !== '' && is_file($path)) {
                unlink($path);
            }
        }
    }

    #[Test]
    public function schema_authority_survives_a_competing_commit_in_steady_state(): void
    {
        $this->databasePath = $this->freshDatabasePath();

        // Steady state: a prior boot already installed the authority row.
        $seed = $this->productionConnection();
        $repository = new MigrationRepository($seed);
        new SchemaMutationCoordinator($seed, $repository)->execute(static fn(): null => null);
        $seed->executeStatement('CREATE TABLE IF NOT EXISTS concurrency_probe (id INTEGER PRIMARY KEY, n INTEGER)');
        $seed->executeStatement('INSERT OR IGNORE INTO concurrency_probe (id, n) VALUES (1, 0)');
        $seed->close();

        // Worker B commits exactly once, at the moment worker A first reads.
        $competingWrites = 0;
        $connection = $this->productionConnection(
            hook: 'PRAGMA table_info(waaseyaa_schema_authority)',
            onHook: function () use (&$competingWrites): void {
                if ($competingWrites > 0) {
                    return;
                }
                $competingWrites += $this->commitCompetingWriteOrFailFast() ? 1 : 0;
            },
        );

        $repository = new MigrationRepository($connection);
        new SchemaMutationCoordinator($connection, $repository)->execute(static fn(): null => null);

        self::assertSame(
            2,
            (int) $connection->fetchOne('SELECT generation FROM waaseyaa_schema_authority WHERE authority_id = 1'),
            'The steady-state boot must acquire schema authority exactly once.',
        );
        $connection->close();
    }

    #[Test]
    public function an_unchanged_synchronization_does_not_acquire_schema_authority(): void
    {
        $this->databasePath = $this->freshDatabasePath();
        $entityType = new EntityType(
            id: 'widget',
            label: 'Widget',
            class: TestStorageEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'bundle',
                'label' => 'label',
                'langcode' => 'langcode',
            ],
        );

        $seed = $this->productionConnection();
        new EntitySchemaSync(new DBALDatabase($seed))->syncAll([$entityType]);
        $seed->executeStatement('CREATE TABLE concurrency_probe (id INTEGER PRIMARY KEY, n INTEGER)');
        $seed->executeStatement('INSERT INTO concurrency_probe (id, n) VALUES (1, 0)');
        $generationBefore = (int) $seed->fetchOne(
            'SELECT generation FROM waaseyaa_schema_authority WHERE authority_id = 1',
        );
        self::assertSame(1, $generationBefore, 'The real schema change must acquire authority exactly once.');
        self::assertContains('widget', $seed->createSchemaManager()->listTableNames());
        $seed->close();

        $competingAttempts = 0;
        $competingWrites = 0;
        $steady = $this->productionConnection(
            hook: 'sqlite_master',
            onHook: function () use (&$competingAttempts, &$competingWrites): void {
                if ($competingAttempts > 0) {
                    return;
                }
                ++$competingAttempts;
                $competingWrites += $this->commitCompetingWriteOrFailFast() ? 1 : 0;
            },
        );
        new EntitySchemaSync(new DBALDatabase($steady))->syncAll([$entityType]);

        self::assertSame(1, $competingAttempts, 'The deterministic schema-inspection hook must run once.');
        self::assertSame(1, $competingWrites, 'An unchanged schema inspection must not hold the writer position.');
        self::assertSame(
            $generationBefore,
            (int) $steady->fetchOne(
                'SELECT generation FROM waaseyaa_schema_authority WHERE authority_id = 1',
            ),
            'An unchanged synchronization must not acquire or advance schema authority.',
        );
        $steady->close();
    }

    /**
     * #2452. An install can carry entity tables while carrying no authority or
     * ledger records — anything materialized before #2446 looks exactly like
     * that. Ordinary boot is a read path, so a synchronization that finds
     * nothing to change must leave that install alone rather than quietly
     * installing schema on its behalf; `db:init` and the next real mutation are
     * the authorized repair paths, and the second half of this test proves the
     * install still converges through one of them.
     */
    #[Test]
    public function an_unchanged_synchronization_leaves_an_install_without_authority_records_alone(): void
    {
        $this->databasePath = $this->freshDatabasePath();
        $widget = self::entityType('widget', 'Widget');

        $seed = $this->productionConnection();
        new EntitySchemaSync(new DBALDatabase($seed))->syncAll([$widget]);
        $seedSchema = $seed->createSchemaManager();
        $seedSchema->dropTable('waaseyaa_schema_authority');
        $seedSchema->dropTable('waaseyaa_migrations');
        self::assertSame(['widget'], $seed->createSchemaManager()->listTableNames());
        $seed->close();

        $boot = $this->productionConnection();
        new EntitySchemaSync(new DBALDatabase($boot))->syncAll([$widget]);

        self::assertNotContains(
            'waaseyaa_schema_authority',
            $boot->createSchemaManager()->listTableNames(),
            'a synchronization that found nothing to change must not install schema authority.',
        );

        new EntitySchemaSync(new DBALDatabase($boot))->syncAll([$widget, self::entityType('gadget', 'Gadget')]);

        $repaired = $boot->createSchemaManager()->listTableNames();
        self::assertContains('waaseyaa_schema_authority', $repaired, 'the next real mutation restores authority');
        self::assertContains('waaseyaa_migrations', $repaired, 'the next real mutation restores the ledger');
        $boot->close();
    }

    private static function entityType(string $id, string $label): EntityType
    {
        return new EntityType(
            id: $id,
            label: $label,
            class: TestStorageEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'bundle',
                'label' => 'label',
                'langcode' => 'langcode',
            ],
        );
    }

    private function freshDatabasePath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'waaseyaa-steady-state-');
        self::assertIsString($path);
        unlink($path);

        return $path;
    }

    /** Mirrors DBALDatabase::createSqlite(), optionally with a deterministic interleave hook. */
    private function productionConnection(string $hook = '', ?\Closure $onHook = null): \Doctrine\DBAL\Connection
    {
        $middlewares = [new SqliteDriverMiddleware(fileBacked: true)];
        if ($hook !== '' && $onHook !== null) {
            $middlewares[] = self::hookMiddleware($hook, $onHook);
        }
        $configuration = new Configuration();
        $configuration->setMiddlewares($middlewares);

        $connection = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'path' => $this->databasePath],
            $configuration,
        );
        SqliteTopology::configureAndVerify($connection, fileBacked: true);

        return $connection;
    }

    /**
     * A second worker attempting an immediate write with no lock patience.
     * Returns true when it committed, false when it was refused outright.
     */
    private function commitCompetingWriteOrFailFast(): bool
    {
        $competitor = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->databasePath]);
        $competitor->executeStatement('PRAGMA journal_mode = WAL');
        $competitor->executeStatement('PRAGMA busy_timeout = 0');
        try {
            $competitor->executeStatement('BEGIN IMMEDIATE');
            $competitor->executeStatement('UPDATE concurrency_probe SET n = n + 1 WHERE id = 1');
            $competitor->executeStatement('COMMIT');

            return true;
        } catch (\Throwable) {
            return false;
        } finally {
            $competitor->close();
        }
    }

    private static function hookMiddleware(string $sqlNeedle, \Closure $onHook): Middleware
    {
        return new class ($sqlNeedle, $onHook) implements Middleware {
            public function __construct(
                private readonly string $sqlNeedle,
                private readonly \Closure $onHook,
            ) {}

            public function wrap(Driver $driver): Driver
            {
                return new class ($driver, $this->sqlNeedle, $this->onHook) extends AbstractDriverMiddleware {
                    public function __construct(
                        Driver $driver,
                        private readonly string $sqlNeedle,
                        private readonly \Closure $onHook,
                    ) {
                        parent::__construct($driver);
                    }

                    public function connect(#[SensitiveParameter] array $params): DriverConnection
                    {
                        return new class (parent::connect($params), $this->sqlNeedle, $this->onHook) extends AbstractConnectionMiddleware {
                            public function __construct(
                                DriverConnection $connection,
                                private readonly string $sqlNeedle,
                                private readonly \Closure $onHook,
                            ) {
                                parent::__construct($connection);
                            }

                            public function query(string $sql): Result
                            {
                                $result = parent::query($sql);
                                if (str_contains($sql, $this->sqlNeedle)) {
                                    ($this->onHook)();
                                }

                                return $result;
                            }

                            public function prepare(string $sql): Statement
                            {
                                if (str_contains($sql, $this->sqlNeedle)) {
                                    ($this->onHook)();
                                }

                                return parent::prepare($sql);
                            }
                        };
                    }
                };
            }
        };
    }
}
