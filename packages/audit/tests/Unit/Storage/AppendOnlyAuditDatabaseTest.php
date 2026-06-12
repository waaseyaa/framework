<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Storage\AppendOnlyAuditDatabase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\DeleteInterface;
use Waaseyaa\Database\InsertInterface;
use Waaseyaa\Database\SelectInterface;
use Waaseyaa\Database\UpdateInterface;

/**
 * Active append-only enforcement: the audit database decorator must refuse every
 * UPDATE/DELETE against `audit_event` while passing all other access through.
 */
#[CoversClass(AppendOnlyAuditDatabase::class)]
final class AppendOnlyAuditDatabaseTest extends TestCase
{
    private function db(): AppendOnlyAuditDatabase
    {
        return new AppendOnlyAuditDatabase(DBALDatabase::createSqlite());
    }

    #[Test]
    public function it_forbids_updates_to_audit_event(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('append-only');

        $this->db()->update('audit_event');
    }

    #[Test]
    public function it_forbids_deletes_from_audit_event(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('append-only');

        $this->db()->delete('audit_event');
    }

    #[Test]
    public function it_allows_inserts_into_audit_event(): void
    {
        // Appends are the sole sanctioned mutation — the decorator must delegate.
        $this->assertInstanceOf(InsertInterface::class, $this->db()->insert('audit_event'));
    }

    #[Test]
    public function it_allows_reads_of_audit_event(): void
    {
        $this->assertInstanceOf(SelectInterface::class, $this->db()->select('audit_event', 'ae'));
    }

    #[Test]
    public function it_passes_through_updates_to_other_tables(): void
    {
        $this->assertInstanceOf(UpdateInterface::class, $this->db()->update('node'));
    }

    #[Test]
    public function it_passes_through_deletes_from_other_tables(): void
    {
        $this->assertInstanceOf(DeleteInterface::class, $this->db()->delete('node'));
    }

    #[Test]
    public function it_passes_through_schema_and_quote_identifier(): void
    {
        $db = $this->db();

        $this->assertSame('"audit_event"', $db->quoteIdentifier('audit_event'));
        // schema() must delegate without throwing.
        $db->schema();
        $this->addToAssertionCount(1);
    }

    // ------------------------------------------------------------------
    // Raw-SQL guard on query() (FR-008, #1648, contract clauses 21–23):
    // literal/comment stripping, then mutation verb + audit table → throw.
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function blockedRawSql(): array
    {
        return [
            'UPDATE audit_event'                  => ['UPDATE audit_event SET outcome = ?'],
            'lowercase delete'                    => ['delete from audit_event where id = 1'],
            'DROP TABLE'                          => ['DROP TABLE audit_event'],
            'ALTER TABLE'                         => ['ALTER TABLE audit_event ADD x INT'],
            'TRUNCATE'                            => ['TRUNCATE audit_event'],
            'CTE-wrapped DELETE (not first-keyword-fooled)' => ['WITH x AS (SELECT 1) DELETE FROM audit_event'],
            'comment does not hide the verb+table' => ['UPDATE /* harmless */ audit_event SET x = 1'],
            'fail-closed: identifier named delete joined to audit_event' => ['SELECT 1 FROM audit_event JOIN delete ON delete.id = audit_event.id'],
        ];
    }

    #[Test]
    #[DataProvider('blockedRawSql')]
    public function it_blocks_raw_mutations_of_audit_event_through_query(string $sql): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('append-only');

        $this->db()->query($sql);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allowedRawSql(): array
    {
        return [
            'SELECT with a mutation verb inside a literal (realistic JSON-attributes search)' => ["SELECT * FROM audit_event WHERE attributes LIKE '%delete%'"],
            'whole statement is a literal'         => ["SELECT 'UPDATE audit_event'"],
            'comment containing verb+table is stripped' => ['/* delete audit_event */ SELECT 1 FROM audit_event'],
            'INSERT is an append'                  => ["INSERT INTO audit_event (uuid, event_kind) VALUES ('u-1', 'entity.write')"],
            'UPDATE of a non-audit table'          => ['UPDATE other_table SET x = 1'],
            'DELETE from a non-audit table'        => ['DELETE FROM cache_items'],
            'line comment stripped'                => ["SELECT id FROM audit_event -- update audit_event later\n"],
            'plain SELECT over audit_event'        => ['SELECT id, event_kind FROM audit_event WHERE id = 1'],
        ];
    }

    #[Test]
    #[DataProvider('allowedRawSql')]
    public function it_passes_legitimate_raw_sql_through_query(string $sql): void
    {
        // No table exists on this bare SQLite handle; the inner database may
        // fail at execution time for some statements, but the GUARD must not
        // throw \LogicException — that is the only behavior under test.
        try {
            iterator_to_array($this->db()->query($sql));
        } catch (\LogicException $e) {
            $this->fail(sprintf('Guard false-positive on legitimate SQL "%s": %s', $sql, $e->getMessage()));
        } catch (\Throwable) {
            // Execution-level failure (missing table etc.) — irrelevant here.
        }

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function the_query_guard_throws_the_same_exception_as_the_builder_guard(): void
    {
        // Contract clause 21: shared message factory — query() and update()
        // must produce byte-identical \LogicException messages for the same
        // table + operation.
        $builderMessage = null;
        try {
            $this->db()->update('audit_event');
        } catch (\LogicException $e) {
            $builderMessage = $e->getMessage();
        }
        $this->assertNotNull($builderMessage);

        $queryMessage = null;
        try {
            $this->db()->query('UPDATE audit_event SET outcome = ?');
        } catch (\LogicException $e) {
            $queryMessage = $e->getMessage();
        }
        $this->assertNotNull($queryMessage);

        $this->assertSame($builderMessage, $queryMessage);
    }
}
