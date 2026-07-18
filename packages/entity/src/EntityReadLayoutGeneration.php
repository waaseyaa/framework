<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

/** Process-local authoritative entity read-layout generation. @api */
final class EntityReadLayoutGeneration
{
    private int $generation = 1;

    public function current(): int
    {
        return $this->generation;
    }

    /** Registration is a process boundary; previously sealed entities become unreadable. */
    public function advance(): int
    {
        return ++$this->generation;
    }
}
