<?php

declare(strict_types=1);

namespace Waaseyaa\Field;

use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Waaseyaa\Plugin\Definition\PluginDefinition;
use Waaseyaa\TypedData\DataDefinitionInterface;

/**
 * @api
 *
 * The dead Field-API item/value-object layer (audit C-24). Instance methods
 * (`get()`/`set()`/`getValue()`/`validate()` no-op/`PropertyValue`/IteratorAggregate)
 * are instantiated nowhere in production — field-type plugins reach this class only
 * for the static descriptor seam, now hosted by {@see AbstractFieldType} which this
 * extends. Train 2 reparents the plugins onto `AbstractFieldType` and removes this
 * instance layer.
 */
abstract class FieldItemBase extends AbstractFieldType implements FieldItemInterface, \IteratorAggregate
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

    /**
     * Inert by design: part of the ported Drupal TypedData/ComplexData
     * contract (TypedDataInterface mandates validate()), but NOT the
     * framework's validation path. Field validation runs through
     * EntityValidator via EntityTypeValidationConstraints::forEntityType()
     * (wired into EntityRepository pre-save since alpha.204); constraint-aware
     * TypedData validation lives in the typed-data package primitives
     * (e.g. Waaseyaa\TypedData\Type\StringData::validate()). Subclasses may
     * override, but the per-item surface is intentionally a no-op here.
     */
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

    // The static field-type descriptor seam (defaultSettings / defaultValue /
    // jsonSchemaFor / schemaFor) now lives on AbstractFieldType (C-24 train 1);
    // it is inherited here unchanged. schema() / jsonSchema() remain abstract on
    // FieldTypeInterface and are provided by each concrete field-type plugin.
}
