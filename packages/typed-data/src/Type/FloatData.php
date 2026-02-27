<?php

declare(strict_types=1);

namespace Aurora\TypedData\Type;

use Aurora\TypedData\DataDefinitionInterface;
use Aurora\TypedData\PrimitiveInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class FloatData implements PrimitiveInterface
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

        return (string) (float) $this->value;
    }

    public function getCastedValue(): string|int|float|bool|null
    {
        if ($this->value === null) {
            return null;
        }

        return (float) $this->value;
    }
}
