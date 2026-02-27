<?php

declare(strict_types=1);

namespace Aurora\TypedData\Type;

use Aurora\TypedData\DataDefinitionInterface;
use Aurora\TypedData\ListInterface;
use Aurora\TypedData\TypedDataInterface;
use Aurora\TypedData\TypedDataManagerInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

final class ListData implements ListInterface, \IteratorAggregate
{
    /** @var TypedDataInterface[] */
    private array $items = [];

    public function __construct(
        private readonly DataDefinitionInterface $definition,
        private readonly TypedDataManagerInterface $manager,
        private readonly string $itemType = 'string',
    ) {}

    public function getValue(): mixed
    {
        return array_map(
            static fn(TypedDataInterface $item): mixed => $item->getValue(),
            $this->items,
        );
    }

    public function setValue(mixed $value): void
    {
        if (!is_array($value)) {
            return;
        }

        $this->items = [];

        foreach ($value as $item) {
            $this->appendItem($item);
        }
    }

    public function getDataDefinition(): DataDefinitionInterface
    {
        return $this->definition;
    }

    public function validate(): ConstraintViolationListInterface
    {
        $violations = new ConstraintViolationList();

        foreach ($this->items as $item) {
            $violations->addAll($item->validate());
        }

        return $violations;
    }

    public function getString(): string
    {
        return implode(', ', array_map(
            static fn(TypedDataInterface $item): string => $item->getString(),
            $this->items,
        ));
    }

    public function get(int $index): TypedDataInterface
    {
        if (!isset($this->items[$index])) {
            throw new \OutOfRangeException(sprintf('Index %d does not exist in the list.', $index));
        }

        return $this->items[$index];
    }

    public function set(int $index, mixed $value): void
    {
        if (!isset($this->items[$index])) {
            throw new \OutOfRangeException(sprintf('Index %d does not exist in the list.', $index));
        }

        if ($value instanceof TypedDataInterface) {
            $this->items[$index] = $value;
        } else {
            $this->items[$index]->setValue($value);
        }
    }

    public function first(): ?TypedDataInterface
    {
        if ($this->isEmpty()) {
            return null;
        }

        return $this->items[array_key_first($this->items)];
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function appendItem(mixed $value = null): TypedDataInterface
    {
        if ($value instanceof TypedDataInterface) {
            $this->items[] = $value;

            return $value;
        }

        $item = $this->manager->createInstance($this->itemType);
        $item->setValue($value);
        $this->items[] = $item;

        return $item;
    }

    public function removeItem(int $index): void
    {
        if (!isset($this->items[$index])) {
            throw new \OutOfRangeException(sprintf('Index %d does not exist in the list.', $index));
        }

        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }
}
