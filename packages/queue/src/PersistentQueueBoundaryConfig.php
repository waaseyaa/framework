<?php

declare(strict_types=1);

namespace Waaseyaa\Queue;

/** Explicit dormant/enforced switch for persistent queue activation blockers. @api */
final readonly class PersistentQueueBoundaryConfig
{
    private function __construct(
        public bool $rejectEntityPayloads,
        public bool $requireAuthorityEnvelope,
    ) {}

    public static function dormant(): self
    {
        return new self(false, false);
    }
    public static function enforced(): self
    {
        return new self(true, true);
    }
}
