<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Fixtures;

use Waaseyaa\Entity\Storage\EntityQueryInterface;

/**
 * No-op EntityQuery — all chainable methods return $this, execute() returns [].
 *
 * @internal Test double for Relationship package tests.
 */
class NullEntityQuery implements EntityQueryInterface
{
    public function condition(string $field, mixed $value, string $operator = '='): static { return $this; }
    public function exists(string $field): static { return $this; }
    public function notExists(string $field): static { return $this; }
    public function sort(string $field, string $direction = 'ASC'): static { return $this; }
    public function range(int $offset, int $limit): static { return $this; }
    public function count(): static { return $this; }
    public function accessCheck(bool $check = true): static { return $this; }
    public function execute(): array { return []; }
}
