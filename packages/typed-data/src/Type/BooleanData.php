<?php

declare(strict_types=1);

namespace Waaseyaa\TypedData\Type;

use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Waaseyaa\TypedData\DataDefinitionInterface;
use Waaseyaa\TypedData\PrimitiveInterface;

final class BooleanData implements PrimitiveInterface
{
    private static ?ValidatorInterface $validator = null;

    private mixed $value;

    public function __construct(
        private readonly DataDefinitionInterface $definition,
        mixed $value = null,
    ) {
        $this->value = $value;
    }

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
        return $this->definition;
    }

    public function validate(): ConstraintViolationListInterface
    {
        self::$validator ??= Validation::createValidator();

        return self::$validator->validate($this->value, $this->definition->getConstraints());
    }

    public function getString(): string
    {
        if ($this->value === null) {
            return '';
        }

        return $this->value ? '1' : '0';
    }

    public function getCastedValue(): string|int|float|bool|null
    {
        if ($this->value === null) {
            return null;
        }

        return (bool) $this->value;
    }
}
