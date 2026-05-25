<?php

declare(strict_types=1);

namespace Waaseyaa\Api\MercureMonitor;

/**
 * Value object representing one row from the `_broadcast_log` table.
 *
 * @api
 */
final readonly class BroadcastEventRow
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public int $id,
        public string $channel,
        public string $event,
        public array $data,
        public float $createdAt,
    ) {}
}
