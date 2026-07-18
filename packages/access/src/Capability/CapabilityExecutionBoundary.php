<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Capability;

/**
 * Opaque proof of one live execution boundary.
 *
 * Constructing an object does not grant authority: only registry-owned object
 * identity is accepted. The correlation id is metadata, not a credential.
 *
 * @api
 */
final readonly class CapabilityExecutionBoundary
{
    public function __construct(public string $correlationId)
    {
        if ($correlationId === '') {
            throw new \InvalidArgumentException('Capability execution boundary requires a correlation id.');
        }
    }

    public function __serialize(): array
    {
        throw new \LogicException('Capability execution boundaries cannot be serialized.');
    }
}
