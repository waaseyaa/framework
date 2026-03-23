<?php

declare(strict_types=1);

namespace Waaseyaa\Queue;

use Waaseyaa\Queue\Transport\TransportInterface;

/**
 * Queue implementation backed by a persistent transport.
 *
 * Serializes messages and pushes them to the transport for
 * later processing by a Worker.
 */
final class DbalQueue implements QueueInterface
{
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly string $defaultQueue = 'default',
    ) {}

    public function dispatch(object $message): void
    {
        $queue = $this->resolveQueue($message);
        $delay = $this->resolveDelay($message);
        $payload = serialize($message);

        $this->transport->push($queue, $payload, $delay);
    }

    private function resolveQueue(object $message): string
    {
        $ref = new \ReflectionClass($message);
        $attributes = $ref->getAttributes(Attribute\OnQueue::class);

        if ($attributes !== []) {
            $onQueue = $attributes[0]->newInstance();

            return $onQueue->name;
        }

        return $this->defaultQueue;
    }

    private function resolveDelay(object $message): int
    {
        if ($message instanceof Job && $message->isReleased()) {
            return $message->getReleaseDelay();
        }

        return 0;
    }
}
