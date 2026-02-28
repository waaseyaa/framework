<?php

declare(strict_types=1);

namespace Aurora\Api\Tests\Unit\Controller;

use Aurora\Api\Controller\BroadcastController;
use Aurora\Foundation\Broadcasting\BroadcastMessage;
use Aurora\Foundation\Broadcasting\SseBroadcaster;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BroadcastController::class)]
final class BroadcastControllerTest extends TestCase
{
    private SseBroadcaster $broadcaster;
    private string $output;

    protected function setUp(): void
    {
        $this->broadcaster = new SseBroadcaster(keepLog: true);
        $this->output = '';
    }

    private function createController(int $keepaliveInterval = 30): BroadcastController
    {
        return new BroadcastController(
            broadcaster: $this->broadcaster,
            keepaliveInterval: $keepaliveInterval,
            outputCallback: function (string $data): void {
                $this->output .= $data;
            },
            flushCallback: static function (): void {
                // No-op for testing.
            },
        );
    }

    #[Test]
    public function get_headers_returns_sse_headers(): void
    {
        $controller = $this->createController();

        $headers = $controller->getHeaders();

        $this->assertSame('text/event-stream', $headers['Content-Type']);
        $this->assertSame('no-cache', $headers['Cache-Control']);
        $this->assertSame('keep-alive', $headers['Connection']);
        $this->assertSame('no', $headers['X-Accel-Buffering']);
    }

    #[Test]
    public function subscribe_registers_on_broadcaster(): void
    {
        $controller = $this->createController();

        $controller->subscribe(['admin.node', 'system']);

        $this->assertTrue($this->broadcaster->hasSubscribers('admin.node'));
        $this->assertTrue($this->broadcaster->hasSubscribers('system'));
    }

    #[Test]
    public function send_message_writes_sse_frame(): void
    {
        $controller = $this->createController();

        $message = new BroadcastMessage(
            channel: 'admin.node',
            event: 'entity.saved',
            data: ['id' => '42'],
            timestamp: 1709136000.5,
        );

        $controller->sendMessage($message);

        $this->assertStringContainsString('event: entity.saved', $this->output);
        $this->assertStringContainsString('data: ', $this->output);
        $this->assertStringContainsString('"channel":"admin.node"', $this->output);
    }

    #[Test]
    public function send_keepalive_writes_comment(): void
    {
        $controller = $this->createController();

        $controller->sendKeepalive();

        $this->assertSame(": keepalive\n\n", $this->output);
    }

    #[Test]
    public function send_connected_writes_connected_event(): void
    {
        $controller = $this->createController();

        $controller->sendConnected(['admin.node', 'system']);

        $this->assertStringContainsString('event: connected', $this->output);
        $this->assertStringContainsString('"channels":["admin.node","system"]', $this->output);
    }

    #[Test]
    public function stream_sends_connected_and_subscribes(): void
    {
        $controller = $this->createController();
        $iterations = 0;

        $controller->stream(
            channels: ['admin.node', 'system'],
            shouldStop: static function () use (&$iterations): bool {
                $iterations++;
                return $iterations > 1; // Stop after first iteration.
            },
            tick: static function (): void {
                // Override usleep for fast testing.
            },
        );

        // Should have sent connected event.
        $this->assertStringContainsString('event: connected', $this->output);
        $this->assertStringContainsString('"channels":["admin.node","system"]', $this->output);

        // Should have subscribed to channels.
        $this->assertTrue($this->broadcaster->hasSubscribers('admin.node'));
        $this->assertTrue($this->broadcaster->hasSubscribers('system'));
    }

    #[Test]
    public function stream_delivers_broadcast_messages(): void
    {
        $controller = $this->createController();
        $iterations = 0;

        $controller->stream(
            channels: ['admin.node'],
            shouldStop: function () use (&$iterations): bool {
                $iterations++;
                if ($iterations === 2) {
                    // Broadcast a message during the loop.
                    $this->broadcaster->broadcast(new BroadcastMessage(
                        channel: 'admin.node',
                        event: 'entity.created',
                        data: ['id' => '99'],
                        timestamp: 1709136000.5,
                    ));
                }
                return $iterations > 2;
            },
            tick: static function (): void {},
        );

        $this->assertStringContainsString('event: entity.created', $this->output);
        $this->assertStringContainsString('"id":"99"', $this->output);
    }

    #[Test]
    public function stream_sends_keepalive_after_interval(): void
    {
        // Use a keepalive interval of 0 so it triggers immediately.
        $controller = $this->createController(keepaliveInterval: 0);
        $iterations = 0;

        $controller->stream(
            channels: ['test'],
            shouldStop: static function () use (&$iterations): bool {
                $iterations++;
                return $iterations > 1;
            },
            tick: static function (): void {},
        );

        $this->assertStringContainsString(': keepalive', $this->output);
    }

    #[Test]
    public function parse_channels_splits_comma_separated(): void
    {
        $channels = BroadcastController::parseChannels('admin.node,system,pipeline.123');

        $this->assertSame(['admin.node', 'system', 'pipeline.123'], $channels);
    }

    #[Test]
    public function parse_channels_trims_whitespace(): void
    {
        $channels = BroadcastController::parseChannels(' admin.node , system ');

        $this->assertSame(['admin.node', 'system'], $channels);
    }

    #[Test]
    public function parse_channels_returns_empty_for_empty_string(): void
    {
        $channels = BroadcastController::parseChannels('');

        $this->assertSame([], $channels);
    }

    #[Test]
    public function parse_channels_filters_empty_segments(): void
    {
        $channels = BroadcastController::parseChannels('admin.node,,system,');

        $this->assertSame(['admin.node', 'system'], $channels);
    }

    #[Test]
    public function get_keepalive_interval_returns_configured_value(): void
    {
        $controller = $this->createController(keepaliveInterval: 45);

        $this->assertSame(45, $controller->getKeepaliveInterval());
    }
}
