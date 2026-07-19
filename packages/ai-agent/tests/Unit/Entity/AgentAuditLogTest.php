<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Agent\Entity\AgentAuditLog;
use Waaseyaa\AI\Agent\Enum\EventType;

#[CoversClass(AgentAuditLog::class)]
final class AgentAuditLogTest extends TestCase
{
    #[Test]
    public function forFactoryProducesIsNewEntityWithDefaults(): void
    {
        $occurredAt = new \DateTimeImmutable('2026-05-18T12:00:00+00:00');

        $log = AgentAuditLog::for(
            id: 'evt-1',
            runId: 'run-1',
            iteration: 3,
            eventType: EventType::ToolCall,
            occurredAt: $occurredAt,
            toolName: 'entity.read',
            toolArgumentsJson: '{"id":"node-1"}',
        );

        self::assertSame('agent_audit_log', $log->getEntityTypeId());
        self::assertSame('evt-1', $log->id());
        $reader = new \Waaseyaa\Tests\Support\AgentAuditEventTypeReaderFixture();
        self::assertSame('run-1', $reader->runId($log));
        self::assertSame(EventType::ToolCall, $reader->read($log));
        $toolCall = $reader->toolCall($log);
        self::assertSame(3, $toolCall['iteration']);
        self::assertSame('entity.read', $toolCall['toolName']);
        self::assertSame('{"id":"node-1"}', $toolCall['toolArgumentsJson']);
        self::assertTrue($reader->success($log));
        self::assertTrue($log->isNew(), 'for() must return an isNew entity ready for append().');
    }

    #[Test]
    public function forFactoryMergesExtraOverrides(): void
    {
        $log = AgentAuditLog::for(
            id: 'evt-2',
            runId: 'run-2',
            iteration: 1,
            eventType: EventType::Error,
            occurredAt: new \DateTimeImmutable('2026-05-18T13:00:00+00:00'),
            success: false,
            extra: ['tool_result_summary' => 'upstream 502'],
        );

        $reader = new \Waaseyaa\Tests\Support\AgentAuditEventTypeReaderFixture();
        self::assertFalse($reader->success($log));
        self::assertSame('upstream 502', $reader->toolResultSummary($log));
        self::assertSame(EventType::Error, $reader->read($log));
    }
}
