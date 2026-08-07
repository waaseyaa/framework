<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Audit\Listener\EntityLifecycleAuditListener;
use Waaseyaa\Audit\Storage\AppendOnlyAuditDatabase;
use Waaseyaa\Audit\Writer\AuditEventWriter;
use Waaseyaa\Audit\Writer\NullAuditWriter;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Event\EntityEvent;

/**
 * NFR-001 chaos test: a broken audit table MUST NOT 500 primary requests.
 *
 * Simulates a broken database (repository throws) and verifies that:
 * 1. AuditEventWriter swallows the exception (best-effort).
 * 2. EntityLifecycleAuditListener does not propagate the write failure.
 * 3. The primary operation (entity save) completes normally.
 */
#[CoversNothing]
final class AuditChaosTest extends TestCase
{
    #[Test]
    public function broken_audit_table_does_not_throw_on_entity_save(): void
    {
        // Broken database: schema is never created, so the audit_event table does
        // not exist and the writer's INSERT throws at execute() — simulated chaos.
        $writer = new AuditEventWriter(new AppendOnlyAuditDatabase(DBALDatabase::createSqlite()));

        $entity = $this->createStub(EntityInterface::class);
        $entity->method('isNew')->willReturn(true);
        $entity->method('getEntityTypeId')->willReturn('note');
        $entity->method('id')->willReturn('1');
        $entity->method('uuid')->willReturn('00000000-0000-0000-0000-000000000001');

        $listener = new EntityLifecycleAuditListener($writer);
        $listener->onPreSave(new EntityEvent($entity));

        // This MUST NOT throw even though the audit repo is broken.
        $listener->onPostSave(new EntityEvent($entity));

        $this->assertTrue(true, 'No exception propagated — best-effort audit did not 500');
    }

    #[Test]
    public function null_writer_is_always_safe(): void
    {
        $writer = new NullAuditWriter();

        $writer->record(new AuditEventDescriptor(
            kind: AuditEventKind::EntityWrite,
            accountUid: 0,
            subjectUri: '/test',
            outcome: 'allowed',
            severity: 'info',
        ));

        $this->assertTrue(true, 'NullAuditWriter is unconditionally safe');
    }
}
