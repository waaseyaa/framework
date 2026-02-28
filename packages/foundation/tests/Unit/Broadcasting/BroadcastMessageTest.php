<?php

declare(strict_types=1);

namespace Aurora\Foundation\Tests\Unit\Broadcasting;

use Aurora\Foundation\Broadcasting\BroadcastMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BroadcastMessage::class)]
final class BroadcastMessageTest extends TestCase
{
    #[Test]
    public function constructor_sets_all_properties(): void
    {
        $message = new BroadcastMessage(
            channel: 'admin.node',
            event: 'entity.saved',
            data: ['id' => '123', 'title' => 'Hello'],
            timestamp: 1709136000.5,
        );

        $this->assertSame('admin.node', $message->channel);
        $this->assertSame('entity.saved', $message->event);
        $this->assertSame(['id' => '123', 'title' => 'Hello'], $message->data);
        $this->assertSame(1709136000.5, $message->timestamp);
    }

    #[Test]
    public function constructor_defaults_data_to_empty_array(): void
    {
        $message = new BroadcastMessage(
            channel: 'system',
            event: 'ping',
        );

        $this->assertSame([], $message->data);
    }

    #[Test]
    public function constructor_defaults_timestamp_to_current_time(): void
    {
        $before = microtime(true);
        $message = new BroadcastMessage(channel: 'test', event: 'created');
        $after = microtime(true);

        $this->assertGreaterThanOrEqual($before, $message->timestamp);
        $this->assertLessThanOrEqual($after, $message->timestamp);
    }

    #[Test]
    public function to_array_returns_complete_structure(): void
    {
        $message = new BroadcastMessage(
            channel: 'admin.node',
            event: 'entity.saved',
            data: ['id' => '42'],
            timestamp: 1709136000.5,
        );

        $expected = [
            'channel' => 'admin.node',
            'event' => 'entity.saved',
            'data' => ['id' => '42'],
            'timestamp' => 1709136000.5,
        ];

        $this->assertSame($expected, $message->toArray());
    }

    #[Test]
    public function to_json_returns_valid_json(): void
    {
        $message = new BroadcastMessage(
            channel: 'system',
            event: 'config.changed',
            data: ['key' => 'site_name'],
            timestamp: 1709136000.123,
        );

        $json = $message->toJson();
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        $this->assertSame('system', $decoded['channel']);
        $this->assertSame('config.changed', $decoded['event']);
        $this->assertSame(['key' => 'site_name'], $decoded['data']);
        $this->assertSame(1709136000.123, $decoded['timestamp']);
    }

    #[Test]
    public function to_sse_frame_formats_correctly(): void
    {
        $message = new BroadcastMessage(
            channel: 'admin.node',
            event: 'entity.saved',
            data: ['id' => '1'],
            timestamp: 1709136000.5,
        );

        $frame = $message->toSseFrame();

        // Should start with "event: " line.
        $this->assertStringStartsWith("event: entity.saved\n", $frame);

        // Should contain "data: " line with JSON.
        $this->assertStringContainsString("data: ", $frame);

        // Should end with double newline (SSE terminator).
        $this->assertStringEndsWith("\n\n", $frame);

        // Extract the JSON from the data line.
        $lines = explode("\n", trim($frame));
        $this->assertCount(2, $lines);
        $this->assertSame('event: entity.saved', $lines[0]);
        $this->assertStringStartsWith('data: ', $lines[1]);

        $dataJson = substr($lines[1], 6); // strip "data: "
        $decoded = json_decode($dataJson, true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('admin.node', $decoded['channel']);
    }

    #[Test]
    public function message_is_readonly(): void
    {
        $message = new BroadcastMessage(
            channel: 'test',
            event: 'created',
            data: ['key' => 'value'],
            timestamp: 1709136000.5,
        );

        $reflection = new \ReflectionClass($message);
        $this->assertTrue($reflection->isReadOnly());
    }
}
