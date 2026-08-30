<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Migration\Executor;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\EntityTableMaterializerInterface;
use Waaseyaa\Foundation\Migration\Executor\ApplyMode;
use Waaseyaa\Foundation\Migration\Executor\IncompatibleSchemaStateException;
use Waaseyaa\Foundation\Migration\Executor\V2PlanExecutor;
use Waaseyaa\Foundation\Schema\Compiler\Sqlite\SqliteCompiler;
use Waaseyaa\Foundation\Schema\Compiler\Validation\PlanPolicy;
use Waaseyaa\Foundation\Schema\Diff\AddColumn;
use Waaseyaa\Foundation\Schema\Diff\AddIndex;
use Waaseyaa\Foundation\Schema\Diff\ColumnSpec;
use Waaseyaa\Foundation\Schema\Diff\CompositeDiff;
use Waaseyaa\Foundation\Schema\Migration\MigrationPlan;

/**
 * The FW-2701 lifecycle contract, exercised through the real executor against a
 * real SQLite connection.
 *
 * Covers every state the change record's behaviour matrix names: absent owned
 * table, absent unowned table, present table missing the column, present column
 * exactly satisfying the operation, incompatible column, and a plan mixing
 * satisfied and outstanding operations.
 *
 * @see docs/change-records/FW-2701.md
 */
#[CoversClass(V2PlanExecutor::class)]
final class V2LifecycleContractTest extends TestCase
{
    private DBALDatabase $database;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->connection = $this->database->getConnection();
    }

    #[Test]
    public function an_absent_owned_table_is_materialized_then_the_evolution_applies(): void
    {
        $outcome = $this->execute($this->addUserId(), $this->materializerOwning(['account']));

        self::assertSame(ApplyMode::Applied, $outcome->mode);
        self::assertSame(['account'], $outcome->materializedTables);
        self::assertContains('user_id', $this->columns('account'));
    }

    #[Test]
    public function an_absent_unowned_table_fails_closed_on_real_sql(): void
    {
        $this->expectExceptionMessageMatches('/no such table: account/');

        $this->execute($this->addUserId(), $this->materializerOwning([]));
    }

    #[Test]
    public function a_present_table_missing_the_column_applies_the_evolution(): void
    {
        $this->connection->executeStatement('CREATE TABLE account (eid INTEGER PRIMARY KEY)');

        $outcome = $this->execute($this->addUserId(), $this->materializerOwning(['account']));

        self::assertSame(ApplyMode::Applied, $outcome->mode);
        self::assertSame([], $outcome->materializedTables, 'an existing table is never touched by C1');
        self::assertContains('user_id', $this->columns('account'));
    }

    #[Test]
    public function an_exactly_satisfying_column_yields_already_satisfied_and_issues_no_sql(): void
    {
        $this->connection->executeStatement('CREATE TABLE account (eid INTEGER PRIMARY KEY, user_id TEXT)');

        $outcome = $this->execute($this->addUserId(), $this->materializerOwning(['account']));

        self::assertSame(ApplyMode::AlreadySatisfied, $outcome->mode);
        self::assertSame(['eid', 'user_id'], $this->columns('account'), 'schema is untouched');
    }

    #[Test]
    public function an_incompatible_column_type_fails_closed(): void
    {
        $this->connection->executeStatement('CREATE TABLE account (eid INTEGER PRIMARY KEY, user_id INTEGER)');

        $this->expectException(IncompatibleSchemaStateException::class);
        $this->expectExceptionMessageMatches('/S1-DB110.*declared type TEXT, found INTEGER/s');

        $this->execute($this->addUserId(), $this->materializerOwning(['account']));
    }

    #[Test]
    public function an_incompatible_nullability_fails_closed(): void
    {
        $this->connection->executeStatement('CREATE TABLE account (eid INTEGER PRIMARY KEY, user_id TEXT NOT NULL)');

        $this->expectException(IncompatibleSchemaStateException::class);
        $this->expectExceptionMessageMatches('/S1-DB110.*declared NULL, found NOT NULL/s');

        $this->execute($this->addUserId(), $this->materializerOwning(['account']));
    }

    #[Test]
    public function a_satisfied_index_is_not_recreated(): void
    {
        $this->connection->executeStatement('CREATE TABLE account (eid INTEGER PRIMARY KEY, user_id TEXT)');
        $this->connection->executeStatement('CREATE INDEX account_user_id ON account ("user_id")');

        $plan = $this->plan(new CompositeDiff([new AddIndex('account', ['user_id'], 'account_user_id')]));
        $outcome = $this->execute($plan, $this->materializerOwning(['account']));

        self::assertSame(ApplyMode::AlreadySatisfied, $outcome->mode);
    }

    #[Test]
    public function a_mixed_plan_executes_only_the_outstanding_operation(): void
    {
        $this->connection->executeStatement('CREATE TABLE account (eid INTEGER PRIMARY KEY, user_id TEXT)');

        $plan = $this->plan(new CompositeDiff([
            new AddColumn('account', 'user_id', new ColumnSpec(type: 'text', nullable: true)),
            new AddColumn('account', 'tenant_id', new ColumnSpec(type: 'text', nullable: true)),
        ]));
        $outcome = $this->execute($plan, $this->materializerOwning(['account']));

        self::assertSame(ApplyMode::Applied, $outcome->mode);
        self::assertContains('tenant_id', $this->columns('account'));
    }

    #[Test]
    public function diff_hash_is_identical_whether_the_node_executed_or_was_already_satisfied(): void
    {
        $freshDatabase = DBALDatabase::createSqlite();
        $freshConnection = $freshDatabase->getConnection();
        $freshConnection->executeStatement('CREATE TABLE account (eid INTEGER PRIMARY KEY)');
        $applied = new V2PlanExecutor($freshConnection, self::compilerFor($freshConnection), $this->materializerOwning([]))
            ->execute($this->addUserId(), new PlanPolicy());

        $this->connection->executeStatement('CREATE TABLE account (eid INTEGER PRIMARY KEY, user_id TEXT)');
        $satisfied = $this->execute($this->addUserId(), $this->materializerOwning(['account']));

        self::assertSame(ApplyMode::Applied, $applied->mode);
        self::assertSame(ApplyMode::AlreadySatisfied, $satisfied->mode);
        self::assertSame(
            $applied->diffHash(),
            $satisfied->diffHash(),
            'diff_hash is a function of the authored plan, not of what executed',
        );
    }

    private function execute(MigrationPlan $plan, EntityTableMaterializerInterface $materializer): \Waaseyaa\Foundation\Migration\Executor\V2ApplyOutcome
    {
        return new V2PlanExecutor($this->connection, self::compilerFor($this->connection), $materializer)
            ->execute($plan, new PlanPolicy());
    }

    private function addUserId(): MigrationPlan
    {
        return $this->plan(new CompositeDiff([
            new AddColumn('account', 'user_id', new ColumnSpec(type: 'text', nullable: true)),
        ]));
    }

    private function plan(CompositeDiff $root): MigrationPlan
    {
        return new MigrationPlan(
            migrationId: 'acme/application:v2:lifecycle',
            package: 'acme/application',
            dependencies: [],
            root: $root,
        );
    }

    /**
     * A materializer that owns exactly the named tables and creates them the way
     * the default `sql-blob` backend does: entity-key columns plus `_data`, and
     * no per-field columns.
     *
     * @param list<string> $owned
     */
    private function materializerOwning(array $owned): EntityTableMaterializerInterface
    {
        return new class ($this->connection, $owned) implements EntityTableMaterializerInterface {
            /** @param list<string> $owned */
            public function __construct(private Connection $connection, private array $owned) {}

            public function materialize(array $tables): array
            {
                $created = [];
                foreach ($tables as $table) {
                    if (!in_array($table, $this->owned, true)) {
                        continue;
                    }
                    $exists = (int) $this->connection->fetchOne(
                        "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?",
                        [$table],
                    ) === 1;
                    if ($exists) {
                        continue;
                    }
                    $this->connection->executeStatement(sprintf(
                        'CREATE TABLE "%s" (eid INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT, bundle TEXT, label TEXT, langcode TEXT, _data TEXT)',
                        $table,
                    ));
                    $created[] = $table;
                }

                return $created;
            }
        };
    }

    private static function compilerFor(Connection $connection): SqliteCompiler
    {
        return SqliteCompiler::forVersion((string) $connection->fetchOne('SELECT sqlite_version()'));
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        return array_values(array_column(
            $this->connection->fetchAllAssociative(sprintf('PRAGMA table_info("%s")', $table)),
            'name',
        ));
    }
}
