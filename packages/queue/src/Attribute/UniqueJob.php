<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Attribute;

/**
 * Marks a job as unique to prevent duplicate dispatch within a single process.
 *
 * **IMPORTANT — in-process / SyncQueue-only. NOT enforced by DbalQueue.**
 *
 * This attribute is evaluated by {@see \Waaseyaa\Queue\AttributeGuard}, which
 * performs pure in-memory, per-process tracking. It is enforced by
 * {@see \Waaseyaa\Queue\SyncQueue} (same-process execution) and has NO effect
 * when jobs are dispatched through {@see \Waaseyaa\Queue\DbalQueue} (the
 * persistent, transport-backed driver). Cross-process deduplication would
 * require a distributed lock store and is currently unimplemented.
 *
 * Using this attribute with DbalQueue will cause a warning to be logged at
 * dispatch time so that the no-op is non-silent.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class UniqueJob
{
    /**
     * @param int    $lockSeconds Number of seconds the uniqueness lock is held (in-process only).
     * @param string $key         Optional custom uniqueness key. If empty, the
     *                            job class name is used as the key.
     */
    public function __construct(
        public readonly int $lockSeconds = 3600,
        public readonly string $key = '',
    ) {}
}
