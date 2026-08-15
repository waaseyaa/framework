<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Key;

use DateTimeImmutable;

/** Conservative CFG-04 retention and propagation arithmetic. @api */
final readonly class SigningKeyLifecyclePolicy
{
    public function __construct(
        private int $maximumTokenLifetimeSeconds = 7_776_000,
        private int $maximumClockSkewSeconds = 300,
        private int $jwksCacheLifetimeSeconds = 86_400,
        private int $propagationMarginSeconds = 300,
    ) {
        if ($this->maximumTokenLifetimeSeconds <= 0
            || $this->maximumClockSkewSeconds < 0
            || $this->jwksCacheLifetimeSeconds < 0
            || $this->propagationMarginSeconds < 0) {
            throw new \InvalidArgumentException('Signing-key lifecycle durations are invalid.');
        }
    }

    public function retentionSeconds(): int
    {
        return $this->maximumTokenLifetimeSeconds
            + $this->maximumClockSkewSeconds
            + $this->jwksCacheLifetimeSeconds
            + $this->propagationMarginSeconds;
    }

    public function effectivePropagationHorizonSeconds(): int
    {
        return $this->jwksCacheLifetimeSeconds + $this->propagationMarginSeconds;
    }

    public function jwksCacheLifetimeSeconds(): int
    {
        return $this->jwksCacheLifetimeSeconds;
    }

    public function retainUntil(DateTimeImmutable $rotatedAt): int
    {
        return $rotatedAt->getTimestamp() + $this->retentionSeconds();
    }
}
