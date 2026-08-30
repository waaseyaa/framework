<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Migration\Executor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Migration\Executor\SqliteTableDefinition;

/**
 * Boundary tests for the DDL scanner that establishes column collation.
 *
 * These deliberately attack the model rather than replay a reported example.
 * The scanner's whole value is that it is authoritative or silent, so the cases
 * that matter are the ones where a looser reader would confidently return the
 * wrong answer: a `COLLATE` token hiding inside a string default or a CHECK
 * expression, a column whose name is a prefix of another, quoted identifiers,
 * comments, and table-level constraints carrying their own commas.
 *
 * @see docs/change-records/FW-2701.md
 */
#[CoversClass(SqliteTableDefinition::class)]
final class SqliteTableDefinitionTest extends TestCase
{
    #[Test]
    #[DataProvider('confirmedCollations')]
    public function it_establishes_a_confirmed_collation(string $sql, string $column, string $expected): void
    {
        self::assertSame($expected, new SqliteTableDefinition($sql)->collationOf($column));
    }

    /** @return array<string, array{string, string, string}> */
    public static function confirmedCollations(): array
    {
        return [
            'no collate declared is authoritative BINARY' => [
                'CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)', 'name', 'BINARY',
            ],
            'explicit collate' => [
                'CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT COLLATE NOCASE)', 'name', 'NOCASE',
            ],
            'collate is upper-cased' => [
                'CREATE TABLE t (name TEXT collate nocase)', 'name', 'NOCASE',
            ],
            'double-quoted identifier' => [
                'CREATE TABLE t ("name" TEXT COLLATE RTRIM)', 'name', 'RTRIM',
            ],
            'bracket-quoted identifier' => [
                'CREATE TABLE t ([name] TEXT COLLATE NOCASE)', 'name', 'NOCASE',
            ],
            'backtick-quoted identifier' => [
                'CREATE TABLE t (`name` TEXT COLLATE NOCASE)', 'name', 'NOCASE',
            ],
            'escaped quote inside identifier' => [
                'CREATE TABLE t ("od""d" TEXT COLLATE NOCASE)', 'od"d', 'NOCASE',
            ],
            'column lookup is case-insensitive' => [
                'CREATE TABLE t (Name TEXT COLLATE NOCASE)', 'nAmE', 'NOCASE',
            ],
            'a COLLATE inside a string default belongs to the string' => [
                "CREATE TABLE t (name TEXT DEFAULT 'COLLATE NOCASE')", 'name', 'BINARY',
            ],
            'a COLLATE inside a CHECK expression is not the column collation' => [
                'CREATE TABLE t (name TEXT CHECK (name = upper(name) COLLATE NOCASE))', 'name', 'BINARY',
            ],
            'a later column collation does not leak to an earlier one' => [
                'CREATE TABLE t (a TEXT, b TEXT COLLATE NOCASE)', 'a', 'BINARY',
            ],
            'a prefix-named column is not confused with a longer one' => [
                'CREATE TABLE t (name TEXT, nameish TEXT COLLATE NOCASE)', 'name', 'BINARY',
            ],
            'the longer column is still found' => [
                'CREATE TABLE t (name TEXT, nameish TEXT COLLATE NOCASE)', 'nameish', 'NOCASE',
            ],
            'table constraints carrying commas are skipped' => [
                'CREATE TABLE t (a TEXT, b TEXT, UNIQUE (a, b), CHECK (a <> b))', 'b', 'BINARY',
            ],
            'a named table constraint is skipped' => [
                'CREATE TABLE t (a TEXT COLLATE NOCASE, CONSTRAINT c UNIQUE (a))', 'a', 'NOCASE',
            ],
            'a line comment cannot introduce a collation' => [
                "CREATE TABLE t (\n name TEXT, -- COLLATE NOCASE\n other TEXT\n)", 'name', 'BINARY',
            ],
            'a block comment cannot introduce a collation' => [
                'CREATE TABLE t (name TEXT /* COLLATE NOCASE */, other TEXT)', 'name', 'BINARY',
            ],
            'a parenthesised type does not break the split' => [
                'CREATE TABLE t (amount DECIMAL(10, 2), name TEXT COLLATE NOCASE)', 'name', 'NOCASE',
            ],
            'IF NOT EXISTS and a schema prefix are tolerated' => [
                'CREATE TABLE IF NOT EXISTS main."t" (name TEXT COLLATE NOCASE)', 'name', 'NOCASE',
            ],
        ];
    }

    #[Test]
    #[DataProvider('unknownCollations')]
    public function it_reports_unknown_rather_than_guessing(string $sql, string $column): void
    {
        // Unknown must never collapse to BINARY: callers fail closed on null,
        // and a guess would silently accept a mismatched index.
        self::assertNull(new SqliteTableDefinition($sql)->collationOf($column));
    }

    /** @return array<string, array{string, string}> */
    public static function unknownCollations(): array
    {
        return [
            'column absent' => ['CREATE TABLE t (a TEXT)', 'missing'],
            'no column list at all' => ['CREATE TABLE t', 'a'],
            'unbalanced parentheses' => ['CREATE TABLE t (a TEXT', 'a'],
            'empty ddl' => ['', 'a'],
            'collate with no argument' => ['CREATE TABLE t (a TEXT COLLATE)', 'a'],
            'a view definition is not a column list we model' => [
                'CREATE VIEW t AS SELECT 1 AS a', 'a',
            ],
        ];
    }

    #[Test]
    public function a_column_named_like_a_constraint_keyword_is_still_found(): void
    {
        // "check" is a legal quoted column name; the constraint skip must key on
        // position, not merely on the word.
        $sql = 'CREATE TABLE t ("check" TEXT COLLATE NOCASE, UNIQUE ("check"))';

        self::assertSame('NOCASE', new SqliteTableDefinition($sql)->collationOf('check'));
    }
}
