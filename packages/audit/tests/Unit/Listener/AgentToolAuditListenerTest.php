<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Unit\Listener;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Audit\Listener\AgentToolAuditListener;

#[CoversClass(AgentToolAuditListener::class)]
final class AgentToolAuditListenerTest extends TestCase
{
    #[Test]
    public function it_records_agent_tool_execute_on_tool_call_observed(): void
    {
        $recorded = [];
        $writer = new class ($recorded) implements AuditWriterInterface {
            public function __construct(private array &$recorded) {}
            public function record(AuditEventDescriptor $d): void
            {
                $this->recorded[] = $d;
            }
        };

        // Use an anonymous object to avoid importing from ai-observability (layer 5).
        $event = new class {
            public string $runId = 'run-abc';
            public string $toolName = 'search_web';
            public bool $succeeded = true;
        };

        $listener = new AgentToolAuditListener($writer);
        $listener->onToolCallObserved($event);

        $this->assertCount(1, $recorded);
        $this->assertSame(AuditEventKind::AgentToolExecute, $recorded[0]->kind);
        $this->assertSame('allowed', $recorded[0]->outcome);
        $this->assertSame('search_web', $recorded[0]->attributes['tool_name']);
    }

    #[Test]
    public function it_records_error_outcome_on_failed_tool_call(): void
    {
        $recorded = [];
        $writer = new class ($recorded) implements AuditWriterInterface {
            public function __construct(private array &$recorded) {}
            public function record(AuditEventDescriptor $d): void
            {
                $this->recorded[] = $d;
            }
        };

        $event = new class {
            public string $runId = 'run-xyz';
            public string $toolName = 'read_file';
            public bool $succeeded = false;
        };

        $listener = new AgentToolAuditListener($writer);
        $listener->onToolCallObserved($event);

        $this->assertSame('error', $recorded[0]->outcome);
        $this->assertSame('warning', $recorded[0]->severity);
    }

    #[Test]
    public function it_subscribes_to_the_agent_tool_call_event_by_class_name_string(): void
    {
        $subscribed = AgentToolAuditListener::getSubscribedEvents();

        $this->assertArrayHasKey(AgentToolAuditListener::AGENT_TOOL_CALL_EVENT, $subscribed);
    }
}
