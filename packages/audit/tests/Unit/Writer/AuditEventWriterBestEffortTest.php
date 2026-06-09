<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Unit\Writer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Audit\Storage\AppendOnlyAuditDatabase;
use Waaseyaa\Audit\Writer\AuditEventWriter;
use Waaseyaa\Database\DBALDatabase;

/**
 * NFR-001: a broken writer MUST NOT throw and MUST NOT disrupt the caller.
 */
#[CoversClass(AuditEventWriter::class)]
final class AuditEventWriterBestEffortTest extends TestCase
{
    #[Test]
    public function it_swallows_exceptions_from_a_broken_database(): void
    {
        // Schema is intentionally NOT created: the audit_event table is missing,
        // so the INSERT throws at execute(). The writer must swallow it.
        $writer = new AuditEventWriter(new AppendOnlyAuditDatabase(DBALDatabase::createSqlite()));

        // Must not throw.
        $writer->record(new AuditEventDescriptor(
            kind: AuditEventKind::EntityWrite,
            accountUid: 1,
            subjectUri: '/entities/note/abc',
            outcome: 'allowed',
            severity: 'info',
        ));

        $this->assertTrue(true, 'No exception bubbled up');
    }
}
