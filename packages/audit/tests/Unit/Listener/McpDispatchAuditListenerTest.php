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
}
