<?php

declare(strict_types=1);

namespace Waaseyaa\Cache;

/** Explicit dormant/enforced switch for entity-bearing cache writes. @api */
final readonly class EntityPayloadBoundaryConfig
{
    private function __construct(public bool $rejectEntityPayloads) {}

    public static function dormant(): self
    {
        return new self(false);
    }
    public static function enforced(): self
    {
        return new self(true);
    }
}
