<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Unit\Listener;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Audit\Listener\McpDispatchAuditListener;

#[CoversClass(McpDispatchAuditListener::class)]
final class McpDispatchAuditListenerTest extends TestCase
{
    #[Test]
    public function it_records_mcp_dispatch_with_hashed_params(): void
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
            public string $method = 'tools/call';
            public array $params = ['tool' => 'search', 'input' => 'secret'];
            public int $accountUid = 5;
        };

        $listener = new McpDispatchAuditListener($writer);
        $listener->onMcpDispatch($event);

        $this->assertCount(1, $recorded);
        $this->assertSame(AuditEventKind::McpDispatch, $recorded[0]->kind);
        $this->assertArrayHasKey('params_hash', $recorded[0]->attributes);
        $this->assertArrayNotHasKey('params', $recorded[0]->attributes);
        $this->assertSame(5, $recorded[0]->accountUid);
    }

    #[Test]
    public function it_subscribes_to_the_mcp_dispatch_event(): void
    {
        $subscribed = McpDispatchAuditListener::getSubscribedEvents();

        $this->assertArrayHasKey(McpDispatchAuditListener::EVENT_NAME, $subscribed);
    }

    // ------------------------------------------------------------------
    // Actor preservation (#1645 S4, contract clause 19): a null accountUid
    // stays null — the pre-mission `(int)` 0-coercion is gone.
    // ------------------------------------------------------------------

    #[Test]
    public function it_preserves_a_null_account_uid_from_the_event(): void
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
            public string $method = 'tools/list';
            public array $params = [];
            public ?int $accountUid = null;
        };

        $listener = new McpDispatchAuditListener($writer);
        $listener->onMcpDispatch($event);

        $this->assertCount(1, $recorded);
        $this->assertNull($recorded[0]->accountUid, 'Null bearer account must stay null — never coerced to 0');
        // The privacy property is untouched: params are hashed, never stored.
        $this->assertArrayHasKey('params_hash', $recorded[0]->attributes);
        $this->assertArrayNotHasKey('params', $recorded[0]->attributes);
    }

    #[Test]
    public function it_records_null_when_the_event_lacks_the_account_uid_property(): void
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
            public string $method = 'initialize';
            public array $params = [];
        };

        $listener = new McpDispatchAuditListener($writer);
        $listener->onMcpDispatch($event);

        $this->assertNull($recorded[0]->accountUid);
    }

    #[Test]
    public function it_records_an_anonymous_zero_account_uid_verbatim(): void
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
            public string $method = 'tools/call';
            public array $params = ['tool' => 'x'];
            public ?int $accountUid = 0;
        };

        $listener = new McpDispatchAuditListener($writer);
        $listener->onMcpDispatch($event);

        $this->assertSame(0, $recorded[0]->accountUid, '0 means the anonymous account — a real value, kept verbatim');
    }
}
