<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Migration;

final class ColumnDefinition
{
    private bool $isNullable = false;
    private mixed $defaultValue = null;
    private bool $hasDefault = false;
    private bool $isUnique = false;

    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly ?int $length = null,
    ) {}

    public function nullable(): self
    {
        $this->isNullable = true;
        return $this;
    }

    public function default(mixed $value): self
    {
        $this->defaultValue = $value;
        $this->hasDefault = true;
        return $this;
    }

    public function unique(): self
    {
        $this->isUnique = true;
        return $this;
    }

    public function isNullable(): bool
    {
        return $this->isNullable;
    }
    public function hasDefaultValue(): bool
    {
        return $this->hasDefault;
    }
    public function getDefaultValue(): mixed
    {
        return $this->defaultValue;
    }
    public function isUnique(): bool
    {
        return $this->isUnique;
    }
}
