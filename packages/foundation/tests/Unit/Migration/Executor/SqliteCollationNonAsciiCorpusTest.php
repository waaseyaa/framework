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
 * The non-ASCII identifier corpus, kept as a permanent regression.
 *
 * A differential fuzz against real SQLite (26,892 variants) found 54 distinct
 * column definitions where the parser returned a confident collation that
 * disagreed with the database. Every one had a single cause: the tokenizer's
 * identifier class was ASCII-only, while SQLite's own rule treats **any byte
 * at or above 0x80** as an identifier character. A non-ASCII byte therefore
 * ended an identifier early, which could fabricate a standalone `COLLATE` token
 * where SQLite sees only a multi-word type name, or split a column name so that
 * a lookup matched the wrong definition.
 *
 * Both directions failed **open** — each produced a confident wrong answer that
 * would accept a mismatched index — so the whole corpus is retained rather than
 * a sample of it.
 *
 * The assertion is the same one-sided contract used elsewhere: a non-null result
 * must agree with SQLite; unknown is always permitted.
 *
 * @see docs/change-records/FW-2701.md
 */
#[CoversClass(SqliteTableDefinition::class)]
final class SqliteCollationNonAsciiCorpusTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DBALDatabase::createSqlite()->getConnection();
    }

    #[Test]
    #[DataProvider('corpus')]
    public function a_confirmed_parse_agrees_with_sqlite(string $columnDefinition, string $column): void
    {
        $ddl = sprintf('CREATE TABLE t (id INTEGER PRIMARY KEY, %s)', $columnDefinition);
        $this->connection->executeStatement($ddl);

        $stored = (string) $this->connection->fetchOne(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 't'",
        );
        $parsed = new SqliteTableDefinition($stored)->collationOf($column);
        if ($parsed === null) {
            // Unknown fails closed and cannot cause a wrong accept.
            self::assertNull($parsed);

            return;
        }

        self::assertSame(
            $this->effectiveIndexCollation('t', $column),
            $parsed,
            sprintf('parser disagreed with SQLite for: %s', $columnDefinition),
        );
    }

    /** @return array<string, array{string, string}> */
    public static function corpus(): array
    {
        return [
            'case 1: x TEXTéCOLLATE NOCASE' => ['x TEXTéCOLLATE NOCASE', 'x'],
            'case 2: x INTEGERéCOLLATE RTRIM' => ['x INTEGERéCOLLATE RTRIM', 'x'],
            'case 3: x TEXT名前COLLATE NOCASE' => ['x TEXT名前COLLATE NOCASE', 'x'],
            'case 4: café TEXT COLLATE NOCASE, caf TEXT' => ['café TEXT COLLATE NOCASE, caf TEXT', 'caf'],
            'case 5: xü TEXT COLLATE NOCASE, x TEXT' => ['xü TEXT COLLATE NOCASE, x TEXT', 'x'],
            'case 6: x_é TEXT COLLATE NOCASE, x_ TEXT' => ['x_é TEXT COLLATE NOCASE, x_ TEXT', 'x_'],
            'case 7: x TEXT /*c*/ TEXTéCOLLATE NOCASE' => ['x TEXT /*c*/ TEXTéCOLLATE NOCASE', 'x'],
            'case 8: c BIGéCOLLATE RTRIM' => ['c BIGéCOLLATE RTRIM', 'c'],
            'case 9: c FOOéCOLLATE NOCASE' => ['c FOOéCOLLATE NOCASE', 'c'],
            'case 10: c ÉCOLLATE NOCASE' => ['c ÉCOLLATE NOCASE', 'c'],
            'case 11: c 中文COLLATE NOCASE' => ['c 中文COLLATE NOCASE', 'c'],
            'case 12: naïve TEXT COLLATE NOCASE, na TEXT' => ['naïve TEXT COLLATE NOCASE, na TEXT', 'na'],
            'case 13: prénom TEXT COLLATE NOCASE, pr TEXT' => ['prénom TEXT COLLATE NOCASE, pr TEXT', 'pr'],
            'case 14: ké TEXT, k TEXT COLLATE NOCASE' => ['ké TEXT, k TEXT COLLATE NOCASE', 'k'],
            'case 15: c🙂 TEXT COLLATE NOCASE, c TEXT' => ['c🙂 TEXT COLLATE NOCASE, c TEXT', 'c'],
            'case 16: user_ß TEXT COLLATE NOCASE, user_ TEXT' => ['user_ß TEXT COLLATE NOCASE, user_ TEXT', 'user_'],
            'case 17: a éCOLLATE NOCASE' => ['a éCOLLATE NOCASE', 'a'],
            'case 18: a TEXT COLLATE NOCASE CONSTRAINT éCOLLATE NOT NULL' => ['a TEXT COLLATE NOCASE CONSTRAINT éCOLLATE NOT NULL', 'a'],
            'case 19: a TEXT COLLATE NOCASE CONSTRAINT éCOLLATE COLLATE RTRIM' => ['a TEXT COLLATE NOCASE CONSTRAINT éCOLLATE COLLATE RTRIM', 'a'],
            'case 20: a TEXT COLLATE NOCASE CONSTRAINT éCOLLATE UNIQUE' => ['a TEXT COLLATE NOCASE CONSTRAINT éCOLLATE UNIQUE', 'a'],
            'case 21: a TEXT REFERENCES éCOLLATE COLLATE NOCASE' => ['a TEXT REFERENCES éCOLLATE COLLATE NOCASE', 'a'],
            'case 22: a TEXT CONSTRAINT éCOLLATE NOT NULL' => ['a TEXT CONSTRAINT éCOLLATE NOT NULL', 'a'],
            'case 23: a TEXT CONSTRAINT éCOLLATE DEFAULT x' => ['a TEXT CONSTRAINT éCOLLATE DEFAULT \'x\'', 'a'],
            'case 24: a TEXT CONSTRAINT éCOLLATE CHECK (a <> )' => ['a TEXT CONSTRAINT éCOLLATE CHECK (a <> \'\')', 'a'],
            'case 25: a TEXT CONSTRAINT éCOLLATE REFERENCES t(id)' => ['a TEXT CONSTRAINT éCOLLATE REFERENCES t(id)', 'a'],
            'case 26: a TEXT CONSTRAINT c_éCOLLATE NOT NULL' => ['a TEXT CONSTRAINT c_éCOLLATE NOT NULL', 'a'],
            'case 27: a UNSIGNED BIG éCOLLATE NOCASE' => ['a UNSIGNED BIG éCOLLATE NOCASE', 'a'],
            'case 28: a 中COLLATE NOCASE' => ['a 中COLLATE NOCASE', 'a'],
            'case 29: a ΩCOLLATE RTRIM' => ['a ΩCOLLATE RTRIM', 'a'],
            'case 30: a TEXT CONSTRAINT éCOLLATE NOT NULL, b TEXT COLLATE RTRIM' => ['a TEXT CONSTRAINT éCOLLATE NOT NULL, b TEXT COLLATE RTRIM', 'a'],
            'case 31: nameé TEXT COLLATE NOCASE, name TEXT' => ['nameé TEXT COLLATE NOCASE, name TEXT', 'name'],
            'case 32: nameé TEXT COLLATE RTRIM, name TEXT COLLATE BINARY' => ['nameé TEXT COLLATE RTRIM, name TEXT COLLATE BINARY', 'name'],
            'case 33: name中 TEXT COLLATE NOCASE, name TEXT' => ['name中 TEXT COLLATE NOCASE, name TEXT', 'name'],
            'case 34: nameµ TEXT COLLATE NOCASE, name TEXT' => ['nameµ TEXT COLLATE NOCASE, name TEXT', 'name'],
            'case 35: nameé TEXT, name TEXT COLLATE NOCASE' => ['nameé TEXT, name TEXT COLLATE NOCASE', 'name'],
            'case 36: naïve TEXT, na TEXT COLLATE NOCASE' => ['naïve TEXT, na TEXT COLLATE NOCASE', 'na'],
            'case 37: naïve TEXT COLLATE RTRIM, na TEXT COLLATE NOCASE' => ['naïve TEXT COLLATE RTRIM, na TEXT COLLATE NOCASE', 'na'],
            'case 38: naïve TEXT COLLATE NOCASE, na TEXT, UNIQUE(na), CHECK(na<>' => ['naïve TEXT COLLATE NOCASE, na TEXT, UNIQUE(na), CHECK(na<>\'\'), FOREIGN KEY(id) REFERENCES t(id)', 'na'],
            'case 39: naïve TEXT, na TEXT COLLATE NOCASE, UNIQUE(id), CHECK(id>0' => ['naïve TEXT, na TEXT COLLATE NOCASE, UNIQUE(id), CHECK(id>0)', 'na'],
            'case 40: naïve TEXT COLLATE NOCASE, na TEXT) WITHOUT ROWID --' => ['naïve TEXT COLLATE NOCASE, na TEXT) WITHOUT ROWID --', 'na'],
            'case 41: id2 INTEGER, naïve TEXT COLLATE RTRIM, na TEXT, UNIQUE(id2' => ['id2 INTEGER, naïve TEXT COLLATE RTRIM, na TEXT, UNIQUE(id2)', 'na'],
            'case 42: straße TEXT COLLATE NOCASE, stra TEXT' => ['straße TEXT COLLATE NOCASE, stra TEXT', 'stra'],
            'case 43: aé TEXT COLLATE NOCASE, a TEXT' => ['aé TEXT COLLATE NOCASE, a TEXT', 'a'],
            'case 44: naïve TEXT COLLATE NOCASE, "na" TEXT' => ['naïve TEXT COLLATE NOCASE, "na" TEXT', 'na'],
            'case 45: name_é TEXT COLLATE NOCASE, name_ TEXT' => ['name_é TEXT COLLATE NOCASE, name_ TEXT', 'name_'],
            'case 46: x TEXTÜCOLLATE RTRIM' => ['x TEXTÜCOLLATE RTRIM', 'x'],
            'case 47: x TÜCOLLATE NOCASE' => ['x TÜCOLLATE NOCASE', 'x'],
            'case 48: nameü TEXT, name TEXT COLLATE RTRIM' => ['nameü TEXT, name TEXT COLLATE RTRIM', 'name'],
            'case 49: na🙂x TEXT COLLATE NOCASE, na TEXT' => ['na🙂x TEXT COLLATE NOCASE, na TEXT', 'na'],
            'case 50: café TEXT COLLATE RTRIM, caf TEXT' => ['café TEXT COLLATE RTRIM, caf TEXT', 'caf'],
            'case 51: año TEXT COLLATE NOCASE, a TEXT' => ['año TEXT COLLATE NOCASE, a TEXT', 'a'],
            'case 52: x_ü TEXT COLLATE NOCASE, x_ TEXT' => ['x_ü TEXT COLLATE NOCASE, x_ TEXT', 'x_'],
            'case 53: xüCOLLATE TEXT, x TEXT' => ['xüCOLLATE TEXT, x TEXT', 'x'],
            'case 54: yÜCOLLATE TEXT, y TEXT COLLATE NOCASE' => ['yÜCOLLATE TEXT, y TEXT COLLATE NOCASE', 'y'],
        ];
    }

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
