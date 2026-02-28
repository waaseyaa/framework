<?php

declare(strict_types=1);

namespace Aurora\Queue\Attribute;

/**
 * Specifies which named queue a job should be dispatched to.
 *
 * When applied to a Job subclass, queue processors should route
 * the job to the designated queue name instead of the default.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class OnQueue
{
    /**
     * @param string $name The queue name to dispatch the job to.
     */
    public function __construct(
        public readonly string $name,
    ) {}
}
