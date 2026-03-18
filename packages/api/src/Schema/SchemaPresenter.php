<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Schema;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeInterface;

/**
 * Converts EntityType definitions (and optional FieldDefinitions) to JSON Schema
 * representations with widget hints for admin SPA consumption.
 *
 * The output follows JSON Schema draft-07 format with custom extensions:
 * - "x-widget": widget type hint for the admin UI (text, textarea, richtext, select, boolean, etc.)
 * - "x-label": human-readable field label
 * - "x-description": field description for help text
 * - "x-weight": field display order weight
 * - "x-required": whether the field is required in forms
 * - "x-access-restricted": field is viewable but not editable by the current account
 * - "x-source-field": for machine_name widgets, the field name to auto-generate from
 */
final class SchemaPresenter
{
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
        'timestamp' => 'date-time',
        'datetime' => 'date-time',
    ];

    /**
     * Present an entity type as a JSON Schema with widget hints.
     *
     * @param EntityTypeInterface                  $entityType       The entity type definition.
     * @param array<string, array<string, mixed>>  $fieldDefinitions Optional field definitions keyed by field name.
     *   Each field definition may contain: type, label, description, required, weight, settings.
     * @param EntityInterface|null                 $entity           Optional entity for field access checking.
     * @param EntityAccessHandler|null             $accessHandler    Optional access handler for field filtering.
     * @param AccountInterface|null                $account          Optional account for access checks.
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
        $schema = [
            '$schema' => 'https://json-schema.org/draft-07/schema#',
            'title' => $entityType->getLabel(),
            'description' => sprintf('Schema for %s entities.', $entityType->getLabel()),
            'type' => 'object',
            'x-entity-type' => $entityType->id(),
            'x-translatable' => $entityType->isTranslatable(),
            'x-revisionable' => $entityType->isRevisionable(),
        ];

        $properties = [];
        $required = [];
        $keys = $entityType->getKeys();

        // Add system properties from entity keys.
        $systemProperties = $this->buildSystemProperties($keys, $entityType);
        foreach ($systemProperties as $name => $prop) {
            $properties[$name] = $prop;
        }

        // Add field definitions if provided.
        if ($fieldDefinitions !== []) {
            foreach ($fieldDefinitions as $fieldName => $definition) {
                // Skip system keys — they are already handled.
                if (in_array($fieldName, array_values($keys), true)) {
                    continue;
                }

                $fieldType = $definition['type'] ?? 'string';
                $fieldSchema = $this->buildFieldSchema($fieldName, $fieldType, $definition);
                $properties[$fieldName] = $fieldSchema;

                if (!empty($definition['required'])) {
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
            $properties[$keys['bundle']] = [
                'type' => 'string',
                'description' => 'The entity bundle.',
                'x-widget' => 'hidden',
            ];
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
     * @param string               $fieldName  The field machine name.
     * @param string               $fieldType  The field type (string, boolean, integer, etc.).
     * @param array<string, mixed> $definition The full field definition.
     *
     * @return array<string, mixed>
     */
    private function buildFieldSchema(string $fieldName, string $fieldType, array $definition): array
    {
        $schema = [
            'type' => self::TYPE_MAP[$fieldType] ?? 'string',
        ];

        // Add format if applicable.
        if (isset(self::FORMAT_MAP[$fieldType])) {
            $schema['format'] = self::FORMAT_MAP[$fieldType];
        }

        // Add description.
        if (isset($definition['description'])) {
            $schema['description'] = $definition['description'];
        }

        // Widget hint.
        $schema['x-widget'] = $definition['widget'] ?? self::WIDGET_MAP[$fieldType] ?? 'text';

        // Human-readable label.
        if (isset($definition['label'])) {
            $schema['x-label'] = $definition['label'];
        } else {
            // Generate a label from field name: 'field_body' -> 'Body', 'title' -> 'Title'.
            $label = str_replace('field_', '', $fieldName);
            $label = str_replace('_', ' ', $label);
            $schema['x-label'] = ucwords($label);
        }

        // Description for help text.
        if (isset($definition['description'])) {
            $schema['x-description'] = $definition['description'];
        }

        // Display weight.
        if (isset($definition['weight'])) {
            $schema['x-weight'] = $definition['weight'];
        }

        // Required flag.
        if (!empty($definition['required'])) {
            $schema['x-required'] = true;
        }

        // Settings (e.g., allowed values for select fields).
        if (isset($definition['settings'])) {
            $settings = $definition['settings'];

            // Handle allowed_values for list/select fields.
            if (isset($settings['allowed_values'])) {
                $schema['enum'] = array_keys($settings['allowed_values']);
                $schema['x-enum-labels'] = $settings['allowed_values'];
            }

            // Handle max_length for string fields.
            if (isset($settings['max_length'])) {
                $schema['maxLength'] = $settings['max_length'];
            }

            // Handle min/max for numeric fields.
            if (isset($settings['min'])) {
                $schema['minimum'] = $settings['min'];
            }
            if (isset($settings['max'])) {
                $schema['maximum'] = $settings['max'];
            }

            // Handle target_type for entity_reference fields (legacy settings format).
            if (isset($settings['target_type'])) {
                $schema['x-target-type'] = $settings['target_type'];
            }
        }

        // Handle top-level target_entity_type_id for entity_reference fields.
        if (isset($definition['target_entity_type_id'])) {
            $schema['x-target-type'] = $definition['target_entity_type_id'];
        }

        // Default value.
        if (array_key_exists('default', $definition)) {
            $defaultValue = $definition['default'];
            // Cast boolean defaults to native bool for JSON Schema.
            if ($fieldType === 'boolean') {
                $defaultValue = (bool) $defaultValue;
            }
            $schema['default'] = $defaultValue;
        }

        return $schema;
    }
}
