<?php

declare(strict_types=1);

namespace Aurora\Queue\Attribute;

/**
 * Marks a job as unique to prevent duplicate dispatch.
 *
 * When applied to a Job subclass, queue processors should ensure
 * only one instance of this job (identified by its class name and
 * optional key) is queued at any time.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class UniqueJob
{
    /**
     * @param int    $lockSeconds Number of seconds the uniqueness lock is held.
     * @param string $key         Optional custom uniqueness key. If empty, the
     *                            job class name is used as the key.
     */
    public function __construct(
        public readonly int $lockSeconds = 3600,
        public readonly string $key = '',
    ) {}
}
