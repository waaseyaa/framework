<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Audit\Writer\AuditEventWriter;
use Waaseyaa\Audit\Writer\NullAuditWriter;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

/**
 * Contract test: every AuditWriterInterface implementation must be best-effort.
 */
#[CoversNothing]
final class AuditWriterContractTest extends TestCase
{
    /**
     * @return array<string, AuditWriterInterface>
     */
    private function implementations(): array
    {
        $repo = $this->createMock(EntityRepositoryInterface::class);

        return [
            'AuditEventWriter' => new AuditEventWriter($repo),
            'NullAuditWriter'  => new NullAuditWriter(),
        ];
    }

    #[Test]
    public function all_implementations_implement_the_interface(): void
    {
        foreach ($this->implementations() as $name => $impl) {
            $this->assertInstanceOf(AuditWriterInterface::class, $impl, $name);
        }
    }

    #[Test]
    public function record_never_throws_on_any_implementation(): void
    {
        $descriptor = new AuditEventDescriptor(
            kind: AuditEventKind::EntityWrite,
            accountUid: 0,
            subjectUri: '/test',
            outcome: 'allowed',
            severity: 'info',
        );

        foreach ($this->implementations() as $name => $impl) {
            $impl->record($descriptor);
            $this->assertTrue(true, "record() on $name did not throw");
        }
    }
}
