<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Migration\Executor;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\EntityTableMaterializerInterface;
use Waaseyaa\Foundation\Migration\Executor\ApplyMode;
use Waaseyaa\Foundation\Migration\Executor\IncompatibleSchemaStateException;
use Waaseyaa\Foundation\Migration\Executor\V2PlanExecutor;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\Migrator;
use Waaseyaa\Foundation\Schema\Compiler\Sqlite\SqliteCompiler;
use Waaseyaa\Foundation\Schema\Diff\AddColumn;
use Waaseyaa\Foundation\Schema\Diff\ColumnSpec;
use Waaseyaa\Foundation\Schema\Diff\CompositeDiff;
use Waaseyaa\Foundation\Schema\Migration\MigrationInterfaceV2;
use Waaseyaa\Foundation\Schema\Migration\MigrationPlan;

/**
 * Replay and interrupted-initialization behaviour for FW-2701.
 *
 * Targeted materialization joins execution and the ledger write inside one
 * per-node transaction, so an interruption must leave the node wholly applied or
 * wholly absent — including the table the materializer created.
 *
 * @see docs/change-records/FW-2701.md
 */
#[CoversClass(Migrator::class)]
final class V2ReplayAndInterruptionTest extends TestCase
{
    private Connection $connection;
    private MigrationRepository $repository;

    protected function setUp(): void
    {
        $this->connection = DBALDatabase::createSqlite()->getConnection();
        $this->repository = new MigrationRepository($this->connection);
        $this->repository->installOrUpgradeLedger();
    }

    #[Test]
    public function a_replayed_run_is_a_no_op_and_preserves_the_recorded_apply_mode(): void
    {
        $migration = self::migration([
            new AddColumn('account', 'user_id', new ColumnSpec(type: 'text', nullable: true)),
        ]);

        $first = $this->migrator()->run([], [$migration]);
        $second = $this->migrator()->run([], [$migration]);

        self::assertSame(1, $first->count);
        self::assertSame(0, $second->count, 'replay applies nothing');
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM waaseyaa_migrations WHERE migration = ?',
            [$migration->migrationId()],
        ), 'exactly one ledger row survives a replay');
        self::assertSame(ApplyMode::Applied->value, $this->repository->getApplyMode($migration->migrationId()));
    }

    #[Test]
    public function an_already_satisfied_node_records_its_mode_for_audit(): void
    {
        $this->connection->executeStatement('CREATE TABLE account (eid INTEGER PRIMARY KEY, user_id TEXT)');
        $migration = self::migration([
            new AddColumn('account', 'user_id', new ColumnSpec(type: 'text', nullable: true)),
        ]);

        $this->migrator()->run([], [$migration]);

        self::assertSame(
            ApplyMode::AlreadySatisfied->value,
            $this->repository->getApplyMode($migration->migrationId()),
        );
        self::assertNotNull(
            $this->repository->getStoredChecksum($migration->migrationId()),
            'an already-satisfied node still records its checksum, so verification works',
        );
    }

    #[Test]
    public function an_interrupted_node_rolls_back_the_materialized_table_and_the_ledger(): void
    {
        // The second operation targets a table nobody owns, so it fails closed on
        // real SQL — after `account` was materialized and its column added. That
        // is precisely the mid-node interruption the contract must survive.
        $migration = self::migration([
            new AddColumn('account', 'user_id', new ColumnSpec(type: 'text', nullable: true)),
            new AddColumn('ghost', 'orphan', new ColumnSpec(type: 'text', nullable: true)),
        ]);

        $threw = false;
        try {
            $this->migrator()->run([], [$migration]);
        } catch (\Throwable) {
            $threw = true;
        }
        self::assertTrue($threw, 'the interrupted node must not report success');

        self::assertSame(0, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'account'",
        ), 'the materialized table is rolled back with the node');
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM waaseyaa_migrations WHERE migration = ?',
            [$migration->migrationId()],
        ), 'no ledger row survives an interrupted node');
    }

    /**
     * A refusal is not merely an exception: it must leave the database exactly
     * as it was. The plan below succeeds at its first operation — adding a real
     * column — and only then meets the primary-key column it must refuse, so the
     * node's transaction has genuine work to undo.
     *
     * The Migrator, MigrationRepository, V2PlanExecutor, SqliteCompiler,
     * OpPreconditionResolver, ledger and per-node transaction are all the real
     * ones. The materializer is this file's anonymous test double and is not
     * exercised here at all — `account` already exists, so C1 has nothing to do
     * and the rollback being proven is of the executor's own statements.
     */
    #[Test]
    public function a_primary_key_refusal_rolls_back_the_earlier_operation_and_writes_no_ledger_row(): void
    {
        $this->connection->executeStatement('CREATE TABLE account (user_id INTEGER PRIMARY KEY, uuid TEXT)');
        $this->connection->executeStatement("INSERT INTO account (user_id, uuid) VALUES (7, 'seeded')");

        $migration = self::migration([
            // Outstanding, and applies successfully.
            new AddColumn('account', 'tenant_id', new ColumnSpec(type: 'text', nullable: true)),
            // The live column is the table's primary key, so this must refuse.
            new AddColumn('account', 'user_id', new ColumnSpec(type: 'int', nullable: true)),
        ]);

        try {
            $this->migrator()->run([], [$migration]);
            self::fail('a primary-key column must not satisfy an authored plain column');
        } catch (IncompatibleSchemaStateException $e) {
            self::assertStringContainsString('S1-DB110', $e->getMessage());
            self::assertStringContainsString('column 1 of the table primary key', $e->getMessage());
        }

        self::assertSame(
            ['user_id', 'uuid'],
            array_values(array_column(
                $this->connection->fetchAllAssociative('PRAGMA table_info("account")'),
                'name',
            )),
            'the earlier operation in the same plan is rolled back with the node',
        );
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM waaseyaa_migrations WHERE migration = ?',
            [$migration->migrationId()],
        ), 'a refused node leaves no ledger row, successful or otherwise');
        self::assertSame('seeded', $this->connection->fetchOne(
            'SELECT uuid FROM account WHERE user_id = 7',
        ), 'pre-existing data is untouched');
    }

    /**
     * The same proof for every other unauthorable shape, not only the primary
     * key. Each plan succeeds at its first operation and only then meets the
     * column it must refuse, so the node's transaction has genuine work to undo
     * — and the ledger, the earlier column and the pre-existing row must all
     * come back exactly as they were.
     */
    #[Test]
    #[DataProvider('unauthorableLiveColumns')]
    public function every_unauthorable_column_rolls_back_the_whole_node(
        array $ddl,
        string $expectedReason,
        ColumnSpec $spec,
    ): void {
        foreach ($ddl as $statement) {
            $this->connection->executeStatement($statement);
        }
        $this->connection->executeStatement("INSERT INTO account (uuid) VALUES ('seeded')");

        $migration = self::migration([
            // Outstanding, and applies successfully.
            new AddColumn('account', 'tenant_id', new ColumnSpec(type: 'text', nullable: true)),
            // The live column is unauthorable, so this must refuse.
            new AddColumn('account', 'user_id', $spec),
        ]);

        try {
            $this->migrator()->run([], [$migration]);
            self::fail('an unauthorable column must not satisfy an authored plain column');
        } catch (IncompatibleSchemaStateException $e) {
            self::assertStringContainsString('S1-DB110', $e->getMessage());
            self::assertStringContainsString($expectedReason, $e->getMessage());
        }

        self::assertNotContains(
            'tenant_id',
            array_column(
                $this->connection->fetchAllAssociative('PRAGMA table_xinfo("account")'),
                'name',
            ),
            'the earlier operation in the same plan is rolled back with the node',
        );
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM waaseyaa_migrations WHERE migration = ?',
            [$migration->migrationId()],
        ), 'a refused node leaves no ledger row, successful or otherwise');
        self::assertSame('seeded', $this->connection->fetchOne('SELECT uuid FROM account'));
        self::assertFalse($this->connection->isTransactionActive());
    }

    /**
     * Each authored spec matches the live column on type, nullability and
     * default, so the refusal can only come from the property under test.
     *
     * @return array<string, array{list<string>, string, ColumnSpec}>
     */
    public static function unauthorableLiveColumns(): array
    {
        $nullableInt = new ColumnSpec(type: 'int', nullable: true);

        return [
            'a UNIQUE constraint' => [
                ['CREATE TABLE account (uuid TEXT, user_id INTEGER UNIQUE)'],
                'found a member of a UNIQUE constraint',
                $nullableInt,
            ],
            'a foreign key source' => [
                [
                    'CREATE TABLE parent (id INTEGER PRIMARY KEY)',
                    'CREATE TABLE account (uuid TEXT, user_id INTEGER REFERENCES parent (id))',
                ],
                'found the source of a foreign key',
                $nullableInt,
            ],
            'a generated column' => [
                ['CREATE TABLE account (uuid TEXT, user_id INTEGER AS (length(uuid)))'],
                'found a VIRTUAL generated column',
                $nullableInt,
            ],
            'a CHECK that can read the column' => [
                ['CREATE TABLE account (uuid TEXT, user_id INTEGER, CHECK (user_id > 0))'],
                'a CHECK constraint can read the column',
                $nullableInt,
            ],
            'a CHECK reaching it through a generated column' => [
                [
                    'CREATE TABLE account (uuid TEXT, user_id INTEGER, '
                    . 'derived INTEGER AS (user_id + 1), CHECK (derived > 0))',
                ],
                'a CHECK constraint can read the column',
                $nullableInt,
            ],
            'a non-BINARY collation' => [
                ['CREATE TABLE account (uuid TEXT, user_id TEXT COLLATE NOCASE)'],
                'the column declares COLLATE NOCASE',
                new ColumnSpec(type: 'text', nullable: true),
            ],
            'a NOT NULL conflict policy' => [
                ['CREATE TABLE account (uuid TEXT, user_id INTEGER NOT NULL ON CONFLICT IGNORE DEFAULT 0)'],
                "not the compiler's ABORT",
                new ColumnSpec(type: 'int', nullable: false, default: 0),
            ],
        ];
    }

    #[Test]
    public function a_resumed_run_after_an_interruption_applies_cleanly(): void
    {
        $broken = self::migration([
            new AddColumn('account', 'user_id', new ColumnSpec(type: 'text', nullable: true)),
            new AddColumn('ghost', 'orphan', new ColumnSpec(type: 'text', nullable: true)),
        ]);
        try {
            $this->migrator()->run([], [$broken]);
        } catch (\Throwable) {
            // expected: the operator fixes the catalogue and re-runs
        }

        $fixed = self::migration([
            new AddColumn('account', 'user_id', new ColumnSpec(type: 'text', nullable: true)),
        ]);
        $result = $this->migrator()->run([], [$fixed]);

        self::assertSame(1, $result->count);
        self::assertContains('user_id', array_column(
            $this->connection->fetchAllAssociative('PRAGMA table_info("account")'),
            'name',
        ));
    }

    private function migrator(): Migrator
    {
        $compiler = SqliteCompiler::forVersion((string) $this->connection->fetchOne('SELECT sqlite_version()'));

        return new Migrator(
            $this->connection,
            $this->repository,
            new V2PlanExecutor($this->connection, $compiler, $this->materializer()),
            isProduction: false,
        );
    }

    private function materializer(): EntityTableMaterializerInterface
    {
        return new class ($this->connection) implements EntityTableMaterializerInterface {
            public function __construct(private Connection $connection) {}

            public function materialize(array $tables): array
            {
                $created = [];
                foreach ($tables as $table) {
                    if ($table !== 'account') {
                        continue;
                    }
                    $exists = (int) $this->connection->fetchOne(
                        "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?",
                        [$table],
                    ) === 1;
                    if ($exists) {
                        continue;
                    }
                    $this->connection->executeStatement(
                        'CREATE TABLE "account" (eid INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT, _data TEXT)',
                    );
                    $created[] = $table;
                }

                return $created;
            }
        };
    }

    /** @param list<\Waaseyaa\Foundation\Schema\Diff\SchemaDiffOp> $ops */
    private static function migration(array $ops): MigrationInterfaceV2
    {
        return new class ($ops) implements MigrationInterfaceV2 {
            /** @param list<\Waaseyaa\Foundation\Schema\Diff\SchemaDiffOp> $ops */
            public function __construct(private array $ops) {}

            public function migrationId(): string
            {
                return 'acme/application:v2:account-evolution';
            }

            public function package(): string
            {
                return 'acme/application';
            }

            public function dependencies(): array
            {
                return [];
            }

            public function plan(): MigrationPlan
            {
                return new MigrationPlan(
                    migrationId: $this->migrationId(),
                    package: $this->package(),
                    dependencies: [],
                    root: new CompositeDiff($this->ops),
                );
            }
        };
    }
}
