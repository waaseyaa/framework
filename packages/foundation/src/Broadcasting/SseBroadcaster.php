<?php

declare(strict_types=1);

namespace Aurora\Foundation\Broadcasting;

/**
 * In-memory broadcaster that delivers messages via Server-Sent Events (SSE).
 *
 * Subscribers register closures per channel. When a message is broadcast,
 * all matching channel callbacks are invoked synchronously. This is designed
 * for single-process SSE streaming where the web server holds an open
 * connection per client.
 *
 * For multi-process/multi-server setups, wrap this with a Redis or database
 * backed broadcaster that fans out to per-process SseBroadcaster instances.
 */
final class SseBroadcaster implements BroadcasterInterface
{
    /**
     * @var array<string, list<\Closure>>
     */
    private array $subscribers = [];

    /**
     * @var list<BroadcastMessage>
     */
    private array $messageLog = [];

    /**
     * @param bool $keepLog Whether to retain broadcast messages in memory (useful for testing).
     */
    public function __construct(
        private readonly bool $keepLog = false,
    ) {}

    public function broadcast(BroadcastMessage $message): void
    {
        if ($this->keepLog) {
            $this->messageLog[] = $message;
        }

        $channel = $message->channel;

        if (!isset($this->subscribers[$channel])) {
            return;
        }

        foreach ($this->subscribers[$channel] as $callback) {
            $callback($message);
        }
    }

    public function subscribe(string $channel, \Closure $callback): void
    {
        $this->subscribers[$channel] ??= [];
        $this->subscribers[$channel][] = $callback;
    }

    /**
     * @return list<string>
     */
    public function getSubscribedChannels(): array
    {
        return array_values(array_keys($this->subscribers));
    }

    /**
     * Check if a specific channel has any subscribers.
     */
    public function hasSubscribers(string $channel): bool
    {
        return isset($this->subscribers[$channel]) && \count($this->subscribers[$channel]) > 0;
    }

    /**
     * Get the number of subscribers on a given channel.
     */
    public function subscriberCount(string $channel): int
    {
        return \count($this->subscribers[$channel] ?? []);
    }

    /**
     * Get all logged messages (only available when $keepLog is true).
     *
     * @return list<BroadcastMessage>
     */
    public function getMessageLog(): array
    {
        return $this->messageLog;
    }

    /**
     * Clear the in-memory message log.
     */
    public function clearLog(): void
    {
        $this->messageLog = [];
    }

    /**
     * Remove all subscribers from all channels.
     */
    public function clearSubscribers(): void
    {
        $this->subscribers = [];
    }
}
