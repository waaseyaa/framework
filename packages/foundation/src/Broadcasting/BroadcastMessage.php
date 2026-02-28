<?php

declare(strict_types=1);

namespace Aurora\Foundation\Broadcasting;

/**
 * Immutable value object representing a message to broadcast over SSE.
 *
 * Each message targets a specific channel, carries an event name and
 * arbitrary data payload, and records the time it was created.
 */
final readonly class BroadcastMessage
{
    public float $timestamp;

    /**
     * @param string               $channel   The broadcast channel (e.g., "admin.node", "pipeline.progress").
     * @param string               $event     The event name (e.g., "entity.saved", "step.completed").
     * @param array<string, mixed> $data      Arbitrary payload data.
     * @param float|null           $timestamp Unix timestamp with microseconds. Defaults to current time.
     */
    public function __construct(
        public string $channel,
        public string $event,
        public array $data = [],
        ?float $timestamp = null,
    ) {
        $this->timestamp = $timestamp ?? microtime(true);
    }

    /**
     * Serialize to an array suitable for JSON encoding.
     *
     * @return array{channel: string, event: string, data: array<string, mixed>, timestamp: float}
     */
    public function toArray(): array
    {
        return [
            'channel' => $this->channel,
            'event' => $this->event,
            'data' => $this->data,
            'timestamp' => $this->timestamp,
        ];
    }

    /**
     * Encode as a JSON string.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), \JSON_THROW_ON_ERROR);
    }

    /**
     * Format as an SSE data frame.
     *
     * Returns a string like:
     *   event: entity.saved
     *   data: {"channel":"admin.node","event":"entity.saved","data":{},"timestamp":1709136000.0}
     *
     * Terminated by a double newline as per the SSE spec.
     */
    public function toSseFrame(): string
    {
        return "event: {$this->event}\ndata: {$this->toJson()}\n\n";
    }
}
