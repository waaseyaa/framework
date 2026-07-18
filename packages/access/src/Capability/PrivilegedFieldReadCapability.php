<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Capability;

/**
 * Empty opaque handle. Registry membership, not object construction or object
 * contents, establishes authority.
 *
 * @api
 */
final class PrivilegedFieldReadCapability
{
    public function __serialize(): array
    {
        throw new \LogicException('Field-read capabilities cannot be serialized.');
    }
}
