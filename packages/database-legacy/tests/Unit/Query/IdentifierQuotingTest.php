<?php

declare(strict_types=1);

namespace Waaseyaa\Database\Tests\Unit\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\Query\DBALDelete;
use Waaseyaa\Database\Query\DBALSelect;
use Waaseyaa\Database\Query\DBALUpdate;
use Waaseyaa\Database\SelectInterface;

/**
 * Identifier-quoting hardening (database-legacy M1+M2, WP6).
 *
 * Builder-owned identifier paths — `fields()` / `addField()` columns + aliases,
 * `join()` / `leftJoin()` table + alias, the WHERE-field of `Update` / `Delete`,
 * and (WP6) the `$field` of `condition()` / `orderBy()` / `isNull()` /
 * `isNotNull()` on `Select` — are run through the platform's `quoteIdentifier`,
 * so a reserved-word or metacharacter-bearing column name works as an identifier
 * and cannot break out into SQL.
 *
 * SQL *expressions* (json_extract, COALESCE, CAST) that cannot be quoted as an
 * identifier go through the WP6 raw seams `whereRaw()` / `orderByRaw()`, which
 * emit verbatim and bind positional `?` parameters — these tests lock both the
 * auto-quoting and the verbatim raw passthrough.
 */
#[CoversClass(DBALSelect::class)]
#[CoversClass(DBALUpdate::class)]
#[CoversClass(DBALDelete::class)]
final class IdentifierQuotingTest extends TestCase
{
    private DBALDatabase $db;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
    }

    /** Reflect the wrapped Doctrine QueryBuilder to inspect the generated SQL. */
    private function sqlOf(SelectInterface $select): string
    {
        $ref = new \ReflectionProperty(DBALSelect::class, 'qb');
        /** @var \Doctrine\DBAL\Query\QueryBuilder $qb */
        $qb = $ref->getValue($select);

        return $qb->getSQL();
    }

    // ---- (a) builder-owned paths quote identifiers ------------------------

    #[Test]
    public function fieldsQuotesReservedWordColumns(): void
    {
        // `key`, `order`, `count` are SQL reserved words — must be quoted to be
        // usable as identifiers.
        $sql = $this->sqlOf($this->db->select('t')->fields('t', ['key', 'order', 'count']));

        self::assertStringContainsString('"key"', $sql);
        self::assertStringContainsString('"order"', $sql);
        self::assertStringContainsString('"count"', $sql);
    }

    #[Test]
    public function addFieldQuotesColumnAndAlias(): void
    {
        $sql = $this->sqlOf($this->db->select('t')->addField('t', 'count', 'order'));

        self::assertStringContainsString('"count"', $sql);
        self::assertStringContainsString('AS "order"', $sql);
    }

    #[Test]
    public function joinQuotesTableAndAlias(): void
    {
        $sql = $this->sqlOf(
            $this->db->select('users', 'u')->fields('u', ['id'])->join('roles', 'order', 'u.id = x'),
        );

        self::assertStringContainsString('"roles"', $sql);
        self::assertStringContainsString('"order"', $sql);
    }

    #[Test]
    public function fieldsRendersMetacharacterIdentifierInert(): void
    {
        // An identifier containing a double-quote must be quoted with the quote
        // DOUBLED (escaped) — rendered inert, never concatenated raw.
        $sql = $this->sqlOf($this->db->select('t')->fields('t', ['evil"col']));

        self::assertStringContainsString('"evil""col"', $sql);
        // The raw, unescaped form must not appear as a bare identifier.
        self::assertStringNotContainsString('.evil"col', $sql);
    }

    #[Test]
    public function fieldsKeepsStarUnquoted(): void
    {
        $sql = $this->sqlOf($this->db->select('t')->fields('t', ['*']));

        self::assertStringContainsString('.*', $sql);
        self::assertStringNotContainsString('"*"', $sql);
    }

    // ---- (a) end-to-end: reserved-word columns work through the engine -----

    #[Test]
    public function selectReadsReservedWordColumnsEndToEnd(): void
    {
        $conn = $this->db->getConnection();
        $conn->executeStatement('CREATE TABLE rw ("key" TEXT, "order" INTEGER, "count" INTEGER)');
        $conn->executeStatement('INSERT INTO rw ("key", "order", "count") VALUES (?, ?, ?)', ['alpha', 2, 7]);

        $rows = iterator_to_array(
            $this->db->select('rw')->fields('rw', ['key', 'order', 'count'])->execute(),
        );

        self::assertCount(1, $rows);
        self::assertSame('alpha', $rows[0]['key']);
        self::assertSame(2, (int) $rows[0]['order']);
        self::assertSame(7, (int) $rows[0]['count']);
    }

    #[Test]
    public function updateConditionQuotesReservedWordColumnSimplePath(): void
    {
        $conn = $this->db->getConnection();
        $conn->executeStatement('CREATE TABLE c ("count" INTEGER, val TEXT)');
        $conn->executeStatement('INSERT INTO c ("count", val) VALUES (?, ?)', [5, 'before']);

        // WHERE "count" = 5 via the simple path (operator '='). Unquoted `count`
        // would be a syntax error.
        $affected = $this->db->update('c')->fields(['val' => 'after'])->condition('count', 5)->execute();

        self::assertSame(1, $affected);
        $row = iterator_to_array($this->db->query('SELECT val FROM c WHERE "count" = 5'));
        self::assertSame('after', $row[0]['val']);
    }

    #[Test]
    public function updateConditionQuotesReservedWordColumnComplexPath(): void
    {
        $conn = $this->db->getConnection();
        $conn->executeStatement('CREATE TABLE c ("count" INTEGER, val TEXT)');
        $conn->executeStatement('INSERT INTO c ("count", val) VALUES (?, ?)', [5, 'before']);

        // IN forces the QueryBuilder/applyConditions path.
        $affected = $this->db->update('c')->fields(['val' => 'after'])->condition('count', [5, 6], 'IN')->execute();

        self::assertSame(1, $affected);
    }

    #[Test]
    public function deleteConditionQuotesReservedWordColumnSimplePath(): void
    {
        $conn = $this->db->getConnection();
        $conn->executeStatement('CREATE TABLE c ("count" INTEGER)');
        $conn->executeStatement('INSERT INTO c ("count") VALUES (5)');
        $conn->executeStatement('INSERT INTO c ("count") VALUES (9)');

        $affected = $this->db->delete('c')->condition('count', 5)->execute();

        self::assertSame(1, $affected);
        $remaining = iterator_to_array($this->db->query('SELECT "count" FROM c'));
        self::assertCount(1, $remaining);
        self::assertSame(9, (int) $remaining[0]['count']);
    }

    #[Test]
    public function deleteConditionQuotesReservedWordColumnComplexPath(): void
    {
        $conn = $this->db->getConnection();
        $conn->executeStatement('CREATE TABLE c ("count" INTEGER)');
        $conn->executeStatement('INSERT INTO c ("count") VALUES (5)');

        $affected = $this->db->delete('c')->condition('count', [5, 6], 'IN')->execute();

        self::assertSame(1, $affected);
    }

    // ---- (b) WP6: condition()/orderBy()/isNull()/isNotNull() quote $field ---

    #[Test]
    public function conditionQuotesReservedWordColumnInert(): void
    {
        // `order` is a SQL reserved word — quoted inert, never raw.
        $sql = $this->sqlOf(
            $this->db->select('t')->fields('t', ['id'])->condition('order', 5),
        );

        self::assertStringContainsString('"order" = ', $sql);
    }

    #[Test]
    public function conditionQuotesMetacharacterColumnInert(): void
    {
        // A column carrying a double-quote must be quoted with the quote DOUBLED
        // (escaped) — rendered inert, never concatenated raw.
        $sql = $this->sqlOf(
            $this->db->select('t')->fields('t', ['id'])->condition('evil"col', 5),
        );

        self::assertStringContainsString('"evil""col"', $sql);
        self::assertStringNotContainsString(' evil"col ', $sql);
    }

    #[Test]
    public function conditionQuotesQualifiedIdentifierPerPart(): void
    {
        // A qualified `alias.column` is split on `.` and each part quoted —
        // `"alias"."column"`, valid and referencing the alias.
        $sql = $this->sqlOf(
            $this->db->select('t', 'alias')->fields('alias', ['id'])->condition('alias.column', 5),
        );

        self::assertStringContainsString('"alias"."column" = ', $sql);
    }

    #[Test]
    public function orderByQuotesReservedWordColumnInert(): void
    {
        $sql = $this->sqlOf(
            $this->db->select('t')->fields('t', ['id'])->orderBy('order', 'DESC'),
        );

        self::assertStringContainsString('ORDER BY "order" DESC', $sql);
    }

    #[Test]
    public function isNullAndIsNotNullQuoteReservedWordColumnInert(): void
    {
        $nullSql = $this->sqlOf(
            $this->db->select('t')->fields('t', ['id'])->isNull('order'),
        );
        self::assertStringContainsString('"order" IS NULL', $nullSql);

        $notNullSql = $this->sqlOf(
            $this->db->select('t')->fields('t', ['id'])->isNotNull('count'),
        );
        self::assertStringContainsString('"count" IS NOT NULL', $notNullSql);
    }

    #[Test]
    public function reservedWordColumnFilterAndSortWorkEndToEnd(): void
    {
        $conn = $this->db->getConnection();
        $conn->executeStatement('CREATE TABLE rw ("order" INTEGER, val TEXT)');
        $conn->executeStatement('INSERT INTO rw ("order", val) VALUES (2, ?)', ['b']);
        $conn->executeStatement('INSERT INTO rw ("order", val) VALUES (1, ?)', ['a']);

        $rows = iterator_to_array(
            $this->db->select('rw')
                ->fields('rw', ['order', 'val'])
                ->condition('order', 1, '>')
                ->orderBy('order', 'ASC')
                ->execute(),
        );

        self::assertCount(1, $rows);
        self::assertSame('b', $rows[0]['val']);
    }

    // ---- (b) WP6: whereRaw() / orderByRaw() emit verbatim + bind params -----

    #[Test]
    public function whereRawEmitsJsonExtractExpressionVerbatimAndBindsParam(): void
    {
        $sql = $this->sqlOf(
            $this->db->select('t')->fields('t', ['id'])->whereRaw("json_extract(_data, '\$.weight') = ?", [5]),
        );

        self::assertStringContainsString("json_extract(_data, '\$.weight') = :", $sql);
        // The expression is NOT quoted as an identifier.
        self::assertStringNotContainsString('"json_extract', $sql);
    }

    #[Test]
    public function whereRawExpandsArrayParameterForInList(): void
    {
        $conn = $this->db->getConnection();
        $conn->executeStatement('CREATE TABLE j (_data TEXT)');
        $conn->executeStatement('INSERT INTO j (_data) VALUES (?)', ['{"weight":2}']);
        $conn->executeStatement('INSERT INTO j (_data) VALUES (?)', ['{"weight":9}']);

        // CAST(... AS TEXT) IN (?) with an array param — the K3 entity-query path.
        $rows = iterator_to_array(
            $this->db->select('j')
                ->fields('j', ['_data'])
                ->whereRaw("CAST(json_extract(_data, '\$.weight') AS TEXT) IN (?)", [['2', '9']])
                ->execute(),
        );

        self::assertCount(2, $rows);
    }

    #[Test]
    public function orderByRawEmitsJsonExtractExpressionVerbatim(): void
    {
        $sql = $this->sqlOf(
            $this->db->select('t')->fields('t', ['id'])->orderByRaw("json_extract(_data, '\$.weight')", 'DESC'),
        );

        self::assertStringContainsString("ORDER BY json_extract(_data, '\$.weight') DESC", $sql);
        self::assertStringNotContainsString('"json_extract', $sql);
    }

    #[Test]
    public function whereRawRejectsParameterCountMismatch(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (void) $this->db->select('t')->fields('t', ['id'])->whereRaw('a = ? AND b = ?', [1]);
    }

    #[Test]
    public function orderByRawRejectsInvalidDirection(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (void) $this->db->select('t')->fields('t', ['id'])->orderByRaw("json_extract(_data, '\$.w')", 'SIDEWAYS');
    }
}
