<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Schema;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Exception\PartialAccessContextException;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionInterface;
use Waaseyaa\Field\FieldStorage;

/**
 * Converts EntityType definitions (and optional FieldDefinitions) to JSON Schema
 * representations with widget hints for admin SPA consumption.
 *
 * This presenter reads **field definitions on the entity type**, not `EntityBase::$casts`.
 * Cast-only value objects or enums on the entity class do not automatically appear here; align definitions
 * with admin UX when structured fields need widgets (#1184 / entity-system spec).
 *
 * The output follows JSON Schema draft-07 format with custom extensions:
 * - "x-widget": widget type hint for the admin UI (text, textarea, richtext, select, boolean, etc.)
 * - "x-label": human-readable field label
 * - "x-description": field description for help text
 * - "x-weight": field display order weight
 * - "x-required": whether the field is required in forms
 * - "x-access-restricted": field is viewable but not editable by the current account
 * - "x-source-field": for machine_name widgets, the field name to auto-generate from
 * - "x-min" / "x-max": presentation bounds for date-only string widgets
 */
final class SchemaPresenter
{
    /**
     * @param FieldDefinitionRegistryInterface|null $fieldDefinitionRegistry
     *   When provided, the bundle property in the rendered schema gains an
     *   `enum` of declared bundle names (via `bundleNamesFor()`). Null leaves
     *   the bundle property as a bare string for backward compatibility with
     *   pre-M3A callers — see #1413.
     */
    public function __construct(
        private readonly ?FieldDefinitionRegistryInterface $fieldDefinitionRegistry = null,
    ) {}

    /**
     * Return the structurally registered bundles for an entity type.
     *
     * Null means this presenter was constructed without a registry and cannot
     * authoritatively validate bundle membership. An empty array means a
     * registry is present and no bundles have registered fields.
     *
     * @return list<string>|null
     */
    public function availableBundles(string $entityTypeId): ?array
    {
        if ($this->fieldDefinitionRegistry === null) {
            return null;
        }

        $bundles = $this->fieldDefinitionRegistry->bundleNamesFor($entityTypeId);
        sort($bundles);

        return $bundles;
    }

    /**
     * Known widget mappings from field types to UI widget hints.
     *
     * @var array<string, string>
     */
    private const WIDGET_MAP = [
        'string' => 'text',
        'text' => 'textarea',
        'text_long' => 'richtext',
        'boolean' => 'boolean',
        'integer' => 'number',
        'float' => 'number',
        'decimal' => 'number',
        'email' => 'email',
        'uri' => 'url',
        'date' => 'date',
        'timestamp' => 'datetime',
        'datetime' => 'datetime',
        'entity_reference' => 'entity_autocomplete',
        'list_string' => 'select',
        'list_integer' => 'select',
        'list_float' => 'select',
        'image' => 'image',
        'file' => 'file',
        'password' => 'password',
    ];

    /**
     * JSON Schema type mappings from field types.
     *
     * @var array<string, string>
     */
    private const TYPE_MAP = [
        'string' => 'string',
        'text' => 'string',
        'text_long' => 'string',
        'boolean' => 'boolean',
        'integer' => 'integer',
        'float' => 'number',
        'decimal' => 'number',
        'email' => 'string',
        'uri' => 'string',
        'date' => 'string',
        'timestamp' => 'string',
        'datetime' => 'string',
        'entity_reference' => 'string',
        'list_string' => 'string',
        'list_integer' => 'integer',
        'list_float' => 'number',
        'image' => 'string',
        'file' => 'string',
        'password' => 'string',
    ];

    /**
     * JSON Schema format mappings for specific field types.
     *
     * @var array<string, string>
     */
    private const FORMAT_MAP = [
        'email' => 'email',
        'uri' => 'uri',
        'date' => 'date',
        'timestamp' => 'date-time',
        'datetime' => 'date-time',
    ];

    /**
     * Present an entity type as a JSON Schema with widget hints.
     *
     * @param EntityTypeInterface                  $entityType       The entity type definition.
     * @param array<string, FieldDefinitionInterface|array<string, mixed>>  $fieldDefinitions Optional field definitions keyed by field name.
     * @param EntityInterface|null                 $entity           Optional entity for field access checking.
     * @param EntityAccessHandler|null             $accessHandler    Optional access handler for field filtering.
     * @param \Waaseyaa\Access\AuthorizationPrincipalInterface|null $account Immutable principal for access checks.
     *   When all three optional parameters are provided, view-denied fields are removed
     *   and edit-denied fields are marked readOnly with x-access-restricted.
     *
     * @return array<string, mixed> JSON Schema array.
     */
    public function present(
        EntityTypeInterface $entityType,
        array $fieldDefinitions = [],
        ?EntityInterface $entity = null,
        ?EntityAccessHandler $accessHandler = null,
        ?AccountInterface $account = null,
    ): array {
        if (($accessHandler === null) !== ($account === null)) {
            throw PartialAccessContextException::forSerializer(__METHOD__);
        }

        $keys = $entityType->getKeys();

        $schema = [
            '$schema' => 'https://json-schema.org/draft-07/schema#',
            'title' => $entityType->getLabel(),
            'description' => sprintf('Schema for %s entities.', $entityType->getLabel()),
            'type' => 'object',
            'x-entity-type' => $entityType->id(),
            'x-translatable' => $entityType->isTranslatable(),
            'x-revisionable' => $entityType->isRevisionable(),
            // The property name that holds the bundle value (e.g. 'type' for
            // nodes). Null when the entity type has no bundle key. Admin SPA
            // reads this to render a bundle filter / selector — see #1413.
            'x-bundle-key' => $keys['bundle'] ?? null,
        ];

        $properties = [];
        $required = [];

        // Add system properties from entity keys.
        $systemProperties = $this->buildSystemProperties($keys, $entityType);
        foreach ($systemProperties as $name => $prop) {
            $properties[$name] = $prop;
        }

        // Add field definitions if provided.
        if ($fieldDefinitions !== []) {
            foreach ($fieldDefinitions as $fieldName => $definitionRaw) {
                // Skip system keys — they are already handled.
                if (in_array($fieldName, array_values($keys), true)) {
                    continue;
                }
                $definition = $this->normalizeFieldDefinition($fieldName, $definitionRaw, $entityType->id());

                $fieldType = $definition->getType();
                $fieldSchema = $this->buildFieldSchema($fieldName, $fieldType, $definition);
                $properties[$fieldName] = $fieldSchema;

                if ($definition->isRequired()) {
                    $required[] = $fieldName;
                }
            }
        }

        // Apply field access control if context is available.
        if ($entity !== null && $accessHandler !== null && $account !== null) {
            $systemKeys = array_values($keys);
            foreach ($properties as $fieldName => $property) {
                // Skip system properties — they are always shown as-is.
                if (in_array($fieldName, $systemKeys, true)) {
                    continue;
                }

                $viewResult = $accessHandler->checkFieldAccess($entity, $fieldName, 'view', $account);
                if ($viewResult->isForbidden()) {
                    unset($properties[$fieldName]);
                    // Also remove from required list.
                    $required = array_values(array_filter(
                        $required,
                        static fn(string $name): bool => $name !== $fieldName,
                    ));
                    continue;
                }

                $editResult = $accessHandler->checkFieldAccess($entity, $fieldName, 'edit', $account);
                if ($editResult->isForbidden()) {
                    $properties[$fieldName]['readOnly'] = true;
                    $properties[$fieldName]['x-access-restricted'] = true;
                }
            }
        }

        $schema['properties'] = $properties;

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @param FieldDefinitionInterface|array<string, mixed> $definition
     */
    private function normalizeFieldDefinition(
        string $fieldName,
        FieldDefinitionInterface|array $definition,
        string $entityTypeId,
    ): FieldDefinitionInterface {
        if ($definition instanceof FieldDefinitionInterface) {
            return $definition;
        }

        $settings = $definition['settings'] ?? [];
        if (!is_array($settings)) {
            $settings = [];
        }
        foreach ($definition as $key => $value) {
            if (!in_array($key, ['type', 'label', 'description', 'required', 'readOnly', 'read_only', 'cardinality', 'translatable', 'revisionable', 'default', 'defaultValue', 'settings', 'constraints', 'stored', 'read'], true)) {
                $settings[$key] = $value;
            }
        }
        $stored = $definition['stored'] ?? FieldStorage::Column;
        if (is_string($stored)) {
            $stored = FieldStorage::tryFrom($stored) ?? FieldStorage::Column;
        }
        if (!$stored instanceof FieldStorage) {
            $stored = FieldStorage::Column;
        }

        return new FieldDefinition(
            name: $fieldName,
            type: (string) ($definition['type'] ?? 'string'),
            cardinality: (int) ($definition['cardinality'] ?? 1),
            settings: $settings,
            targetEntityTypeId: $entityTypeId,
            targetBundle: null,
            translatable: (bool) ($definition['translatable'] ?? false),
            revisionable: (bool) ($definition['revisionable'] ?? false),
            defaultValue: $definition['defaultValue'] ?? ($definition['default'] ?? null),
            label: (string) ($definition['label'] ?? ''),
            description: (string) ($definition['description'] ?? ''),
            required: (bool) ($definition['required'] ?? false),
            readOnly: (bool) ($definition['readOnly'] ?? $definition['read_only'] ?? false),
            constraints: is_array($definition['constraints'] ?? null) ? $definition['constraints'] : [],
            stored: $stored,
            read: ($definition['read'] ?? null) instanceof \Waaseyaa\Entity\FieldReadLevel ? $definition['read'] : null,
        );
    }

    /**
     * Build system properties from entity keys.
     *
     * @param array<string, string> $keys       Entity keys.
     * @param EntityTypeInterface   $entityType The entity type.
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildSystemProperties(array $keys, EntityTypeInterface $entityType): array
    {
        $properties = [];

        if (isset($keys['id'])) {
            if (isset($keys['uuid'])) {
                // Content entity: auto-increment integer ID, hidden.
                $properties[$keys['id']] = [
                    'type' => 'integer',
                    'description' => 'The primary identifier.',
                    'readOnly' => true,
                    'x-widget' => 'hidden',
                ];
            } else {
                // Config entity: editable string machine name.
                $prop = [
                    'type' => 'string',
                    'description' => 'The machine name identifier.',
                    'x-widget' => 'machine_name',
                    'x-label' => 'Machine name',
                ];
                if (isset($keys['label'])) {
                    $prop['x-source-field'] = $keys['label'];
                }
                $properties[$keys['id']] = $prop;
            }
        }

        if (isset($keys['uuid'])) {
            $properties[$keys['uuid']] = [
                'type' => 'string',
                'format' => 'uuid',
                'description' => 'The universally unique identifier.',
                'readOnly' => true,
                'x-widget' => 'hidden',
            ];
        }

        if (isset($keys['label'])) {
            $properties[$keys['label']] = [
                'type' => 'string',
                'description' => sprintf('The %s label.', $entityType->getLabel()),
                'x-widget' => 'text',
                'x-label' => 'Title',
            ];
        }

        if (isset($keys['bundle'])) {
            $bundleProperty = [
                'type' => 'string',
                'description' => 'The entity bundle.',
                'x-widget' => 'hidden',
            ];

            if ($this->fieldDefinitionRegistry !== null) {
                $bundleNames = $this->availableBundles($entityType->id()) ?? [];
                if ($bundleNames !== []) {
                    // With a known set of bundles, the bundle becomes a real
                    // user-facing field on create: a required select sorted to
                    // the top of the form. M3B (#1413). Edit-side enforcement
                    // of bundle immutability is the storage layer's
                    // responsibility, not the schema's — see notes in
                    // docs/specs/entity-system.md.
                    $bundleProperty['enum'] = $bundleNames;
                    $bundleProperty['x-widget'] = 'select';
                    $bundleProperty['x-label'] = 'Bundle';
                    $bundleProperty['x-required'] = true;
                    $bundleProperty['x-weight'] = -100;
                }
            }

            $properties[$keys['bundle']] = $bundleProperty;
        }

        if (isset($keys['langcode']) && $entityType->isTranslatable()) {
            $properties[$keys['langcode']] = [
                'type' => 'string',
                'description' => 'The language code.',
                'x-widget' => 'select',
                'x-label' => 'Language',
            ];
        }

        return $properties;
    }

    /**
     * Build a JSON Schema property for a field definition.
     *
     * @param string $fieldName  The field machine name.
     * @param string $fieldType  The field type (string, boolean, integer, etc.).
     * @param FieldDefinitionInterface $definition The full field definition.
     *
     * @return array<string, mixed>
     */
    private function buildFieldSchema(string $fieldName, string $fieldType, FieldDefinitionInterface $definition): array
    {
        $isIntegerTimestamp = in_array($fieldType, ['integer', 'int'], true)
            && $definition->getSetting('subtype') === 'timestamp';
        $schema = [
            'type' => $isIntegerTimestamp ? 'string' : (self::TYPE_MAP[$fieldType] ?? 'string'),
            // Preserve the canonical FieldDefinition cardinality so generic
            // widgets can distinguish scalar and multi-value authoring.
            'x-cardinality' => $definition->getCardinality(),
        ];

        // Add format if applicable.
        if ($isIntegerTimestamp) {
            $schema['format'] = 'date-time';
        } elseif (isset(self::FORMAT_MAP[$fieldType])) {
            $schema['format'] = self::FORMAT_MAP[$fieldType];
        }

        // Add description.
        $description = $definition->getDescription();
        if ($description !== '') {
            $schema['description'] = $description;
        }

        // Widget hint.
        $schema['x-widget'] = $definition->getSetting('widget')
            ?? ($isIntegerTimestamp ? 'datetime' : (self::WIDGET_MAP[$fieldType] ?? 'text'));

        $sourceField = $definition->getSetting('source_field');
        if (is_string($sourceField) && $sourceField !== '') {
            $schema['x-source-field'] = $sourceField;
        }

        // Human-readable label.
        $label = $definition->getLabel();
        if ($label !== '') {
            $schema['x-label'] = $label;
        } else {
            // Generate a label from field name: 'field_body' -> 'Body', 'title' -> 'Title'.
            $label = str_replace('field_', '', $fieldName);
            $label = str_replace('_', ' ', $label);
            $schema['x-label'] = ucwords($label);
        }

        // Description for help text.
        if ($description !== '') {
            $schema['x-description'] = $description;
        }

        // Display weight.
        $schema['x-weight'] = (int) ($definition->getSetting('weight') ?? 0);

        // Required flag.
        if ($definition->isRequired()) {
            $schema['x-required'] = true;
        }

        // Settings (e.g., allowed values for select fields).
        $settings = $definition->getSettings();
        if ($settings !== []) {

            // Handle allowed_values for list/select fields.
            if (isset($settings['allowed_values'])) {
                $schema['enum'] = array_keys($settings['allowed_values']);
                $schema['x-enum-labels'] = $settings['allowed_values'];
            }

            // Handle max_length for string fields.
            if (isset($settings['max_length'])) {
                $schema['maxLength'] = $settings['max_length'];
            }

            // Date-only bounds are presentation hints: draft-07's numeric
            // minimum/maximum keywords do not apply to formatted strings.
            if ($fieldType === 'date') {
                if (isset($settings['min'])) {
                    $schema['x-min'] = $settings['min'];
                }
                if (isset($settings['max'])) {
                    $schema['x-max'] = $settings['max'];
                }
            } else {
                if (isset($settings['min'])) {
                    $schema['minimum'] = $settings['min'];
                }
                if (isset($settings['max'])) {
                    $schema['maximum'] = $settings['max'];
                }
            }

            // Handle target_type for entity_reference fields (legacy settings format).
            if (isset($settings['target_type'])) {
                $schema['x-target-type'] = $settings['target_type'];
            }
        }

        // Handle top-level target_entity_type_id for entity_reference fields.
        $targetType = $definition->getSetting('target_entity_type_id')
            ?? $definition->getSetting('targetEntityTypeId');
        if (is_string($targetType) && $targetType !== '') {
            $schema['x-target-type'] = $targetType;
        }

        // Default value.
        $defaultValue = $definition->getDefaultValue();
        if ($defaultValue !== null) {
            // Cast boolean defaults to native bool for JSON Schema.
            if ($fieldType === 'boolean') {
                $defaultValue = (bool) $defaultValue;
            }
            $schema['default'] = $defaultValue;
        }

        return $schema;
    }
}
