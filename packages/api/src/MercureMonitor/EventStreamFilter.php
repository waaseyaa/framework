<?php

declare(strict_types=1);

namespace Waaseyaa\Api\MercureMonitor;

/**
 * Immutable filter for the recent-events read model.
 *
 * @api
 */
final readonly class EventStreamFilter
{
    /**
     * @param list<string> $channels
     */
    public function __construct(
        public array $channels = [],
        public ?string $event = null,
        public ?float $since = null,
    ) {}

    /**
     * Parse from raw HTTP query parameters.
     *
     * - `channels`: comma-separated list (e.g. `admin,realtime`)
     * - `event`: exact event name
     * - `since`: ISO 8601 datetime string (e.g. `2026-05-25T00:00:00Z`)
     *
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        $channels = [];
        if (isset($query['channels']) && is_string($query['channels']) && $query['channels'] !== '') {
            $channels = array_values(array_filter(array_map('trim', explode(',', $query['channels'])), static fn(string $s): bool => $s !== ''));
        }

        $event = null;
        if (isset($query['event']) && is_string($query['event']) && $query['event'] !== '') {
            $event = $query['event'];
        }

        $since = null;
        if (isset($query['since']) && is_string($query['since']) && $query['since'] !== '') {
            $ts = strtotime($query['since']);
            if ($ts !== false) {
                $since = (float) $ts;
            }
        }

        return new self(channels: $channels, event: $event, since: $since);
    }
}
