<?php

declare(strict_types=1);

namespace Aurora\Telescope\Recorder;

use Aurora\Telescope\Storage\TelescopeStoreInterface;

/**
 * Records dispatched events with class name, payload, and listeners.
 */
final class EventRecorder
{
    public const string TYPE = 'event';

    public function __construct(
        private readonly TelescopeStoreInterface $store,
    ) {}

    /**
     * Record a dispatched event.
     *
     * @param string $eventClass The fully-qualified class name of the event.
     * @param array<string, mixed> $payload Serializable event payload.
     * @param string[] $listeners List of listener identifiers that will handle this event.
     */
    public function record(
        string $eventClass,
        array $payload,
        array $listeners = [],
    ): void {
        $this->store->store(self::TYPE, [
            'event' => $eventClass,
            'payload' => $payload,
            'listeners' => $listeners,
            'timestamp' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        ]);
    }
}
