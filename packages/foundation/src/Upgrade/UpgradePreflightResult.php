<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Upgrade;

/**
 * Immutable, serialization-safe upgrade preflight result.
 *
 * @api
 */
final readonly class UpgradePreflightResult
{
    /**
     * @param list<string> $reasonCodes
     * @param list<string> $evaluatedGates
     */
    public function __construct(
        public UpgradePreflightDecision $decision,
        public array $reasonCodes,
        public array $evaluatedGates,
    ) {}
}
