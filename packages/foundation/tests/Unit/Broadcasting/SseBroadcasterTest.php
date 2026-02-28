<?php

declare(strict_types=1);

namespace Aurora\Foundation\Tests\Unit\Broadcasting;

use Aurora\Foundation\Broadcasting\BroadcasterInterface;
use Aurora\Foundation\Broadcasting\BroadcastMessage;
use Aurora\Foundation\Broadcasting\SseBroadcaster;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SseBroadcaster::class)]
final class SseBroadcasterTest extends TestCase
{
    #[Test]
    public function implements_broadcaster_interface(): void
    {
        $broadcaster = new SseBroadcaster();

        $this->assertInstanceOf(BroadcasterInterface::class, $broadcaster);
    }

    #[Test]
    public function broadcast_delivers_to_matching_subscribers(): void
    {
        $broadcaster = new SseBroadcaster();
        $received = [];

        $broadcaster->subscribe('admin.node', function (BroadcastMessage $msg) use (&$received): void {
            $received[] = $msg;
        });

        $message = new BroadcastMessage(
            channel: 'admin.node',
            event: 'entity.saved',
            data: ['id' => '1'],
        );

        $broadcaster->broadcast($message);

        $this->assertCount(1, $received);
        $this->assertSame($message, $received[0]);
    }

    #[Test]
    public function broadcast_does_not_deliver_to_other_channels(): void
    {
        $broadcaster = new SseBroadcaster();
        $received = [];

        $broadcaster->subscribe('admin.node', function (BroadcastMessage $msg) use (&$received): void {
            $received[] = $msg;
        });

        $message = new BroadcastMessage(
            channel: 'admin.user',
            event: 'entity.saved',
            data: ['id' => '1'],
        );

        $broadcaster->broadcast($message);

        $this->assertCount(0, $received);
    }

    #[Test]
    public function broadcast_delivers_to_multiple_subscribers(): void
    {
        $broadcaster = new SseBroadcaster();
        $received1 = [];
        $received2 = [];

        $broadcaster->subscribe('system', function (BroadcastMessage $msg) use (&$received1): void {
            $received1[] = $msg;
        });

        $broadcaster->subscribe('system', function (BroadcastMessage $msg) use (&$received2): void {
            $received2[] = $msg;
        });

        $message = new BroadcastMessage(channel: 'system', event: 'config.changed');

        $broadcaster->broadcast($message);

        $this->assertCount(1, $received1);
        $this->assertCount(1, $received2);
    }

    #[Test]
    public function get_subscribed_channels_returns_all_channels(): void
    {
        $broadcaster = new SseBroadcaster();

        $broadcaster->subscribe('admin.node', fn() => null);
        $broadcaster->subscribe('system', fn() => null);
        $broadcaster->subscribe('pipeline.123', fn() => null);

        $channels = $broadcaster->getSubscribedChannels();

        $this->assertCount(3, $channels);
        $this->assertContains('admin.node', $channels);
        $this->assertContains('system', $channels);
        $this->assertContains('pipeline.123', $channels);
    }

    #[Test]
    public function has_subscribers_returns_correct_status(): void
    {
        $broadcaster = new SseBroadcaster();

        $this->assertFalse($broadcaster->hasSubscribers('admin.node'));

        $broadcaster->subscribe('admin.node', fn() => null);

        $this->assertTrue($broadcaster->hasSubscribers('admin.node'));
        $this->assertFalse($broadcaster->hasSubscribers('system'));
    }

    #[Test]
    public function subscriber_count_returns_correct_count(): void
    {
        $broadcaster = new SseBroadcaster();

        $this->assertSame(0, $broadcaster->subscriberCount('admin.node'));

        $broadcaster->subscribe('admin.node', fn() => null);
        $this->assertSame(1, $broadcaster->subscriberCount('admin.node'));

        $broadcaster->subscribe('admin.node', fn() => null);
        $this->assertSame(2, $broadcaster->subscriberCount('admin.node'));
    }

    #[Test]
    public function keep_log_records_broadcast_messages(): void
    {
        $broadcaster = new SseBroadcaster(keepLog: true);

        $msg1 = new BroadcastMessage(channel: 'a', event: 'e1');
        $msg2 = new BroadcastMessage(channel: 'b', event: 'e2');

        $broadcaster->broadcast($msg1);
        $broadcaster->broadcast($msg2);

        $log = $broadcaster->getMessageLog();
        $this->assertCount(2, $log);
        $this->assertSame($msg1, $log[0]);
        $this->assertSame($msg2, $log[1]);
    }

    #[Test]
    public function log_is_empty_when_keep_log_is_false(): void
    {
        $broadcaster = new SseBroadcaster(keepLog: false);

        $broadcaster->broadcast(new BroadcastMessage(channel: 'a', event: 'e1'));

        $this->assertCount(0, $broadcaster->getMessageLog());
    }

    #[Test]
    public function clear_log_empties_message_log(): void
    {
        $broadcaster = new SseBroadcaster(keepLog: true);

        $broadcaster->broadcast(new BroadcastMessage(channel: 'a', event: 'e1'));
        $this->assertCount(1, $broadcaster->getMessageLog());

        $broadcaster->clearLog();
        $this->assertCount(0, $broadcaster->getMessageLog());
    }

    #[Test]
    public function clear_subscribers_removes_all_subscriptions(): void
    {
        $broadcaster = new SseBroadcaster();

        $broadcaster->subscribe('admin.node', fn() => null);
        $broadcaster->subscribe('system', fn() => null);

        $this->assertCount(2, $broadcaster->getSubscribedChannels());

        $broadcaster->clearSubscribers();

        $this->assertCount(0, $broadcaster->getSubscribedChannels());
    }

    #[Test]
    public function broadcast_without_subscribers_does_not_throw(): void
    {
        $broadcaster = new SseBroadcaster();

        // Should not throw.
        $broadcaster->broadcast(new BroadcastMessage(channel: 'nobody', event: 'ignored'));

        $this->assertTrue(true); // Assert we got here without exception.
    }
}
