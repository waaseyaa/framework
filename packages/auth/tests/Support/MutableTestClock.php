<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Support;

use Waaseyaa\Entity\DateTime\EntityClockInterface;

/**
 * Advanceable clock for auth token lifetime tests.
 *
 * `FixedEntityClock` cannot move, so it cannot express the case that matters
 * here: a token that is valid when it is read and expired when it is consumed.
 * Never used by runtime code.
 */
final class MutableTestClock implements EntityClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct(?\DateTimeImmutable $start = null)
    {
        $this->now = $start ?? new \DateTimeImmutable('2026-09-01 12:00:00', new \DateTimeZone('UTC'));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advanceSeconds(int $seconds): void
    {
        $this->now = $this->now->modify(sprintf('%+d seconds', $seconds));
    }
}
