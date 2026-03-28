<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Fixtures;

use Waaseyaa\Entity\Storage\EntityQueryInterface;

/**
 * Returns preconfigured result sets on successive execute() calls.
 *
 * Usage:
 *   new FixedResultEntityQuery([[1, 2], [3, 4]])
 *   // First execute() returns [1, 2], second returns [3, 4], subsequent return []
 *
 * @internal Test double for Relationship package tests.
 */
class FixedResultEntityQuery implements EntityQueryInterface
{
    private int $callCount = 0;

    /** @param list<array<int|string>> $resultSets Each element is one execute() result */
    public function __construct(private readonly array $resultSets) {}

    public function condition(string $field, mixed $value, string $operator = '='): static { return $this; }
    public function exists(string $field): static { return $this; }
    public function notExists(string $field): static { return $this; }
    public function sort(string $field, string $direction = 'ASC'): static { return $this; }
    public function range(int $offset, int $limit): static { return $this; }
    public function count(): static { return $this; }
    public function accessCheck(bool $check = true): static { return $this; }

    public function execute(): array
    {
        $index = $this->callCount++;

        return $this->resultSets[$index] ?? [];
    }
}
