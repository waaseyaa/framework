<?php

declare(strict_types=1);

namespace Waaseyaa\Api\MercureMonitor;

/**
 * Value object representing a single channel's 24h statistics.
 *
 * @api
 */
final readonly class ChannelInspectorRow
{
    public function __construct(
        public string $channel,
        public int $eventCount24h,
        public ?float $lastEventAt,
        public ?string $lastEventName,
    ) {}
}
