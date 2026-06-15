<?php

declare(strict_types=1);

namespace Waaseyaa\Field;

use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Waaseyaa\Plugin\Definition\PluginDefinition;
use Waaseyaa\Plugin\PluginBase;
use Waaseyaa\TypedData\DataDefinitionInterface;

/**
 * @api
 */
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

    // FieldTypeInterface static methods have defaults that subclasses override

    public static function defaultSettings(): array
    {
        return [];
    }

    public static function defaultValue(): mixed
    {
        return null;
    }

    /**
     * Default per-definition JSON Schema.
     *
     * Reproduces the legacy per-type mapping that previously lived as a
     * hardcoded match in FieldDefinition::toJsonSchema(). Field types that
     * need per-definition variation (e.g. EnumItem reading
     * settings.enum_class) override this method. Field types that are happy
     * with the legacy mapping (string, integer, boolean, float, text,
     * entity_reference) get bit-identical behavior with zero overrides.
     *
     * Note: this intentionally returns the legacy mapping rather than
     * delegating to static::jsonSchema(). The latter is the richer per-type
     * schema (e.g. StringItem::jsonSchema() includes maxLength) but
     * FieldDefinition::toJsonSchema() has historically emitted a minimal
     * shape; preserving that emission contract is mandated by WP01's
     * regression test.
     */
    public static function jsonSchemaFor(FieldDefinitionInterface $def): array
    {
        return match ($def->getType()) {
            'string' => ['type' => 'string'],
            'integer' => ['type' => 'integer'],
            'boolean' => ['type' => 'boolean'],
            'float' => ['type' => 'number'],
            'text' => [
                'type' => 'object',
                'properties' => [
                    'value' => ['type' => 'string'],
                    'format' => ['type' => 'string'],
                ],
            ],
            'entity_reference' => [
                'type' => 'object',
                'properties' => [
                    'target_id' => ['type' => 'integer'],
                    'target_type' => ['type' => 'string'],
                ],
            ],
            default => ['type' => 'string'],
        };
    }

    /**
     * Default per-definition storage schema. Delegates to the static
     * schema() method, preserving behavior for every existing field type.
     *
     * @return array<string, array{type: string, description?: string}>
     */
    public static function schemaFor(FieldDefinitionInterface $def): array
    {
        return static::schema();
    }
}
