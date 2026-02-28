<?php

declare(strict_types=1);

namespace Aurora\Foundation\Broadcasting;

/**
 * Broadcasts messages to subscribed channels.
 *
 * Implementations may use SSE, WebSockets, Redis Pub/Sub, or any other
 * transport mechanism. The interface decouples event producers from the
 * delivery mechanism.
 */
interface BroadcasterInterface
{
    /**
     * Broadcast a message to all subscribers of its channel.
     */
    public function broadcast(BroadcastMessage $message): void;

    /**
     * Subscribe to a channel with a callback that receives each message.
     *
     * @param string   $channel  The channel name to subscribe to.
     * @param \Closure $callback Receives a BroadcastMessage when one is published.
     */
    public function subscribe(string $channel, \Closure $callback): void;

    /**
     * Get all channels that currently have subscribers.
     *
     * @return list<string>
     */
    public function getSubscribedChannels(): array;
}
