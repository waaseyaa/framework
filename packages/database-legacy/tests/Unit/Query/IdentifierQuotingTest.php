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
 * Identifier-quoting hardening (database-legacy M1+M2).
 *
 * Builder-owned identifier paths — `fields()` / `addField()` columns + aliases,
 * `join()` / `leftJoin()` table + alias, and the WHERE-field of `Update` /
 * `Delete` — are run through the platform's `quoteIdentifier`, so a reserved-word
 * or metacharacter-bearing column name works as an identifier and cannot break
 * out into SQL.
 *
 * `condition()` / `orderBy()` `$field` is, by documented contract, a
 * developer-supplied RAW fragment (a pre-quoted identifier OR an expression such
 * as SqlEntityQuery's `json_extract(...)`) and is emitted verbatim — these tests
 * also lock that passthrough so the entity query engine keeps working.
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

    // ---- (b) condition()/orderBy() raw-fragment passthrough contract -------

    #[Test]
    public function conditionPassesJsonExtractExpressionThroughUnchanged(): void
    {
        // The SqlEntityQuery guarantee: a json_extract(...) fragment is emitted
        // verbatim, NOT quoted as a single identifier.
        $sql = $this->sqlOf(
            $this->db->select('t')->fields('t', ['id'])->condition("json_extract(_data, '\$.weight')", 5),
        );

        self::assertStringContainsString("json_extract(_data, '\$.weight')", $sql);
        self::assertStringNotContainsString('"json_extract', $sql);
    }

    #[Test]
    public function conditionPassesPreQuotedIdentifierThroughWithoutDoubleQuoting(): void
    {
        $sql = $this->sqlOf(
            $this->db->select('t')->fields('t', ['id'])->condition('"alias"."column"', 5),
        );

        self::assertStringContainsString('"alias"."column"', $sql);
        // Not re-quoted into a single blob like "alias"".""column".
        self::assertStringNotContainsString('"""', $sql);
    }

    #[Test]
    public function orderByPassesJsonExtractExpressionThroughUnchanged(): void
    {
        $sql = $this->sqlOf(
            $this->db->select('t')->fields('t', ['id'])->orderBy("json_extract(_data, '\$.weight')", 'DESC'),
        );

        self::assertStringContainsString("json_extract(_data, '\$.weight')", $sql);
        self::assertStringNotContainsString('"json_extract', $sql);
    }
}
