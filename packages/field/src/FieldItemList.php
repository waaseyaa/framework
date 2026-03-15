<?php

declare(strict_types=1);

namespace Waaseyaa\Field;

use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Waaseyaa\TypedData\DataDefinitionInterface;
use Waaseyaa\TypedData\TypedDataInterface;

class FieldItemList implements FieldItemListInterface, \IteratorAggregate, \Countable
{
    /** @var FieldItemInterface[] */
    protected array $items = [];

    public function __construct(
        protected readonly FieldDefinitionInterface $fieldDefinition,
    ) {}

    public function getFieldDefinition(): FieldDefinitionInterface
    {
        return $this->fieldDefinition;
    }

    public function __get(string $name): mixed
    {
        $first = $this->first();
        if ($first === null) {
            return null;
        }

        return $first->get($name)->getValue();
    }

    // ListInterface methods

    public function get(int $index): TypedDataInterface
    {
        if (!isset($this->items[$index])) {
            throw new \OutOfBoundsException("Item at index $index does not exist.");
        }

        return $this->items[$index];
    }

    public function set(int $index, mixed $value): void
    {
        if ($value instanceof FieldItemInterface) {
            $this->items[$index] = $value;
        } else {
            if (!isset($this->items[$index])) {
                throw new \OutOfBoundsException("Item at index $index does not exist.");
            }
            $this->items[$index]->setValue($value);
        }
    }

    public function first(): ?TypedDataInterface
    {
        return $this->items[0] ?? null;
    }

    public function isEmpty(): bool
    {
        if (count($this->items) === 0) {
            return true;
        }

        foreach ($this->items as $item) {
            if (!$item->isEmpty()) {
                return false;
            }
        }

        return true;
    }

    public function appendItem(mixed $value = null): TypedDataInterface
    {
        if ($value instanceof FieldItemInterface) {
            $this->items[] = $value;

            return $value;
        }

        throw new \InvalidArgumentException('appendItem expects a FieldItemInterface instance.');
    }

    public function removeItem(int $index): void
    {
        if (!isset($this->items[$index])) {
            throw new \OutOfBoundsException("Item at index $index does not exist.");
        }

        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    // TypedDataInterface methods

    public function getValue(): mixed
    {
        $values = [];
        foreach ($this->items as $item) {
            $values[] = $item->toArray();
        }

        return $values;
    }

    public function setValue(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $index => $itemValue) {
                if (isset($this->items[$index])) {
                    $this->items[$index]->setValue($itemValue);
                }
            }
        }
    }

    public function getDataDefinition(): DataDefinitionInterface
    {
        return $this->fieldDefinition;
    }

    public function validate(): ConstraintViolationListInterface
    {
        return new ConstraintViolationList();
    }

    public function getString(): string
    {
        $strings = [];
        foreach ($this->items as $item) {
            $string = $item->getString();
            if ($string !== '') {
                $strings[] = $string;
            }
        }

        return implode(', ', $strings);
    }

    // Countable

    public function count(): int
    {
        return count($this->items);
    }

    // IteratorAggregate

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }
}
