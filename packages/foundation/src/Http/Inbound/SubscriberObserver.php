<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Inbound;

use Waaseyaa\Api\MercureMonitor\SubscriberObserverInterface;
use Waaseyaa\Api\MercureMonitor\SubscriberRow;

/**
 * Foundation adapter for `SubscriberObserverInterface`.
 *
 * Reads `subscribers.json` written by `BroadcastRouter` on connect/disconnect.
 *
 * - Malformed file → empty array, never fatal (FR-004).
 * - Stale rows (lastHeartbeat > 30s ago) are dropped (FR-004).
 * - No session tokens, IPs, or User-Agent strings exposed (NFR-004 / DIR-006).
 */
final class SubscriberObserver implements SubscriberObserverInterface
{
    private const STALE_THRESHOLD_SECONDS = 30;

    public function __construct(
        private readonly string $subscribersJsonPath,
    ) {}

    /**
     * @return list<SubscriberRow>
     */
    public function currentSubscribers(): array
    {
        if (!is_file($this->subscribersJsonPath)) {
            return [];
        }

        try {
            $raw = file_get_contents($this->subscribersJsonPath);
            if ($raw === false || $raw === '') {
                return [];
            }

            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                return [];
            }
        } catch (\JsonException) {
            return [];
        }

        $now = microtime(true);
        $threshold = $now - self::STALE_THRESHOLD_SECONDS;

        $result = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }

            // Drop stale entries
            if (!isset($row['lastHeartbeat']) || (float) $row['lastHeartbeat'] < $threshold) {
                continue;
            }

            $channels = [];
            if (isset($row['channels']) && is_array($row['channels'])) {
                foreach ($row['channels'] as $ch) {
                    if (is_string($ch)) {
                        $channels[] = $ch;
                    }
                }
            }

            $accountLabel = null;
            if (isset($row['accountLabel']) && is_string($row['accountLabel']) && $row['accountLabel'] !== '') {
                $accountLabel = $row['accountLabel'];
            }

            $result[] = new SubscriberRow(
                accountId: isset($row['accountId']) ? (int) $row['accountId'] : 0,
                accountLabel: $accountLabel,
                channels: $channels,
                connectedSince: isset($row['connectedSince']) ? (float) $row['connectedSince'] : $now,
            );
        }

        return $result;
    }
}
