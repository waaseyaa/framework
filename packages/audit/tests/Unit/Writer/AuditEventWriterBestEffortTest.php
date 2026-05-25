<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Unit\Writer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Audit\Writer\AuditEventWriter;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

/**
 * NFR-001: a broken writer MUST NOT throw and MUST NOT disrupt the caller.
 */
#[CoversClass(AuditEventWriter::class)]
final class AuditEventWriterBestEffortTest extends TestCase
{
    #[Test]
    public function it_swallows_exceptions_from_a_broken_repository(): void
    {
        $repo = $this->createMock(EntityRepositoryInterface::class);
        $repo->method('save')->willThrowException(new \RuntimeException('DB exploded'));

        $writer = new AuditEventWriter($repo);

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
