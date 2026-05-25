<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Unit\Listener;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Audit\Listener\BroadcastAuditListener;

#[CoversClass(BroadcastAuditListener::class)]
final class BroadcastAuditListenerTest extends TestCase
{
    #[Test]
    public function it_records_broadcast_publish(): void
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
            public string $channel = 'entity-updates';
            public string $messageId = 'msg-42';
            public int $accountUid = 0;
        };

        $listener = new BroadcastAuditListener($writer);
        $listener->onBroadcastPublish($event);

        $this->assertCount(1, $recorded);
        $this->assertSame(AuditEventKind::BroadcastPublish, $recorded[0]->kind);
        $this->assertSame('notice', $recorded[0]->severity);
        $this->assertSame('entity-updates', $recorded[0]->attributes['channel']);
        $this->assertSame('msg-42', $recorded[0]->attributes['message_id']);
    }

    #[Test]
    public function it_subscribes_to_the_broadcast_publish_event(): void
    {
        $subscribed = BroadcastAuditListener::getSubscribedEvents();

        $this->assertArrayHasKey(BroadcastAuditListener::EVENT_NAME, $subscribed);
    }
}
