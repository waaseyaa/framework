<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Unit\Writer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Audit\Writer\AuditEventWriter;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

#[CoversClass(AuditEventWriter::class)]
final class AuditEventWriterTest extends TestCase
{
    #[Test]
    public function it_records_a_descriptor_as_an_entity(): void
    {
        $repo = $this->createMock(EntityRepositoryInterface::class);
        $repo->expects($this->once())->method('save');

        $writer = new AuditEventWriter($repo);
        $writer->record(new AuditEventDescriptor(
            kind: AuditEventKind::EntityWrite,
            accountUid: 1,
            subjectUri: '/entities/note/abc-123',
            outcome: 'allowed',
            severity: 'info',
        ));
    }

    #[Test]
    public function it_implements_audit_writer_interface(): void
    {
        $repo = $this->createMock(EntityRepositoryInterface::class);
        $writer = new AuditEventWriter($repo);

        $this->assertInstanceOf(AuditWriterInterface::class, $writer);
    }
}
