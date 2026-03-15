<?php

declare(strict_types=1);

namespace Waaseyaa\TypedData\Type;

use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Waaseyaa\TypedData\ComplexDataInterface;
use Waaseyaa\TypedData\DataDefinitionInterface;
use Waaseyaa\TypedData\TypedDataInterface;
use Waaseyaa\TypedData\TypedDataManagerInterface;

final class MapData implements ComplexDataInterface, \IteratorAggregate, \Countable
{
    /** @var array<string, TypedDataInterface> */
    private array $properties = [];

    /** @var array<string, DataDefinitionInterface> */
    private array $propertyDefinitions = [];

    public function __construct(
        private readonly DataDefinitionInterface $definition,
        private readonly TypedDataManagerInterface $manager,
    ) {}

    public function getValue(): mixed
    {
        return $this->toArray();
    }

    public function setValue(mixed $value): void
    {
        if (!is_array($value)) {
            return;
        }

        foreach ($value as $name => $itemValue) {
            $this->set((string) $name, $itemValue);
        }
    }

    public function getDataDefinition(): DataDefinitionInterface
    {
        return $this->definition;
    }

    public function validate(): ConstraintViolationListInterface
    {
        $violations = new ConstraintViolationList();

        foreach ($this->properties as $property) {
            $violations->addAll($property->validate());
        }

        return $violations;
    }

    public function getString(): string
    {
        $parts = [];
        foreach ($this->properties as $name => $property) {
            $parts[] = $name . ': ' . $property->getString();
        }

        return implode(', ', $parts);
    }

    public function get(string $name): TypedDataInterface
    {
        if (!isset($this->properties[$name])) {
            throw new \InvalidArgumentException(sprintf('Property "%s" does not exist.', $name));
        }

        return $this->properties[$name];
    }

    public function set(string $name, mixed $value): static
    {
        if ($value instanceof TypedDataInterface) {
            $this->properties[$name] = $value;
            $this->propertyDefinitions[$name] = $value->getDataDefinition();

            return $this;
        }

        if (isset($this->properties[$name])) {
            $this->properties[$name]->setValue($value);

            return $this;
        }

        // Auto-detect type for new properties.
        $dataType = match (true) {
            is_int($value) => 'integer',
            is_float($value) => 'float',
            is_bool($value) => 'boolean',
            is_string($value) => 'string',
            default => 'string',
        };

        $item = $this->manager->createInstance($dataType);
        $item->setValue($value);
        $this->properties[$name] = $item;
        $this->propertyDefinitions[$name] = $item->getDataDefinition();

        return $this;
    }

    /** @return array<string, DataDefinitionInterface> */
    public function getProperties(): array
    {
        return $this->propertyDefinitions;
    }

    public function toArray(): array
    {
        $result = [];
        foreach ($this->properties as $name => $property) {
            $result[$name] = $property->getValue();
        }

        return $result;
    }

    public function count(): int
    {
        return count($this->properties);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->properties);
    }
}
