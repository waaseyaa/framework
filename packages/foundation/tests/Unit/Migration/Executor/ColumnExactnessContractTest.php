<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Migration\Executor;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\Executor\IncompatibleSchemaStateException;
use Waaseyaa\Foundation\Migration\Executor\OpPrecondition;
use Waaseyaa\Foundation\Migration\Executor\OpPreconditionResolver;
use Waaseyaa\Foundation\Migration\Executor\SqliteTableDefinition;
use Waaseyaa\Foundation\Schema\Diff\AddColumn;
use Waaseyaa\Foundation\Schema\Diff\ColumnSpec;

/**
 * C3 for `AddColumn`, decided in full.
 *
 * The authored vocabulary is `ColumnSpec`, and `AddColumnTranslator` renders
 * exactly `<type> [NOT NULL] [DEFAULT <literal>]`. SQLite's `column-constraint`
 * and `table-constraint` productions are finite, so the properties a live column
 * can carry outside that vocabulary are a closed list: `PRIMARY KEY`, generated,
 * `UNIQUE`, `REFERENCES`, `COLLATE`, a `NOT NULL` conflict policy, and `CHECK`
 * dependence. Every one of them is refused here, and every one has a positive
 * control beside it that must still be **accepted** — a blanket rejection of
 * constrained tables would pass the refusals and fail the controls.
 *
 * Where SQLite itself can settle a case it is asked to: the CHECK and conflict
 * families assert the database's own observed behaviour alongside the verdict,
 * so those expectations are observations rather than readings of the code under
 * test.
 *
 * @see docs/change-records/FW-2701.md — C3 already satisfied, C4 fail closed
 */
#[CoversClass(OpPreconditionResolver::class)]
#[CoversClass(SqliteTableDefinition::class)]
#[CoversClass(IncompatibleSchemaStateException::class)]
final class ColumnExactnessContractTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DBALDatabase::createSqlite()->getConnection();
        $this->connection->executeStatement('CREATE TABLE parent (id INTEGER PRIMARY KEY)');
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    /**
     * Not expressible in `ColumnSpec`, therefore not the column the operation
     * declares, therefore refused under `[S1-DB110]`.
     *
     * @param list<string> $extraDdl
     */
    #[Test]
    #[DataProvider('unauthorableShapes')]
    public function an_unauthorable_column_shape_is_refused(
        string $body,
        string $expectedMessage,
        array $extraDdl = [],
        string $type = 'int',
    ): void {
        $this->createSample($body, $extraDdl);
        $before = $this->schemaSnapshot();

        try {
            $this->resolve($type);
            self::fail('an unauthorable column shape must not satisfy an authored plain column');
        } catch (IncompatibleSchemaStateException $e) {
            self::assertStringContainsString('S1-DB110', $e->getMessage());
            self::assertStringContainsString($expectedMessage, $e->getMessage());
        }

        self::assertSame($before, $this->schemaSnapshot(), 'a refusal issues no schema change');
    }

    /** @return array<string, array{0: string, 1: string, 2?: list<string>, 3?: string}> */
    public static function unauthorableShapes(): array
    {
        return [
            // UNIQUE — a constraint of the table, not a separate schema object.
            'inline UNIQUE' => [
                'value INTEGER UNIQUE',
                'found a member of a UNIQUE constraint',
            ],
            'table-level UNIQUE' => [
                'value INTEGER, UNIQUE (value)',
                'found a member of a UNIQUE constraint',
            ],
            'composite UNIQUE, non-first position' => [
                'other INTEGER, value INTEGER, UNIQUE (other, value)',
                'found a member of a UNIQUE constraint',
            ],
            // REFERENCES — the column is the foreign key's source.
            'inline REFERENCES' => [
                'value INTEGER REFERENCES parent (id)',
                'found the source of a foreign key',
            ],
            'table-level FOREIGN KEY' => [
                'value INTEGER, FOREIGN KEY (value) REFERENCES parent (id)',
                'found the source of a foreign key',
            ],
            // Generated columns are absent from PRAGMA table_info entirely, so
            // before this repair they failed on a raw SQL error rather than an
            // auditable refusal.
            'VIRTUAL generated target' => [
                'other INTEGER, value INTEGER GENERATED ALWAYS AS (other + 1) VIRTUAL',
                'found a VIRTUAL generated column',
            ],
            'STORED generated target' => [
                'other INTEGER, value INTEGER GENERATED ALWAYS AS (other + 1) STORED',
                'found a STORED generated column',
            ],
            'shorthand generated target' => [
                'other INTEGER, value INTEGER AS (other + 1)',
                'found a VIRTUAL generated column',
            ],
            // Collation decides comparison and uniqueness semantics.
            'COLLATE NOCASE' => [
                'value TEXT COLLATE NOCASE',
                'the column declares COLLATE NOCASE',
                [],
                'text',
            ],
            'the last COLLATE clause is the effective one' => [
                'value TEXT COLLATE BINARY COLLATE NOCASE',
                'the column declares COLLATE NOCASE',
                [],
                'text',
            ],
            // A virtual table's ordinary columns report hidden = 0 and a
            // plausible-looking table_xinfo row, so only the stored definition
            // can establish that this is not a column list we model.
            'a virtual table is not a column list we model' => [
                self::VIRTUAL_TABLE,
                'the stored definition is not an ordinary table declaration',
            ],
        ];
    }

    /**
     * The controls. Each is a real column beside real structure, and each must
     * stay `already_satisfied` — refusing them would be a false refusal of a
     * valid migration, which is what a blanket rule would produce.
     *
     * @param list<string> $extraDdl
     */
    #[Test]
    #[DataProvider('equivalentShapes')]
    public function an_ordinary_column_beside_unrelated_structure_is_still_satisfied(
        string $body,
        array $extraDdl = [],
        string $type = 'int',
        bool $nullable = true,
        mixed $default = null,
    ): void {
        $this->createSample($body, $extraDdl);

        self::assertSame(
            OpPrecondition::AlreadySatisfied,
            $this->resolve($type, $nullable, $default),
        );
    }

    /** @return array<string, array{0: string, 1?: list<string>, 2?: string, 3?: bool, 4?: mixed}> */
    public static function equivalentShapes(): array
    {
        return [
            'a plain column' => ['value INTEGER'],
            'beside a primary key' => ['other INTEGER PRIMARY KEY, value INTEGER'],
            'beside a UNIQUE column' => ['other INTEGER UNIQUE, value INTEGER'],
            'beside a foreign key' => ['other INTEGER REFERENCES parent (id), value INTEGER'],
            'beside a generated column' => ['other INTEGER, gen INTEGER AS (other + 1), value INTEGER'],
            'beside an unrelated CHECK' => ['other INTEGER CHECK (other > 0), value INTEGER'],
            // An index is a separate schema object with its own authored form,
            // AddIndex. A UNIQUE index created by CREATE INDEX therefore does
            // not make the column unauthorable — and EntitySchemaSync emits
            // exactly this shape for entity `uuid` columns.
            'under an independently created UNIQUE index' => [
                'value INTEGER, other INTEGER',
                ['CREATE UNIQUE INDEX value_idx ON sample (value)'],
            ],
            // Being a foreign key's *target* is a property of the other table.
            // SQLite requires the parent key to carry a unique index, which is
            // supplied here by CREATE UNIQUE INDEX rather than by a UNIQUE
            // constraint on the column, so the column itself stays plain.
            'as the target of another table\'s foreign key' => [
                'value INTEGER, other INTEGER',
                [
                    'CREATE UNIQUE INDEX value_idx ON sample (value)',
                    'CREATE TABLE child (ref INTEGER REFERENCES sample (value))',
                ],
            ],
            'explicit COLLATE BINARY restates the default' => [
                'value TEXT COLLATE BINARY',
                [],
                'text',
            ],
            'a COLLATE keyword inside a string default is not a clause' => [
                "value TEXT DEFAULT 'COLLATE NOCASE'",
                [],
                'text',
                true,
                'COLLATE NOCASE',
            ],
        ];
    }

    /**
     * A `NOT NULL` conflict policy other than `ABORT` is a different column.
     *
     * Three of the five policies throw on a single-row NULL insert, so "it
     * throws" is not the comparison. `FAIL` keeps the rows a failing statement
     * already wrote, and `ROLLBACK` aborts the **enclosing** transaction — which
     * here is the migration's own per-node transaction. The oracle below is
     * therefore two observations, not one.
     */
    #[Test]
    #[DataProvider('conflictPolicies')]
    public function a_not_null_conflict_policy_is_compared_semantically(
        string $clause,
        bool $equivalent,
        bool $rejectsSingleNull,
        int $rowsAfterPartialInsert,
    ): void {
        $this->createSample(sprintf('value INTEGER %s DEFAULT 7', $clause));

        self::assertSame($rejectsSingleNull, $this->insertThrows('INSERT INTO sample (value) VALUES (NULL)'));
        $this->connection->executeStatement('DELETE FROM sample');
        $this->insertThrows('INSERT INTO sample (value) VALUES (1), (NULL)');
        self::assertSame(
            $rowsAfterPartialInsert,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM sample'),
            'a bare NOT NULL undoes the whole statement; only an equivalent policy does the same',
        );
        $this->connection->executeStatement('DELETE FROM sample');

        if ($equivalent) {
            self::assertSame(OpPrecondition::AlreadySatisfied, $this->resolve('int', false, 7));

            return;
        }

        $this->expectException(IncompatibleSchemaStateException::class);
        $this->expectExceptionMessageMatches("/not the compiler's ABORT/");
        $this->resolve('int', false, 7);
    }

    /** @return array<string, array{string, bool, bool, int}> */
    public static function conflictPolicies(): array
    {
        return [
            // clause, equivalent, single NULL insert throws, rows left by
            // INSERT ... VALUES (1), (NULL)
            'bare NOT NULL' => ['NOT NULL', true, true, 0],
            'explicit ABORT' => ['NOT NULL ON CONFLICT ABORT', true, true, 0],
            // Throws like ABORT, but keeps the row the failed statement wrote.
            'FAIL' => ['NOT NULL ON CONFLICT FAIL', false, true, 1],
            // Throws like ABORT; the divergence is the enclosing transaction.
            'ROLLBACK' => ['NOT NULL ON CONFLICT ROLLBACK', false, true, 0],
            'IGNORE' => ['NOT NULL ON CONFLICT IGNORE', false, false, 1],
            'REPLACE' => ['NOT NULL ON CONFLICT REPLACE', false, false, 2],

            // Composition. A definition may carry several constraints, so the
            // policy has to be attributed to the clause SQLite actually applies:
            // the LAST `NOT NULL`, defaulting to ABORT when that one names none.
            // A bare `NULL` constraint's clause is inert. Reading "any ON
            // CONFLICT that is not ABORT" instead refuses the first two of these,
            // which behave exactly like a bare NOT NULL.
            'a later inert NULL clause does not change ABORT' => [
                'NOT NULL ON CONFLICT ABORT NULL ON CONFLICT IGNORE', true, true, 0,
            ],
            'the last NOT NULL wins, restoring ABORT' => [
                'NOT NULL ON CONFLICT IGNORE NOT NULL ON CONFLICT ABORT', true, true, 0,
            ],
            'a NOT NULL naming no policy is ABORT' => [
                'NOT NULL ON CONFLICT IGNORE NOT NULL', true, true, 0,
            ],
            'the last NOT NULL wins, diverging' => [
                'NOT NULL ON CONFLICT ABORT NOT NULL ON CONFLICT IGNORE', false, false, 1,
            ],
            'an inert NULL clause cannot rescue a divergent NOT NULL' => [
                'NOT NULL ON CONFLICT IGNORE NULL ON CONFLICT ABORT', false, false, 1,
            ],
            'a bare NOT NULL before a policy-bearing one does not win' => [
                'NOT NULL NOT NULL ON CONFLICT IGNORE', false, false, 1,
            ],
            // A quoted constraint name spelling the keyword is not the keyword.
            'a constraint named "ON" is not an ON CONFLICT clause' => [
                'NOT NULL ON CONFLICT FAIL CONSTRAINT "ON" CHECK (1)', false, true, 1,
            ],
        ];
    }

    /**
     * `CREATE TABLE virtual (…)` is an ordinary table whose name happens to be
     * the word `virtual`. Deciding "is this a CREATE VIRTUAL TABLE?" by
     * searching every bare word before the column list refuses it, which is a
     * wrong answer about valid DDL; the statement header has to be read by
     * position.
     */
    #[Test]
    public function a_table_named_after_the_virtual_keyword_is_still_an_ordinary_table(): void
    {
        $this->connection->executeStatement('CREATE TABLE virtual (value INTEGER, other INTEGER)');

        self::assertSame(
            OpPrecondition::AlreadySatisfied,
            new OpPreconditionResolver($this->connection)->resolve(
                new AddColumn('virtual', 'value', new ColumnSpec(type: 'int', nullable: true)),
            ),
        );
    }

    /**
     * The same distinction at the reader, plus the header spellings it must
     * keep accepting.
     *
     * @param list<string> $expectedFragments
     */
    #[Test]
    #[DataProvider('statementHeaders')]
    public function the_statement_header_is_read_by_position(string $sql, array $expectedFragments): void
    {
        self::assertSame(
            $expectedFragments,
            new SqliteTableDefinition($sql)->plainColumnDivergences('value'),
        );
    }

    /** @return array<string, array{string, list<string>}> */
    public static function statementHeaders(): array
    {
        $notATable = ['the stored definition is not an ordinary table declaration'];

        return [
            'a table named virtual' => ['CREATE TABLE virtual (value INTEGER)', []],
            'a temporary table' => ['CREATE TEMP TABLE t (value INTEGER)', []],
            'a spelled-out temporary table' => ['CREATE TEMPORARY TABLE t (value INTEGER)', []],
            'IF NOT EXISTS and a schema prefix' => [
                'CREATE TABLE IF NOT EXISTS main."t" (value INTEGER)', [],
            ],
            'a string-quoted table name' => ["CREATE TABLE 't' (value INTEGER)", []],
            'a virtual table' => ['CREATE VIRTUAL TABLE t USING fts5(value)', $notATable],
            'a view' => ['CREATE VIEW t AS SELECT 1 AS value', $notATable],
            'an index' => ['CREATE UNIQUE INDEX i ON t (value)', $notATable],
        ];
    }

    /**
     * `ON CONFLICT ROLLBACK` is not ABORT with a different spelling: it discards
     * the enclosing transaction's earlier work. Inside the Migrator that
     * transaction is the node's own, which is why this policy cannot be treated
     * as the compiler's default.
     */
    #[Test]
    public function on_conflict_rollback_discards_the_enclosing_transactions_earlier_work(): void
    {
        $this->createSample('value INTEGER NOT NULL ON CONFLICT ROLLBACK DEFAULT 7');
        $this->connection->executeStatement('CREATE TABLE earlier (n INTEGER)');

        $this->connection->beginTransaction();
        $this->connection->executeStatement('INSERT INTO earlier (n) VALUES (1)');
        self::assertTrue($this->insertThrows('INSERT INTO sample (value) VALUES (NULL)'));

        // The oracle is the database, not DBAL's bookkeeping: DBAL keeps its own
        // nesting counter and still reports an active transaction here, because
        // SQLite rolled back underneath it without telling the driver. That
        // divergence is part of the harm, not the measurement.
        self::assertSame(
            0,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM earlier'),
            'the earlier statement in the same transaction is discarded, which ABORT never does',
        );
        $native = $this->connection->getNativeConnection();
        self::assertInstanceOf(\PDO::class, $native);
        self::assertFalse($native->inTransaction(), 'SQLite itself has no transaction left to commit');
    }

    /**
     * The contested family. A CHECK constrains a column when its expression can
     * read it, wherever the constraint is written; syntactic placement proves
     * nothing either way. SQLite is the oracle: the expectation is asserted
     * against a real insert of `value = -10` before the verdict is read.
     */
    #[Test]
    #[DataProvider('checkExpressions')]
    public function a_check_is_refused_exactly_when_it_can_read_the_column(
        string $expression,
        bool $equivalent,
    ): void {
        $this->createSample(sprintf('value INTEGER, other INTEGER, CHECK (%s)', $expression));

        $this->assertSqliteAgrees($equivalent);
        $this->assertVerdict($equivalent, 'a CHECK constraint can read the column');
    }

    /**
     * The same expressions, attached syntactically to the *other* column. A
     * constraint's placement is not its scope, so every case that constrains
     * `value` from the table level constrains it from here too.
     */
    #[Test]
    #[DataProvider('checkExpressions')]
    public function a_check_attached_to_another_column_has_the_same_scope(
        string $expression,
        bool $equivalent,
    ): void {
        $this->createSample(sprintf('value INTEGER, other INTEGER CHECK (%s)', $expression));

        $this->assertSqliteAgrees($equivalent);
        $this->assertVerdict($equivalent, 'a CHECK constraint can read the column');
    }

    /** @return array<string, array{string, bool}> */
    public static function checkExpressions(): array
    {
        return [
            // Reads `value` — every column-reference spelling SQLite offers.
            'bare name' => ['value > 0', false],
            'qualified' => ['sample.value > 0', false],
            'single-quoted column in an identifier position' => ["sample.'value' > 0", false],
            'single-quoted on both sides of the dot' => ["'sample'.'value' > 0", false],
            'mixed quoting across the dot' => ["[sample].'value' > 0", false],
            'double-quoted' => ['"value" > 0', false],
            'bracket-quoted' => ['[value] > 0', false],
            'backtick-quoted' => ['`value` > 0', false],
            'case-insensitive' => ['VaLuE > 0', false],
            'comments around the dot do not join tokens' => ['sample/*x*/./*y*/value > 0', false],
            'nested parentheses' => ['(((value))) > 0', false],
            'inside a cast' => ['CAST(value AS INTEGER) > 0', false],
            'inside a CASE expression' => ['CASE WHEN value < 0 THEN 0 ELSE 1 END', false],
            'inside a function call' => ['coalesce(value, 1) > 0', false],
            'inside arithmetic' => ['(value + 1) > 0', false],
            'BETWEEN' => ['value BETWEEN 1 AND 100', false],
            'IN list' => ['value IN (1, 2, 3)', false],
            'NOT IN list' => ['value NOT IN (-10)', false],
            'under an explicit COLLATE' => ['(value COLLATE BINARY) > 0', false],

            // Reads no column, or only the other one. These must be accepted:
            // they are the false-refusal side of the boundary.
            'a standalone single-quoted token is a literal' => ["'value' != 'other'", true],
            'a double-quoted token that names no column' => ['"not_a_column" != \'other\'', true],
            'another column, bare' => ['other > 0', true],
            'another column, qualified' => ['sample.other > 0', true],
            'another column, single-quoted' => ["'sample'.'other' > 0", true],
            'another column in a function' => ['abs(other) > 0', true],
            'another column in a cast' => ['CAST(other AS INTEGER) > 0', true],
            'another column in a CASE' => ['CASE WHEN other < 0 THEN 0 ELSE 1 END', true],
            'another column in BETWEEN' => ['other BETWEEN 1 AND 100', true],
            'another column in an IN list' => ['other IN (1, 2, 3)', true],
            'the column name inside a string and a comment' => [
                "'CHECK(value > 0)' != 'x' /* value */",
                true,
            ],
            'a numeric exponent is not an identifier' => ['other < 1e10', true],
            'a blob literal spelling the column name is still a literal' => [
                "X'76616c7565' != X'01'",
                true,
            ],
        ];
    }

    /**
     * A CHECK can reach the target column through a generated column, and
     * through arbitrarily many of them. `CHECK (derived > 0)` names only
     * `derived`, yet SQLite rejects `value = -10`.
     */
    #[Test]
    #[DataProvider('generatedColumnIndirection')]
    public function a_check_reaches_the_column_through_generated_columns(
        string $body,
        bool $equivalent,
    ): void {
        $this->createSample($body);

        $this->assertSqliteAgrees($equivalent);
        $this->assertVerdict($equivalent, 'a CHECK constraint can read the column');
    }

    /** @return array<string, array{string, bool}> */
    public static function generatedColumnIndirection(): array
    {
        return [
            'one link' => [
                'value INTEGER, other INTEGER, derived INTEGER AS (value + 1), CHECK (derived > 0)',
                false,
            ],
            'two links' => [
                'value INTEGER, other INTEGER, a INTEGER AS (value + 1), b INTEGER AS (a + 1), CHECK (b > 0)',
                false,
            ],
            'a quoted reference inside the generated expression' => [
                'value INTEGER, other INTEGER, a INTEGER AS ("value" + 1), CHECK (a > 0)',
                false,
            ],
            'a generated column over the other column only' => [
                'value INTEGER, other INTEGER, a INTEGER AS (other + 1), CHECK (a > 0)',
                true,
            ],
            'two links over the other column only' => [
                'value INTEGER, other INTEGER, a INTEGER AS (other + 1), b INTEGER AS (a + 1), CHECK (b > 0)',
                true,
            ],
            'a generated column whose expression is a literal' => [
                "value INTEGER, other INTEGER, a TEXT AS ('value'), CHECK (a != 'x')",
                true,
            ],
            'two CHECKs, one of which reads the column' => [
                'value INTEGER, other INTEGER CHECK (other > 0), CHECK (value > 0)',
                false,
            ],
            // The constraint NAME is not part of the expression.
            'a constraint named after the column' => [
                'value INTEGER, other INTEGER, CONSTRAINT value CHECK (other > 0)',
                true,
            ],
        ];
    }

    /**
     * SQLite accepts a string literal where a column name is expected, so a
     * column may be *declared* as `'value'`. Reading only bare and delimited
     * spellings would report an ordinary column as unknown and refuse a valid
     * migration — the same wrong answer the collation reader already corrected.
     */
    #[Test]
    public function a_column_declared_with_a_string_literal_name_is_still_read(): void
    {
        $this->createSample("'value' INTEGER, other INTEGER");

        self::assertSame(OpPrecondition::AlreadySatisfied, $this->resolve());
    }

    #[Test]
    public function a_string_literal_named_column_carrying_a_collation_is_still_refused(): void
    {
        $this->createSample("'value' TEXT COLLATE NOCASE, other INTEGER");

        $this->expectException(IncompatibleSchemaStateException::class);
        $this->expectExceptionMessageMatches('/declares COLLATE NOCASE/');

        $this->resolve('text');
    }

    /**
     * Unknown never collapses to "equivalent".
     *
     * These are asserted at the reader, not through the resolver, because
     * malformed schema text cannot be produced by `CREATE TABLE` — SQLite
     * rejects it. The reader still has to meet it: `sqlite_master.sql` is
     * arbitrary text as far as this class is concerned, and answering
     * confidently about text it cannot parse is the one failure it must not
     * have.
     *
     * @param list<string> $expectedFragments
     */
    #[Test]
    #[DataProvider('unreadableDefinitions')]
    public function an_unreadable_stored_definition_is_reported_not_assumed(
        string $sql,
        string $column,
        array $expectedFragments,
    ): void {
        $divergences = new SqliteTableDefinition($sql)->plainColumnDivergences($column);

        foreach ($expectedFragments as $fragment) {
            self::assertContains($fragment, $divergences);
        }
    }

    /** @return array<string, array{string, string, list<string>}> */
    public static function unreadableDefinitions(): array
    {
        return [
            'empty schema text' => [
                '', 'value', ['the stored definition is not an ordinary table declaration'],
            ],
            'no column list' => [
                'CREATE TABLE sample', 'value',
                ['the stored definition could not be split into column definitions'],
            ],
            'unbalanced parentheses' => [
                'CREATE TABLE sample (value INTEGER', 'value',
                ['the stored definition could not be split into column definitions'],
            ],
            'the column is absent' => [
                'CREATE TABLE sample (other INTEGER)', 'value',
                ['the column is not present in the stored definition'],
            ],
            'a virtual table' => [
                'CREATE VIRTUAL TABLE sample USING fts5(value, other)', 'value',
                ['the stored definition is not an ordinary table declaration'],
            ],
            'a view' => [
                'CREATE VIEW sample AS SELECT 1 AS value', 'value',
                ['the stored definition is not an ordinary table declaration'],
            ],
            'a COLLATE with no argument' => [
                'CREATE TABLE sample (value TEXT COLLATE)', 'value',
                ['a COLLATE clause argument could not be read'],
            ],
            'a CHECK with no expression' => [
                'CREATE TABLE sample (value INTEGER, CHECK)', 'value',
                ['a CHECK clause could not be read'],
            ],
            'an ON CONFLICT with no policy' => [
                'CREATE TABLE sample (value INTEGER NOT NULL ON CONFLICT)', 'value',
                ['an ON CONFLICT policy could not be read'],
            ],
            'a generated column with no expression' => [
                'CREATE TABLE sample (value INTEGER, gen INTEGER AS)', 'value',
                ['a generated-column expression could not be read'],
            ],
        ];
    }

    /** A clean ordinary column yields an empty list — a proof, not an absence. */
    #[Test]
    public function a_clean_definition_yields_no_divergences(): void
    {
        self::assertSame(
            [],
            new SqliteTableDefinition('CREATE TABLE sample (value INTEGER, other INTEGER CHECK (other > 0))')
                ->plainColumnDivergences('value'),
        );
    }

    /**
     * SQLite decides, before the verdict is read, whether the fixture really
     * constrains `value`.
     */
    private function assertSqliteAgrees(bool $equivalent): void
    {
        self::assertSame(
            !$equivalent,
            $this->insertThrows('INSERT INTO sample (value, other) VALUES (-10, 1)'),
            'SQLite must reject value = -10 exactly when the fixture constrains the column',
        );
    }

    private function assertVerdict(bool $equivalent, string $expectedMessage): void
    {
        if ($equivalent) {
            self::assertSame(OpPrecondition::AlreadySatisfied, $this->resolve());

            return;
        }

        $this->expectException(IncompatibleSchemaStateException::class);
        $this->expectExceptionMessage($expectedMessage);
        $this->resolve();
    }

    private function insertThrows(string $sql): bool
    {
        try {
            $this->connection->executeStatement($sql);

            return false;
        } catch (\Throwable) {
            return true;
        }
    }

    private function resolve(string $type = 'int', bool $nullable = true, mixed $default = null): OpPrecondition
    {
        return new OpPreconditionResolver($this->connection)->resolve(
            new AddColumn('sample', 'value', new ColumnSpec(type: $type, nullable: $nullable, default: $default)),
        );
    }

    /** Sentinel for the one fixture that is not a `CREATE TABLE` body. */
    private const VIRTUAL_TABLE = '';

    /** @param list<string> $extraDdl */
    private function createSample(string $body, array $extraDdl = []): void
    {
        $this->connection->executeStatement(
            $body === self::VIRTUAL_TABLE
                ? 'CREATE VIRTUAL TABLE sample USING fts5(value, other)'
                : sprintf('CREATE TABLE sample (%s)', $body),
        );

        foreach ($extraDdl as $sql) {
            $this->connection->executeStatement($sql);
        }
    }

    /** @return list<array<string, mixed>> */
    private function schemaSnapshot(): array
    {
        return $this->connection->fetchAllAssociative('SELECT * FROM sqlite_master ORDER BY name');
    }
}
