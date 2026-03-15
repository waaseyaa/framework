<?php

declare(strict_types=1);

namespace Waaseyaa\Field;

use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Waaseyaa\Plugin\Definition\PluginDefinition;
use Waaseyaa\Plugin\PluginBase;
use Waaseyaa\TypedData\DataDefinitionInterface;

abstract class FieldItemBase extends PluginBase implements FieldItemInterface, FieldTypeInterface, \IteratorAggregate
{
    /** @var array<string, mixed> */
    protected array $values = [];

    protected FieldDefinitionInterface $fieldDefinition;

    public function __construct(
        string $pluginId,
        PluginDefinition $pluginDefinition,
        array $configuration = [],
    ) {
        parent::__construct($pluginId, $pluginDefinition, $configuration);

        if (isset($configuration['field_definition'])) {
            $this->fieldDefinition = $configuration['field_definition'];
        } else {
            $this->fieldDefinition = new FieldDefinition(
                name: $configuration['field_name'] ?? $pluginId,
                type: $pluginId,
            );
        }

        if (isset($configuration['values'])) {
            foreach ($configuration['values'] as $name => $value) {
                $this->values[$name] = $value;
            }
        }
    }

    public function isEmpty(): bool
    {
        $mainProperty = static::mainPropertyName();
        $value = $this->values[$mainProperty] ?? null;

        return $value === null || $value === '' || $value === [];
    }

    public function getFieldDefinition(): FieldDefinitionInterface
    {
        return $this->fieldDefinition;
    }

    // ComplexDataInterface methods

    public function get(string $name): PropertyValue
    {
        $definitions = static::propertyDefinitions();
        if (!array_key_exists($name, $definitions)) {
            throw new \InvalidArgumentException("Property '$name' does not exist.");
        }

        return new PropertyValue($name, $this->values[$name] ?? null);
    }

    public function set(string $name, mixed $value): static
    {
        $definitions = static::propertyDefinitions();
        if (!array_key_exists($name, $definitions)) {
            throw new \InvalidArgumentException("Property '$name' does not exist.");
        }

        $this->values[$name] = $value;

        return $this;
    }

    public function getProperties(): array
    {
        $properties = [];
        foreach (static::propertyDefinitions() as $name => $type) {
            $properties[$name] = new PropertyValue($name, $this->values[$name] ?? null);
        }

        return $properties;
    }

    public function toArray(): array
    {
        $result = [];
        foreach (static::propertyDefinitions() as $name => $type) {
            $result[$name] = $this->values[$name] ?? null;
        }

        return $result;
    }

    // TypedDataInterface methods

    public function getValue(): mixed
    {
        $mainProperty = static::mainPropertyName();

        return $this->values[$mainProperty] ?? null;
    }

    public function setValue(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $name => $val) {
                if (array_key_exists($name, static::propertyDefinitions())) {
                    $this->values[$name] = $val;
                }
            }
        } else {
            $mainProperty = static::mainPropertyName();
            $this->values[$mainProperty] = $value;
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
        $value = $this->getValue();

        if ($value === null) {
            return '';
        }

        return (string) $value;
    }

    // IteratorAggregate

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->getProperties());
    }

    // FieldTypeInterface static methods have defaults that subclasses override

    public static function defaultSettings(): array
    {
        return [];
    }

    public static function defaultValue(): mixed
    {
        return null;
    }
}
