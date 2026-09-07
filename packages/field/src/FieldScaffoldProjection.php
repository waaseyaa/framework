<?php

declare(strict_types=1);

namespace Waaseyaa\Field;

use Waaseyaa\Entity\Attribute\FieldTypeInferrer;

/**
 * Projects registered field definitions into PHP scaffold property metadata.
 *
 * @internal
 */
final readonly class FieldScaffoldProjection
{
    private FieldSchemaAuthority $schemas;

    public function __construct(
        private FieldTypeManagerInterface&FieldValueKindResolverInterface $fieldTypes,
    ) {
        $this->schemas = new FieldSchemaAuthority($fieldTypes);
    }

    /**
     * Registered field ids a scalar scaffold can declare without additional
     * metadata, plus registered entity-reference kinds whose target is
     * supplied by the authored reference syntax.
     *
     * @return list<string>
     */
    public function fieldTypeIds(): array
    {
        $candidates = $this->schemas->blueprintFieldTypeIds();
        foreach (array_keys($this->fieldTypes->getDefinitions()) as $id) {
            if ($this->fieldTypes->valueKind($id) === FieldValueKind::EntityReference) {
                $candidates[] = $id;
            }
        }

        $ids = [];
        foreach (array_unique($candidates) as $id) {
            $settings = $this->fieldTypes->getDefaultSettings($id);
            if (in_array(null, $settings, true)) {
                continue;
            }

            $this->property(new FieldDefinition(
                name: 'value',
                type: $id,
                settings: $settings,
                fieldTypeManager: $this->fieldTypes,
            ));
            $ids[] = $id;
        }
        sort($ids, SORT_STRING);

        return $ids;
    }

    public function valueKind(string $fieldType): FieldValueKind
    {
        return $this->fieldTypes->valueKind($fieldType);
    }

    public function definition(string $name, string $fieldType, ?string $referenceTarget = null): FieldDefinition
    {
        if (!in_array($fieldType, $this->fieldTypeIds(), true)) {
            throw new \InvalidArgumentException(sprintf(
                'Registered field type "%s" cannot be scaffolded without additional metadata.',
                $fieldType,
            ));
        }

        $settings = $this->fieldTypes->getDefaultSettings($fieldType);
        if ($this->valueKind($fieldType) === FieldValueKind::EntityReference) {
            if ($referenceTarget === null || $referenceTarget === '') {
                throw new \InvalidArgumentException(sprintf(
                    'Entity-reference field "%s" requires a target entity type.',
                    $name,
                ));
            }
            $settings['target_entity_type_id'] = $referenceTarget;
        }

        return new FieldDefinition(
            name: $name,
            type: $fieldType,
            settings: $settings,
            fieldTypeManager: $this->fieldTypes,
        );
    }

    /**
     * @return array{phpType: string, defaultLiteral: string}
     */
    public function property(FieldDefinitionInterface $definition): array
    {
        $this->schemas->fieldSchema($definition);
        $this->fieldTypes->schemaFor($definition);
        if ($definition->getStored() === FieldStorage::Column) {
            $this->fieldTypes->entityStorageColumnSchemaFor($definition);
        }

        if ($definition->isMultiple()) {
            return [
                'phpType' => 'array',
                'defaultLiteral' => $definition->getDefaultValue() === null
                    ? '[]'
                    : var_export($definition->getDefaultValue(), true),
            ];
        }

        [$phpType, $defaultLiteral, $inferredType] = match ($this->valueKind($definition->getType())) {
            FieldValueKind::String, FieldValueKind::FormattedText => ['string', "''", 'string'],
            FieldValueKind::Integer => ['?int', 'null', 'integer'],
            FieldValueKind::Boolean => ['bool', 'false', 'boolean'],
            FieldValueKind::Float => ['?float', 'null', 'float'],
            FieldValueKind::EntityReference => ['?int', 'null', 'integer'],
        };

        if (!FieldTypeInferrer::isCompatible($inferredType, $definition->getType())) {
            $phpType = 'mixed';
            $defaultLiteral = 'null';
        }

        if ($definition->getDefaultValue() !== null) {
            $defaultLiteral = var_export($definition->getDefaultValue(), true);
        }

        return ['phpType' => $phpType, 'defaultLiteral' => $defaultLiteral];
    }
}
