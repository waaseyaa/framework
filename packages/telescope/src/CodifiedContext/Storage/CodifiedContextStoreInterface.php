<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\CodifiedContext\Storage;

use Waaseyaa\Telescope\CodifiedContext\CodifiedContextEntry;
use Waaseyaa\Telescope\Storage\TelescopeStoreInterface;

/**
 * Storage interface for codified context entries with domain-specific query methods.
 */
interface CodifiedContextStoreInterface extends TelescopeStoreInterface
{
    /**
     * Query entries by session ID.
     *
     * @return CodifiedContextEntry[]
     */
    public function queryBySession(string $sessionId, int $limit = 100, int $offset = 0): array;

    /**
     * Query entries by event type (stored in data.event_type).
     *
     * @return CodifiedContextEntry[]
     */
    public function queryByEventType(string $eventType, int $limit = 50, int $offset = 0): array;

    /**
     * Query entries within a time range.
     *
     * @return CodifiedContextEntry[]
     */
    public function queryByTimeRange(\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 100): array;

    /**
     * Query entries by drift severity (stored in data.severity).
     *
     * @return CodifiedContextEntry[]
     */
    public function queryByDriftSeverity(string $severity, int $limit = 50, int $offset = 0): array;
}
