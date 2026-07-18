<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

/** Explicit dormant/enforced switch for value-bearing entity serialization. @api */
final readonly class EntitySerializationBoundaryConfig
{
    private function __construct(public bool $rejectSerialization) {}

    public static function dormant(): self
    {
        return new self(false);
    }
    public static function enforced(): self
    {
        return new self(true);
    }
}
