<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
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
}
