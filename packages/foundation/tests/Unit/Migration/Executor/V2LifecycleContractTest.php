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
use Waaseyaa\Foundation\Migration\Executor\OpPreconditionResolver;
use Waaseyaa\Foundation\Migration\Executor\V2ApplyOutcome;
use Waaseyaa\Foundation\Migration\Executor\V2PlanExecutor;
use Waaseyaa\Foundation\Schema\Diff\PlanTargets;
use Waaseyaa\Foundation\Schema\Compiler\Sqlite\SqliteCompiler;
use Waaseyaa\Foundation\Schema\Compiler\Sqlite\Translator\SqliteIdentifier;
use Waaseyaa\Foundation\Schema\Compiler\Validation\DestructiveOpBlockedException;
use Waaseyaa\Foundation\Schema\Compiler\Validation\PlanPolicy;
use Waaseyaa\Foundation\Schema\Diff\AddColumn;
use Waaseyaa\Foundation\Schema\Diff\AddIndex;
use Waaseyaa\Foundation\Schema\Diff\ColumnSpec;
use Waaseyaa\Foundation\Schema\Diff\CompositeDiff;
use Waaseyaa\Foundation\Schema\Diff\DropColumn;
use Waaseyaa\Foundation\Schema\Diff\RenameColumn;
use Waaseyaa\Foundation\Schema\Diff\RenameTable;
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
#[CoversClass(OpPreconditionResolver::class)]
#[CoversClass(IncompatibleSchemaStateException::class)]
#[CoversClass(V2ApplyOutcome::class)]
#[CoversClass(ApplyMode::class)]
#[CoversClass(PlanTargets::class)]
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
        $this->expectExceptionMessageMatches('/S1-DB110.*declared TEXT \(TEXT affinity\), found INTEGER \(INTEGER affinity\)/s');

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

    /**
     * A primary key is not expressible in `ColumnSpec`, and the compiler renders
     * only `<type> [NOT NULL] [DEFAULT <literal>]`, so a live primary-key column
     * is not the column an authored `AddColumn` declares and can never exactly
     * satisfy it.
     *
     * The rowid-alias case is the dangerous one: `INTEGER PRIMARY KEY` reports
     * `notnull = 0` while being incapable of holding NULL, so the nullability
     * comparison reads a value that misdescribes the column and no other check
     * can catch it.
     */
    #[Test]
    #[DataProvider('primaryKeyDeclarations')]
    public function a_primary_key_column_never_satisfies_an_authored_plain_column(
        string $ddl,
        bool $nullable,
        int $position,
    ): void {
        $this->connection->executeStatement($ddl);

        $this->expectException(IncompatibleSchemaStateException::class);
        $this->expectExceptionMessageMatches(
            sprintf('/S1-DB110.*column %d of the table primary key/s', $position),
        );

        $this->execute(
            $this->plan(new CompositeDiff([
                new AddColumn('account', 'user_id', new ColumnSpec(type: 'int', nullable: $nullable)),
            ])),
            $this->materializerOwning([]),
        );
    }

    /** @return array<string, array{string, bool, int}> */
    public static function primaryKeyDeclarations(): array
    {
        return [
            // The reported case: a rowid alias, which reports notnull = 0.
            'inline integer rowid primary key' => [
                'CREATE TABLE account (user_id INTEGER PRIMARY KEY)',
                true,
                1,
            ],
            // Same column, declared as a table constraint rather than inline.
            'table-level single-column primary key' => [
                'CREATE TABLE account (user_id INTEGER, PRIMARY KEY (user_id))',
                true,
                1,
            ],
            'composite primary key, first position' => [
                'CREATE TABLE account (user_id INTEGER, tenant TEXT, PRIMARY KEY (user_id, tenant))',
                true,
                1,
            ],
            // `pk` is a 1-based position, not a flag. Membership is `pk !== 0`,
            // true at every position; testing `pk === 1` is what would silently
            // miss a column sitting later in the key.
            'composite primary key, non-first position' => [
                'CREATE TABLE account (tenant TEXT, user_id INTEGER, PRIMARY KEY (tenant, user_id))',
                true,
                2,
            ],
            // WITHOUT ROWID reports notnull = 1, so an authored NOT NULL column
            // matches on type, nullability and default alike. Only the
            // primary-key reading can refuse this one.
            'WITHOUT ROWID primary key matching on every other property' => [
                'CREATE TABLE account (user_id INTEGER NOT NULL PRIMARY KEY) WITHOUT ROWID',
                false,
                1,
            ],
        ];
    }

    /**
     * The positive control for the rule above. `pk = 0` columns are unaffected,
     * including in a table whose *other* column is a primary key — the reading
     * is per-column, not per-table.
     */
    #[Test]
    #[DataProvider('ordinaryColumnDeclarations')]
    public function an_ordinary_non_primary_key_column_is_still_satisfied(string $ddl, bool $nullable): void
    {
        $this->connection->executeStatement($ddl);

        $outcome = $this->execute(
            $this->plan(new CompositeDiff([
                new AddColumn('account', 'user_id', new ColumnSpec(type: 'int', nullable: $nullable)),
            ])),
            $this->materializerOwning([]),
        );

        self::assertSame(ApplyMode::AlreadySatisfied, $outcome->mode);
    }

    /** @return array<string, array{string, bool}> */
    public static function ordinaryColumnDeclarations(): array
    {
        return [
            'beside a primary-key column' => [
                'CREATE TABLE account (eid INTEGER PRIMARY KEY, user_id INTEGER)',
                true,
            ],
            'in a table with no primary key at all' => [
                'CREATE TABLE account (user_id INTEGER)',
                true,
            ],
            'NOT NULL but not a primary key' => [
                'CREATE TABLE account (eid INTEGER PRIMARY KEY, user_id INTEGER NOT NULL)',
                false,
            ],
        ];
    }

    #[Test]
    public function a_doctrine_spelling_of_the_same_column_is_satisfied_not_refused(): void
    {
        // The canonical materializer emits Doctrine's vocabulary. TEXT and CLOB
        // are the same SQLite column; refusing this would abort every
        // sql-column fresh install.
        $this->connection->executeStatement('CREATE TABLE account (eid INTEGER PRIMARY KEY, user_id CLOB DEFAULT NULL)');

        $outcome = $this->execute($this->addUserId(), $this->materializerOwning(['account']));

        self::assertSame(ApplyMode::AlreadySatisfied, $outcome->mode);
    }

    #[Test]
    public function a_differing_default_value_fails_closed(): void
    {
        $this->connection->executeStatement("CREATE TABLE account (eid INTEGER PRIMARY KEY, tier TEXT DEFAULT 'gold')");

        $plan = $this->plan(new CompositeDiff([
            new AddColumn('account', 'tier', new ColumnSpec(type: 'text', nullable: true, default: 'silver')),
        ]));

        $this->expectException(IncompatibleSchemaStateException::class);
        $this->expectExceptionMessageMatches("/declared default 'silver', found 'gold'/");

        $this->execute($plan, $this->materializerOwning(['account']));
    }

    #[Test]
    public function an_index_with_the_same_columns_but_a_different_name_does_not_satisfy(): void
    {
        $this->connection->executeStatement('CREATE TABLE account (eid INTEGER PRIMARY KEY, user_id TEXT)');
        $this->connection->executeStatement('CREATE INDEX unrelated_index ON account ("user_id")');

        $plan = $this->plan(new CompositeDiff([new AddIndex('account', ['user_id'], 'account_user_id')]));
        $outcome = $this->execute($plan, $this->materializerOwning(['account']));

        self::assertSame(ApplyMode::Applied, $outcome->mode, 'the authored index must still be created');
        $names = array_column(
            $this->connection->fetchAllAssociative('PRAGMA index_list("account")'),
            'name',
        );
        self::assertContains('account_user_id', $names);
    }

    #[Test]
    public function an_operation_made_necessary_by_an_earlier_operation_is_not_dropped(): void
    {
        // Classifying the whole plan against one pre-execution snapshot would
        // judge the AddColumn against a table that still has the OLD column
        // name, and drop it as already satisfied.
        $this->connection->executeStatement('CREATE TABLE account (eid INTEGER PRIMARY KEY, legacy_id TEXT)');

        $plan = $this->plan(new CompositeDiff([
            new RenameColumn('account', 'legacy_id', 'archived_id'),
            new AddColumn('account', 'legacy_id', new ColumnSpec(type: 'text', nullable: true)),
        ]));
        $outcome = $this->execute($plan, $this->materializerOwning(['account']));

        self::assertSame(ApplyMode::Applied, $outcome->mode);
        self::assertSame(['eid', 'archived_id', 'legacy_id'], $this->columns('account'));
    }

    #[Test]
    public function a_rename_destination_is_never_materialized(): void
    {
        $this->connection->executeStatement('CREATE TABLE old_account (eid INTEGER PRIMARY KEY)');

        $plan = $this->plan(new CompositeDiff([new RenameTable('old_account', 'account')]));
        $outcome = $this->execute($plan, $this->materializerOwning(['account']));

        self::assertSame(ApplyMode::Applied, $outcome->mode);
        self::assertSame([], $outcome->materializedTables, 'pre-creating the destination would break the rename');
        self::assertTrue($this->tableExists('account'));
        self::assertFalse($this->tableExists('old_account'));
    }

    /**
     * R1: expected and actual defaults must be compared as SQL literals. The
     * earlier code compared an unquoted PHP string against SQLite's literal
     * text, which refused values the compiler itself produces.
     */
    #[Test]
    #[DataProvider('roundTripDefaults')]
    public function a_default_this_compiler_itself_would_emit_is_satisfied(string $authored): void
    {
        $literal = SqliteIdentifier::literal($authored);
        $this->connection->executeStatement(
            sprintf('CREATE TABLE account (eid INTEGER PRIMARY KEY, v TEXT DEFAULT %s)', $literal),
        );

        $plan = $this->plan(new CompositeDiff([
            new AddColumn('account', 'v', new ColumnSpec(type: 'text', nullable: true, default: $authored)),
        ]));

        self::assertSame(ApplyMode::AlreadySatisfied, $this->execute($plan, $this->materializerOwning([]))->mode);
    }

    /** @return list<array{string}> */
    public static function roundTripDefaults(): array
    {
        return [['ordinary'], [''], ['NULL'], ['  padded  '], ["it's"], ['0']];
    }

    /**
     * R1, the dangerous direction: an authored default against a column that
     * has none must refuse. The earlier normalization collapsed both to null.
     */
    #[Test]
    #[DataProvider('roundTripDefaults')]
    public function an_authored_default_is_never_satisfied_by_a_column_without_one(string $authored): void
    {
        $this->connection->executeStatement('CREATE TABLE account (eid INTEGER PRIMARY KEY, v TEXT)');

        $plan = $this->plan(new CompositeDiff([
            new AddColumn('account', 'v', new ColumnSpec(type: 'text', nullable: true, default: $authored)),
        ]));

        $this->expectException(IncompatibleSchemaStateException::class);
        $this->expectExceptionMessageMatches('/found none/');

        $this->execute($plan, $this->materializerOwning([]));
    }

    /** R4: an index whose collation differs from the column's is not equivalent. */
    #[Test]
    public function an_index_under_a_different_collation_fails_closed(): void
    {
        $this->connection->executeStatement('CREATE TABLE account (eid INTEGER PRIMARY KEY, name TEXT COLLATE BINARY)');
        $this->connection->executeStatement('CREATE UNIQUE INDEX idx_names ON account (name COLLATE NOCASE)');

        $this->expectException(IncompatibleSchemaStateException::class);
        $this->expectExceptionMessageMatches('/inherited from the column.*found NOCASE/');

        $this->execute(
            $this->plan(new CompositeDiff([new AddIndex('account', ['name'], 'idx_names', true)])),
            $this->materializerOwning([]),
        );
    }

    /** R4, the other direction: a column declaring NOCASE expects a NOCASE index. */
    #[Test]
    public function an_index_inheriting_a_declared_column_collation_is_satisfied(): void
    {
        $this->connection->executeStatement('CREATE TABLE account (eid INTEGER PRIMARY KEY, name TEXT COLLATE NOCASE)');
        $this->connection->executeStatement('CREATE UNIQUE INDEX idx_names ON account (name)');

        $outcome = $this->execute(
            $this->plan(new CompositeDiff([new AddIndex('account', ['name'], 'idx_names', true)])),
            $this->materializerOwning([]),
        );

        self::assertSame(ApplyMode::AlreadySatisfied, $outcome->mode);
    }

    /**
     * R3: a table an earlier operation produces is not a prerequisite of a
     * later one, so the materializer must not pre-create the rename target.
     */
    #[Test]
    public function a_rename_followed_by_an_alter_of_the_destination_still_applies(): void
    {
        $this->connection->executeStatement('CREATE TABLE old_account (eid INTEGER PRIMARY KEY)');

        $plan = $this->plan(new CompositeDiff([
            new RenameTable('old_account', 'account'),
            new AddColumn('account', 'user_id', new ColumnSpec(type: 'text', nullable: true)),
        ]));
        $outcome = $this->execute($plan, $this->materializerOwning(['account']));

        self::assertSame(ApplyMode::Applied, $outcome->mode);
        self::assertSame([], $outcome->materializedTables);
        self::assertContains('user_id', $this->columns('account'));
        self::assertFalse($this->tableExists('old_account'));
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
    public function a_partial_index_does_not_satisfy_a_full_one(): void
    {
        $this->connection->executeStatement('CREATE TABLE account (eid INTEGER PRIMARY KEY, user_id TEXT)');
        $this->connection->executeStatement('CREATE INDEX account_user_id ON account ("user_id") WHERE "user_id" IS NOT NULL');

        $this->expectException(IncompatibleSchemaStateException::class);
        $this->expectExceptionMessageMatches('/found a partial one/');

        $this->execute(
            $this->plan(new CompositeDiff([new AddIndex('account', ['user_id'], 'account_user_id')])),
            $this->materializerOwning(['account']),
        );
    }

    #[Test]
    public function a_descending_index_does_not_satisfy_an_ascending_one(): void
    {
        $this->connection->executeStatement('CREATE TABLE account (eid INTEGER PRIMARY KEY, user_id TEXT)');
        $this->connection->executeStatement('CREATE INDEX account_user_id ON account ("user_id" DESC)');

        $this->expectException(IncompatibleSchemaStateException::class);
        $this->expectExceptionMessageMatches('/descending/');

        $this->execute(
            $this->plan(new CompositeDiff([new AddIndex('account', ['user_id'], 'account_user_id')])),
            $this->materializerOwning(['account']),
        );
    }

    #[Test]
    public function a_policy_refusal_fires_before_the_materializer_is_invoked(): void
    {
        // Step 1 of the documented sequence is load-bearing: the full compile,
        // and therefore every policy refusal, must happen before anything
        // touches the database.
        $recording = new class implements EntityTableMaterializerInterface {
            public bool $invoked = false;

            public function materialize(array $tables): array
            {
                $this->invoked = true;

                return [];
            }
        };

        $plan = $this->plan(new CompositeDiff([new DropColumn('account', 'user_id')]));

        try {
            $this->execute($plan, $recording);
            self::fail('a destructive op must be refused under the default policy');
        } catch (DestructiveOpBlockedException) {
            // expected
        }

        self::assertFalse($recording->invoked, 'the materializer must not run before the policy gate');
    }

    #[Test]
    public function an_index_on_an_absent_table_is_outstanding_not_satisfied(): void
    {
        $outcome = $this->execute(
            $this->plan(new CompositeDiff([new AddIndex('account', ['user_id'], 'account_user_id')])),
            $this->materializerOwning(['account']),
        );

        self::assertSame(ApplyMode::Applied, $outcome->mode);
    }

    #[Test]
    public function an_index_with_the_authored_name_but_different_uniqueness_fails_closed(): void
    {
        $this->connection->executeStatement('CREATE TABLE account (eid INTEGER PRIMARY KEY, user_id TEXT)');
        $this->connection->executeStatement('CREATE INDEX account_user_id ON account ("user_id")');

        $this->expectException(IncompatibleSchemaStateException::class);
        $this->expectExceptionMessageMatches('/declared UNIQUE, found the opposite/');

        $this->execute(
            $this->plan(new CompositeDiff([new AddIndex('account', ['user_id'], 'account_user_id', true)])),
            $this->materializerOwning([]),
        );
    }

    #[Test]
    public function an_index_with_the_authored_name_but_different_columns_fails_closed(): void
    {
        $this->connection->executeStatement('CREATE TABLE account (eid INTEGER PRIMARY KEY, user_id TEXT, tenant_id TEXT)');
        $this->connection->executeStatement('CREATE INDEX account_scope ON account ("tenant_id")');

        $this->expectException(IncompatibleSchemaStateException::class);
        $this->expectExceptionMessageMatches('/declared columns \(user_id\), found \(tenant_id\)/');

        $this->execute(
            $this->plan(new CompositeDiff([new AddIndex('account', ['user_id'], 'account_scope')])),
            $this->materializerOwning([]),
        );
    }

    #[Test]
    public function a_blob_column_does_not_satisfy_an_authored_text_column(): void
    {
        $this->connection->executeStatement('CREATE TABLE account (eid INTEGER PRIMARY KEY, payload BLOB)');

        $this->expectException(IncompatibleSchemaStateException::class);
        $this->expectExceptionMessageMatches('/TEXT affinity.*BLOB affinity/s');

        $this->execute(
            $this->plan(new CompositeDiff([
                new AddColumn('account', 'payload', new ColumnSpec(type: 'text', nullable: true)),
            ])),
            $this->materializerOwning([]),
        );
    }

    #[Test]
    public function doctrines_double_precision_satisfies_an_authored_float(): void
    {
        $this->connection->executeStatement('CREATE TABLE account (eid INTEGER PRIMARY KEY, ratio DOUBLE PRECISION)');

        $outcome = $this->execute(
            $this->plan(new CompositeDiff([
                new AddColumn('account', 'ratio', new ColumnSpec(type: 'float', nullable: true)),
            ])),
            $this->materializerOwning([]),
        );

        self::assertSame(ApplyMode::AlreadySatisfied, $outcome->mode);
    }

    #[Test]
    public function a_numeric_affinity_column_does_not_satisfy_an_authored_integer(): void
    {
        // DECIMAL resolves to NUMERIC affinity; INTEGER does not. This is the
        // boundary the boolean allowlist deliberately does NOT generalize.
        $this->connection->executeStatement('CREATE TABLE account (eid INTEGER PRIMARY KEY, score DECIMAL(10,2))');

        $this->expectException(IncompatibleSchemaStateException::class);
        $this->expectExceptionMessageMatches('/INTEGER affinity.*NUMERIC affinity/s');

        $this->execute(
            $this->plan(new CompositeDiff([
                new AddColumn('account', 'score', new ColumnSpec(type: 'int', nullable: true)),
            ])),
            $this->materializerOwning([]),
        );
    }

    #[Test]
    public function doctrines_boolean_spelling_satisfies_an_authored_boolean(): void
    {
        $this->connection->executeStatement('CREATE TABLE account (eid INTEGER PRIMARY KEY, active BOOLEAN)');

        $outcome = $this->execute(
            $this->plan(new CompositeDiff([
                new AddColumn('account', 'active', new ColumnSpec(type: 'boolean', nullable: true)),
            ])),
            $this->materializerOwning([]),
        );

        self::assertSame(
            ApplyMode::AlreadySatisfied,
            $outcome->mode,
            'the allowlist reconciles the two producers for this one logical type',
        );
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

    private function tableExists(string $table): bool
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$table],
        ) === 1;
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
