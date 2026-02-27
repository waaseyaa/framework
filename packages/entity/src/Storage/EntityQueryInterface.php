<?php

declare(strict_types=1);

namespace Aurora\Entity\Storage;

interface EntityQueryInterface
{
    public function condition(string $field, mixed $value, string $operator = '='): static;

    public function exists(string $field): static;

    public function notExists(string $field): static;

    public function sort(string $field, string $direction = 'ASC'): static;

    public function range(int $offset, int $limit): static;

    public function count(): static;

    public function accessCheck(bool $check = true): static;

    /**
     * Execute the query and return entity IDs.
     *
     * @return array<int|string>
     */
    public function execute(): array;
}
