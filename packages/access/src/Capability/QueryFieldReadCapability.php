<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Capability;

/** @api */
final class QueryFieldReadCapability
{
    public function __serialize(): array
    {
        throw new \LogicException('Query capabilities cannot be serialized.');
    }
}
