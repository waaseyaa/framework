<?php

declare(strict_types=1);

namespace Aurora\Api\Controller;

use Aurora\Foundation\Broadcasting\BroadcasterInterface;
use Aurora\Foundation\Broadcasting\BroadcastMessage;

/**
 * SSE endpoint controller for real-time broadcasting.
 *
 * Provides a `GET /api/broadcast` endpoint that:
 * 1. Opens an SSE stream to the client.
 * 2. Subscribes to requested channels on the broadcaster.
 * 3. Sends keepalive comments at a configurable interval.
 * 4. Streams BroadcastMessages as SSE events.
 *
 * Usage with a raw PHP server:
 *
 *     $controller = new BroadcastController($broadcaster);
 *     $controller->stream(['admin.node', 'system']);
 *
 * The controller is framework-agnostic: it writes directly to the output
 * buffer using a configurable output callback (defaults to `echo`).
 */
final class BroadcastController
{
    /**
     * @param BroadcasterInterface     $broadcaster       The broadcaster to subscribe to.
     * @param int                      $keepaliveInterval  Seconds between keepalive comments.
     * @param \Closure|null            $outputCallback     Callback to write SSE output. Receives a string. Defaults to echo.
     * @param \Closure|null            $flushCallback      Callback to flush output. Defaults to flush().
     */
    public function __construct(
        private readonly BroadcasterInterface $broadcaster,
        private readonly int $keepaliveInterval = 30,
        private ?\Closure $outputCallback = null,
        private ?\Closure $flushCallback = null,
    ) {
        $this->outputCallback ??= static function (string $data): void {
            echo $data;
        };
        $this->flushCallback ??= static function (): void {
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        };
    }

    /**
     * Build SSE response headers.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];
    }

    /**
     * Subscribe to channels and collect SSE frames.
     *
     * This is a non-blocking variant that subscribes to channels on the
     * broadcaster and returns the subscription list. Messages are delivered
     * when the broadcaster's broadcast() method is called.
     *
     * @param list<string> $channels Channel names to subscribe to.
     */
    public function subscribe(array $channels): void
    {
        foreach ($channels as $channel) {
            $this->broadcaster->subscribe($channel, function (BroadcastMessage $message): void {
                $this->sendMessage($message);
            });
        }
    }

    /**
     * Send an SSE message to the client.
     */
    public function sendMessage(BroadcastMessage $message): void
    {
        ($this->outputCallback)($message->toSseFrame());
        ($this->flushCallback)();
    }

    /**
     * Send an SSE keepalive comment.
     *
     * The SSE spec allows lines starting with ":" as comments,
     * which keep the connection alive without triggering client events.
     */
    public function sendKeepalive(): void
    {
        ($this->outputCallback)(": keepalive\n\n");
        ($this->flushCallback)();
    }

    /**
     * Send the initial SSE connection event.
     *
     * @param list<string> $channels The channels the client is subscribed to.
     */
    public function sendConnected(array $channels): void
    {
        $message = new BroadcastMessage(
            channel: 'system',
            event: 'connected',
            data: ['channels' => $channels],
        );
        $this->sendMessage($message);
    }

    /**
     * Run the SSE stream loop.
     *
     * This method blocks and continuously checks for messages, sending
     * keepalives at the configured interval. It runs until $shouldStop
     * returns true or the connection is aborted.
     *
     * @param list<string>  $channels   Channel names to subscribe to.
     * @param \Closure|null $shouldStop Returns true to terminate the loop. Defaults to connection_aborted().
     * @param \Closure|null $tick       Called each iteration. Useful for testing or integrating with an event loop.
     */
    public function stream(
        array $channels,
        ?\Closure $shouldStop = null,
        ?\Closure $tick = null,
    ): void {
        $shouldStop ??= static fn(): bool => connection_aborted() === 1;

        $this->subscribe($channels);
        $this->sendConnected($channels);

        $lastKeepalive = time();

        while (!$shouldStop()) {
            if ($tick !== null) {
                $tick();
            }

            $now = time();
            if (($now - $lastKeepalive) >= $this->keepaliveInterval) {
                $this->sendKeepalive();
                $lastKeepalive = $now;
            }

            // Yield to other processes — in a real server this would be
            // replaced by an event loop or blocking read on a pub/sub channel.
            usleep(50_000); // 50ms
        }
    }

    /**
     * Parse channel names from a query string parameter.
     *
     * @param string $channelsParam Comma-separated channel names (e.g., "admin.node,system").
     *
     * @return list<string>
     */
    public static function parseChannels(string $channelsParam): array
    {
        if ($channelsParam === '') {
            return [];
        }

        $channels = array_map('trim', explode(',', $channelsParam));

        return array_values(array_filter($channels, static fn(string $ch): bool => $ch !== ''));
    }

    /**
     * Get the configured keepalive interval in seconds.
     */
    public function getKeepaliveInterval(): int
    {
        return $this->keepaliveInterval;
    }
}
