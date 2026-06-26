<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

interface EntityInterface
{
    public function id(): int|string|null;

    public function uuid(): string;

    public function label(): string;

    public function getEntityTypeId(): string;

    public function bundle(): string;

    public function isNew(): bool;

    public function get(string $name): mixed;

    public function set(string $name, mixed $value): static;

    public function toArray(): array;

    public function language(): string;
}
