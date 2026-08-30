<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Migration\Executor;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\Executor\SqliteTableDefinition;
use Waaseyaa\Foundation\Schema\Compiler\Sqlite\Translator\AddIndexTranslator;
use Waaseyaa\Foundation\Schema\Diff\AddIndex;

/**
 * Differential tests: SQLite itself is the oracle for column collation.
 *
 * The parser exists to predict what collation an index created by this
 * compiler will actually inherit. That prediction is only trustworthy if it is
 * checked against the database rather than against the parser's own model, so
 * each case here builds the real table, creates the authored index through the
 * real compiler, and reads the effective collation back out of
 * `PRAGMA index_xinfo`.
 *
 * The contract under test is one-sided and deliberately so: a **non-null**
 * parser result must agree with SQLite exactly. Returning null (unknown) is
 * always permitted, because unknown fails closed and cannot cause a wrong
 * accept. What must never happen is a confident answer that differs from the
 * database.
 *
 * @see docs/change-records/FW-2701.md
 */
#[CoversClass(SqliteTableDefinition::class)]
final class SqliteCollationOracleTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DBALDatabase::createSqlite()->getConnection();
    }

    #[Test]
    #[DataProvider('columnDefinitions')]
    public function a_confirmed_parse_agrees_with_sqlite(string $columnDefinition, ?string $mustConfirm): void
    {
        $ddl = sprintf('CREATE TABLE t (id INTEGER PRIMARY KEY, %s)', $columnDefinition);
        $this->connection->executeStatement($ddl);

        $oracle = $this->effectiveIndexCollation('t', 'name');
        $parsed = new SqliteTableDefinition($ddl)->collationOf('name');

        if ($parsed !== null) {
            self::assertSame(
                $oracle,
                $parsed,
                sprintf('parser said %s, SQLite uses %s, for: %s', $parsed, $oracle, $columnDefinition),
            );
        }

        if ($mustConfirm !== null) {
            self::assertSame(
                $mustConfirm,
                $parsed,
                sprintf('this shape must be resolvable, not unknown: %s', $columnDefinition),
            );
        }
    }

    /**
     * Each case is a valid column definition for a column called `name`.
     * The second value is the collation the parser must confirm, or null when
     * returning unknown is an acceptable outcome.
     *
     * @return array<string, array{string, string|null}>
     */
    public static function columnDefinitions(): array
    {
        return [
            // --- the reported P1 counterexamples ---
            'comment between COLLATE and its argument' => ['name TEXT COLLATE/* c */NOCASE', 'NOCASE'],
            'repeated clause: the later one wins' => ['name TEXT COLLATE BINARY COLLATE NOCASE', 'NOCASE'],
            'COLLATE inside an identifier is not a clause' => ['name acollate BINARY COLLATE NOCASE', 'NOCASE'],

            // --- token-boundary attacks ---
            'line comment between COLLATE and argument' => ["name TEXT COLLATE -- c\n NOCASE", 'NOCASE'],
            'comment before the column name' => ['/* lead */ name TEXT COLLATE NOCASE', 'NOCASE'],
            'comment between name and type' => ['name /* c */ TEXT COLLATE NOCASE', 'NOCASE'],
            'no whitespace around a parenthesised type' => ['name VARCHAR(10)COLLATE NOCASE', 'NOCASE'],
            'three repeated clauses' => ['name TEXT COLLATE NOCASE COLLATE BINARY COLLATE RTRIM', 'RTRIM'],
            'type name ending in collate-like text' => ['name mycollate COLLATE NOCASE', 'NOCASE'],
            'identifier suffix after COLLATE keyword' => ['name TEXT COLLATEX NOCASE', null],

            // --- quoting ---
            'quoted collation name' => ['name TEXT COLLATE "NOCASE"', 'NOCASE'],
            'bracketed collation name' => ['name TEXT COLLATE [NOCASE]', 'NOCASE'],
            'quoted column identifier' => ['"name" TEXT COLLATE NOCASE', 'NOCASE'],
            'bracketed column identifier' => ['[name] TEXT COLLATE NOCASE', 'NOCASE'],

            // --- COLLATE that must NOT be read as the column collation ---
            'inside a string default' => ["name TEXT DEFAULT 'COLLATE NOCASE'", 'BINARY'],
            'inside a CHECK expression' => ['name TEXT CHECK (name = upper(name) COLLATE NOCASE)', 'BINARY'],
            'inside a nested default expression' => ['name TEXT DEFAULT (upper(\'x\' COLLATE NOCASE))', 'BINARY'],

            // --- ordinary shapes that must stay resolvable ---
            'plain column' => ['name TEXT', 'BINARY'],
            'not null with default' => ["name TEXT NOT NULL DEFAULT 'x'", 'BINARY'],
            'collate then constraint' => ['name TEXT COLLATE NOCASE NOT NULL', 'NOCASE'],
            'constraint then collate' => ['name TEXT NOT NULL COLLATE NOCASE', 'NOCASE'],
        ];
    }

    /**
     * SQLite's lexer skips a UTF-8 BOM at a **token start** as whitespace, but
     * treats those same bytes as ordinary identifier characters inside a token.
     * The distinction is the whole point: `TEXT <BOM>COLLATE NOCASE` carries a
     * real clause, while `COLLATE<BOM>NOCASE` is one identifier and carries
     * none. A global strip would get the second case wrong, so the negative
     * controls below are as load-bearing as the positive one.
     *
     * @param non-empty-string $columnDefinition
     */
    #[Test]
    #[DataProvider('byteOrderMarkPlacements')]
    public function a_byte_order_mark_is_whitespace_only_at_a_token_start(
        string $columnDefinition,
        string $column,
    ): void {
        $ddl = sprintf('CREATE TABLE t (id INTEGER PRIMARY KEY, %s)', $columnDefinition);
        $this->connection->executeStatement($ddl);

        $stored = (string) $this->connection->fetchOne(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 't'",
        );
        $parsed = new SqliteTableDefinition($stored)->collationOf($column);

        if ($parsed !== null) {
            self::assertSame($this->effectiveIndexCollation('t', $column), $parsed);
        }
    }

    /** @return array<string, array{string, string}> */
    public static function byteOrderMarkPlacements(): array
    {
        $bom = "\xEF\xBB\xBF";

        return [
            // Positive: a BOM at a token start is whitespace, so the clause is real.
            'before COLLATE' => ['name TEXT ' . $bom . 'COLLATE NOCASE', 'name'],
            'before the column name' => [$bom . 'name TEXT COLLATE NOCASE', 'name'],
            'before the type' => ['name ' . $bom . 'TEXT COLLATE NOCASE', 'name'],
            'before the collation argument' => ['name TEXT COLLATE ' . $bom . 'NOCASE', 'name'],
            'repeated at a token start' => ['name TEXT ' . $bom . $bom . 'COLLATE NOCASE', 'name'],
            'after a comma' => ['a TEXT, ' . $bom . 'name TEXT COLLATE NOCASE', 'name'],

            // Negative controls: inside a token those bytes are identifier
            // characters and must survive intact.
            'inside the column name' => ['na' . $bom . 'me TEXT COLLATE NOCASE, na TEXT', 'na'],
            'inside the type name' => ['name TE' . $bom . 'XT COLLATE NOCASE', 'name'],
            'joined to the COLLATE keyword' => ['name TEXT COLLATE' . $bom . 'NOCASE', 'name'],
            // (a BOM inside the collation NAME is not a valid variant: SQLite
            // rejects the statement with "no such collation sequence")
        ];
    }

    #[Test]
    public function a_table_level_constraint_after_the_column_does_not_change_the_answer(): void
    {
        $ddl = 'CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT COLLATE NOCASE, UNIQUE (id, name))';
        $this->connection->executeStatement($ddl);

        self::assertSame($this->effectiveIndexCollation('t', 'name'), new SqliteTableDefinition($ddl)->collationOf('name'));
    }

    /**
     * What collation an index created by the real compiler actually uses.
     */
    private function effectiveIndexCollation(string $table, string $column): string
    {
        $step = AddIndexTranslator::translate(new AddIndex($table, [$column], 'oracle_idx'));
        $this->connection->executeStatement($step->sql());

        foreach ($this->connection->fetchAllAssociative('PRAGMA index_xinfo("oracle_idx")') as $entry) {
            if ((int) ($entry['key'] ?? 0) === 1) {
                return strtoupper(trim((string) ($entry['coll'] ?? '')));
            }
        }

        self::fail('the oracle index reported no key column');
    }
}
