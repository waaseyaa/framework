<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Unit\Listener;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Access\Context\RequestAccountContext;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Audit\Listener\AgentToolAuditListener;

#[CoversClass(AgentToolAuditListener::class)]
final class AgentToolAuditListenerTest extends TestCase
{
    /**
     * @param list<AuditEventDescriptor> $recorded
     */
    private function spyWriter(array &$recorded): AuditWriterInterface
    {
        return new class ($recorded) implements AuditWriterInterface {
            public function __construct(private array &$recorded) {}
            public function record(AuditEventDescriptor $d): void
            {
                $this->recorded[] = $d;
            }
        };
    }

    private function contextHolding(int $uid): AccountContextInterface
    {
        $context = new RequestAccountContext();
        $context->set(new AgentToolListenerStubAccount($uid));

        return $context;
    }
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

    // ------------------------------------------------------------------
    // Actor attribution matrix (#1645 S2): event accountId → context → null.
    // The pre-mission hardcoded `accountUid: 0` is gone.
    // ------------------------------------------------------------------

    #[Test]
    public function it_records_the_event_account_id_as_actor(): void
    {
        $recorded = [];
        // Context deliberately holds a DIFFERENT account: the event's own
        // accountId must win the resolution order.
        $listener = new AgentToolAuditListener(
            $this->spyWriter($recorded),
            accountContext: $this->contextHolding(99),
        );

        $event = new class {
            public string $runId = 'run-abc';
            public string $toolName = 'search_web';
            public bool $succeeded = true;
            public ?int $accountId = 7;
        };

        $listener->onToolCallObserved($event);

        $this->assertSame(7, $recorded[0]->accountUid);
    }

    #[Test]
    public function it_records_an_anonymous_zero_event_account_id_without_falling_through(): void
    {
        $recorded = [];
        $listener = new AgentToolAuditListener(
            $this->spyWriter($recorded),
            accountContext: $this->contextHolding(99),
        );

        $event = new class {
            public string $runId = 'run-abc';
            public string $toolName = 'search_web';
            public bool $succeeded = true;
            public ?int $accountId = 0;
        };

        $listener->onToolCallObserved($event);

        $this->assertSame(0, $recorded[0]->accountUid, '0 is a real initiator (anonymous) — not a fall-through trigger');
    }

    #[Test]
    public function it_falls_back_to_the_account_context_when_the_event_account_id_is_null(): void
    {
        $recorded = [];
        $listener = new AgentToolAuditListener(
            $this->spyWriter($recorded),
            accountContext: $this->contextHolding(12),
        );

        $event = new class {
            public string $runId = 'run-abc';
            public string $toolName = 'search_web';
            public bool $succeeded = true;
            public ?int $accountId = null;
        };

        $listener->onToolCallObserved($event);

        $this->assertSame(12, $recorded[0]->accountUid);
    }

    #[Test]
    public function it_records_null_actor_when_neither_event_nor_context_carries_an_account(): void
    {
        $recorded = [];
        $listener = new AgentToolAuditListener($this->spyWriter($recorded));

        $event = new class {
            public string $runId = 'run-abc';
            public string $toolName = 'search_web';
            public bool $succeeded = true;
            public ?int $accountId = null;
        };

        $listener->onToolCallObserved($event);

        $this->assertNull($recorded[0]->accountUid, 'No initiator and no context must be null — never the old hardcoded 0');
    }

    #[Test]
    public function it_handles_a_legacy_event_shape_without_the_account_id_property(): void
    {
        // Clause 11: duck-typed subscription means events lacking the
        // additive property still record, actor falling through to
        // context → null without error.
        $recorded = [];
        $listener = new AgentToolAuditListener(
            $this->spyWriter($recorded),
            accountContext: $this->contextHolding(3),
        );

        $legacyEvent = new class {
            public string $runId = 'run-legacy';
            public string $toolName = 'read_file';
            public bool $succeeded = true;
        };

        $listener->onToolCallObserved($legacyEvent);

        $this->assertCount(1, $recorded);
        $this->assertSame(3, $recorded[0]->accountUid);

        $recordedNoContext = [];
        $bare = new AgentToolAuditListener($this->spyWriter($recordedNoContext));
        $bare->onToolCallObserved($legacyEvent);

        $this->assertNull($recordedNoContext[0]->accountUid);
    }
}

/** Minimal account stub: id-only. */
final class AgentToolListenerStubAccount implements AccountInterface
{
    public function __construct(private readonly int $uid) {}

    public function id(): int|string
    {
        return $this->uid;
    }

    public function hasPermission(string $permission): bool
    {
        return false;
    }

    public function getRoles(): array
    {
        return [];
    }

    public function isAuthenticated(): bool
    {
        return $this->uid > 0;
    }
}
