<?php

declare(strict_types=1);

namespace Waaseyaa\Api\MercureMonitor;

/**
 * Value object representing one active SSE subscriber.
 *
 * Security: MUST NOT include session tokens, raw IPs, User-Agent strings,
 * or any 64-char hex values (NFR-004 / DIR-006 identity-leak guard).
 *
 * @api
 */
final readonly class SubscriberRow
{
    /**
     * @param list<string> $channels
     */
    public function __construct(
        public int $accountId,
        public ?string $accountLabel,
        public array $channels,
        public float $connectedSince,
    ) {}
}
