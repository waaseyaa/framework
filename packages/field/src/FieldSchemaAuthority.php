<?php

declare(strict_types=1);

namespace Waaseyaa\Field;

use Waaseyaa\Entity\EntityTypeInterface;

/**
 * Canonical, model-independent structural introspection for entity fields.
 *
 * Authorization-aware adapters remove fields the principal may not view
 * before exposing this output. This class performs no implicit access check,
 * so an absent principal can never be mistaken for authority.
 *
 * @api
 */
final readonly class FieldSchemaAuthority
{
    public function __construct(private FieldTypeManagerInterface $fieldTypes) {}

    /** @return array<string, mixed> */
    public function fieldSchema(FieldDefinitionInterface $definition): array
    {
        $valueSchema = $this->fieldTypes->entityValueJsonSchemaFor($definition);
        $settings = $definition->getSettings();
        if (isset($settings['allowed_values']) && is_array($settings['allowed_values'])) {
            $valueSchema['enum'] = array_keys($settings['allowed_values']);
        }
        if (isset($settings['max_length']) && is_int($settings['max_length'])) {
            $valueSchema['maxLength'] = $settings['max_length'];
        }
        foreach (['min' => 'minimum', 'max' => 'maximum'] as $setting => $keyword) {
            if (isset($settings[$setting]) && (is_int($settings[$setting]) || is_float($settings[$setting]))) {
                $valueSchema[$keyword] = $settings[$setting];
            }
        }

        $schema = $definition->isMultiple()
            ? ['type' => 'array', 'items' => $valueSchema]
            : $valueSchema;

        $schema['x-field-type'] = $definition->getType();
        $schema['x-cardinality'] = $definition->getCardinality();
        $schema['x-translatable'] = $definition->isTranslatable();
        $schema['x-revisionable'] = $definition->isRevisionable();

        if ($definition->isReadOnly()) {
            $schema['readOnly'] = true;
        }
        if ($definition->getDescription() !== '') {
            $schema['description'] = $definition->getDescription();
        }
        if ($definition->getDefaultValue() !== null) {
            $schema['default'] = $definition->getDefaultValue();
        }

        return $schema;
    }

    /**
     * @param array<string, FieldDefinitionInterface> $fieldDefinitions
     * @return array<string, mixed>
     */
    public function entitySchema(EntityTypeInterface $entityType, array $fieldDefinitions): array
    {
        $keys = $entityType->getKeys();
        $properties = $this->systemProperties($entityType);
        $required = [];

        foreach ($fieldDefinitions as $name => $definition) {
            if (in_array($name, array_values($keys), true)) {
                continue;
            }
            $properties[$name] = $this->fieldSchema($definition);
            if ($definition->isRequired()) {
                $required[] = $name;
            }
        }

        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => $entityType->getLabel(),
            'description' => sprintf('Schema for %s entities.', $entityType->getLabel()),
            'type' => 'object',
            'x-entity-type' => $entityType->id(),
            'x-translatable' => $entityType->isTranslatable(),
            'x-revisionable' => $entityType->isRevisionable(),
            'x-bundle-key' => $keys['bundle'] ?? null,
            'properties' => $properties,
            'additionalProperties' => false,
        ];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /** @return list<string> */
    public function blueprintFieldTypeIds(): array
    {
        return $this->fieldTypes->blueprintFieldTypeIds();
    }

    /** @return array<string, array<string, mixed>> */
    private function systemProperties(EntityTypeInterface $entityType): array
    {
        $keys = $entityType->getKeys();
        $properties = [];
        if (isset($keys['id'])) {
            $properties[$keys['id']] = ['type' => isset($keys['uuid']) ? 'integer' : 'string'];
            if (isset($keys['uuid'])) {
                $properties[$keys['id']]['readOnly'] = true;
            }
        }
        if (isset($keys['uuid'])) {
            $properties[$keys['uuid']] = ['type' => 'string', 'format' => 'uuid', 'readOnly' => true];
        }
        if (isset($keys['label'])) {
            $properties[$keys['label']] = ['type' => 'string'];
        }
        if (isset($keys['bundle'])) {
            $properties[$keys['bundle']] = ['type' => 'string'];
        }
        if (isset($keys['langcode']) && $entityType->isTranslatable()) {
            $properties[$keys['langcode']] = ['type' => 'string'];
        }
        if (isset($keys['revision']) && $entityType->isRevisionable()) {
            $properties[$keys['revision']] = ['type' => 'integer', 'readOnly' => true];
        }

        return $properties;
    }
}
