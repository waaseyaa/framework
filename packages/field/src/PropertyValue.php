<?php

declare(strict_types=1);

namespace Waaseyaa\Field;

use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Waaseyaa\TypedData\DataDefinition;
use Waaseyaa\TypedData\DataDefinitionInterface;
use Waaseyaa\TypedData\TypedDataInterface;

/**
 * Simple typed data wrapper for a single property value within a field item.
 */
final class PropertyValue implements TypedDataInterface
{
    public function __construct(
        private readonly string $name,
        private mixed $value = null,
    ) {}

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function setValue(mixed $value): void
    {
        $this->value = $value;
    }

    public function getDataDefinition(): DataDefinitionInterface
    {
        return new DataDefinition(
            dataType: 'property',
            label: $this->name,
        );
    }

    public function validate(): ConstraintViolationListInterface
    {
        return new ConstraintViolationList();
    }

    public function getString(): string
    {
        if ($this->value === null) {
            return '';
        }

        return (string) $this->value;
    }
}
