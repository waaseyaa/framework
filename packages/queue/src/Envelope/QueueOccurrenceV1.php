<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Envelope;

/** Immutable scheduler occurrence identity carried by a persistent queue delivery. @api */
final readonly class QueueOccurrenceV1
{
    public function __construct(
        public string $occurrenceId,
        public string $taskName,
        public string $scheduleGeneration,
        public int $leaseTtlMs,
    ) {
        if (
            preg_match('/^[a-f0-9]{64}$/D', $occurrenceId) !== 1
            || $taskName === ''
            || preg_match('/^[a-f0-9]{64}$/D', $scheduleGeneration) !== 1
            || $leaseTtlMs < 1_000
        ) {
            throw new \InvalidArgumentException('Queue occurrence identity requires SHA-256 IDs, a task name, and a lease TTL of at least one second.');
        }
    }
}
