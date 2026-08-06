<?php

declare(strict_types=1);

namespace Waaseyaa\Testing\Clock;

use Waaseyaa\Entity\DateTime\EntityClockInterface;

/**
 * Deterministic clock whose value moves only when the test asks it to.
 *
 * Use the production FixedEntityClock when time never changes during a test.
 *
 * @api
 */
final class MutableEntityClock implements EntityClockInterface
{
    public function __construct(
        private \DateTimeImmutable $current,
    ) {}

    public function now(): \DateTimeImmutable
    {
        return $this->current;
    }

    public function set(\DateTimeImmutable $current): void
    {
        $this->current = $current;
    }

    public function advance(\DateInterval $interval): void
    {
        $this->current = $this->current->add($interval);
    }
}
