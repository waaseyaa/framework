<?php

declare(strict_types=1);

namespace Aurora\Entity;

interface FieldableInterface
{
    public function hasField(string $name): bool;

    public function get(string $name): mixed;

    public function set(string $name, mixed $value): static;

    /** @return array<string, mixed> Field definitions keyed by field name */
    public function getFieldDefinitions(): array;
}
