<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Validation;

use Symfony\Component\Validator\Constraint;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Entity\Repository\EntityIdentifierResolver;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionInterface;

/**
 * Resolves the constraint map used by {@see \Waaseyaa\EntityStorage\EntityRepository} on save.
 *
 * Precedence: for each field name present in {@see EntityTypeInterface::getConstraints()}, those
 * constraints **replace** any constraints derived from {@see EntityTypeInterface::getFieldDefinitions()}.
 * Manual keys that do not appear in field definitions are still applied. Fields only described in
 * field definitions use derived constraints only.
 *
 * {@see EntityReferenceExistenceConstraintBuilder} composes afterward when a resolver is supplied;
 * manual per-type constraints cannot remove those existence checks.
 */
final class EntityTypeValidationConstraints
{
    /**
     * @param array<string, \Waaseyaa\Field\FieldDefinitionInterface>|null $fieldDefinitions
     *   Pre-resolved field definitions to derive constraints from (e.g. the
     *   canonical bundle-aware set, so a content type's bundle fields are
     *   validated). When null, falls back to the entity type's class-declared
     *   base fields, preserving pre-bundle behaviour.
     *
     * @return array<string, Constraint|list<Constraint>>
     */
    public static function forEntityType(
        EntityTypeInterface $entityType,
        ?array $fieldDefinitions = null,
        ?EntityIdentifierResolver $referenceResolver = null,
    ): array {
        $resolvedFieldDefinitions = $fieldDefinitions ?? $entityType->getFieldDefinitions();
        $merged = FieldDefinitionConstraintBuilder::build($resolvedFieldDefinitions);

        foreach ($entityType->getConstraints() as $field => $manual) {
            $merged[$field] = self::normalizeToList($manual);
        }

        if ($referenceResolver !== null) {
            foreach ($resolvedFieldDefinitions as $fieldName => $definition) {
                $normalized = self::normalizeFieldDefinition($fieldName, $definition);
                if ($normalized->getType() !== 'entity_reference') {
                    continue;
                }

                $merged[$fieldName] = [
                    ...($merged[$fieldName] ?? []),
                    ...EntityReferenceExistenceConstraintBuilder::existenceConstraints(
                        $fieldName,
                        $normalized,
                        $referenceResolver,
                    ),
                ];
            }
        }

        return $merged;
    }

    /**
     * @return list<Constraint>
     */
    private static function normalizeToList(mixed $constraints): array
    {
        if ($constraints instanceof Constraint) {
            return [$constraints];
        }

        if (is_array($constraints)) {
            /** @var list<Constraint> */
            return array_values($constraints);
        }

        throw new \InvalidArgumentException(
            'EntityType::getConstraints() values must be a Constraint or a list of Constraint objects.',
        );
    }

    /**
     * @param FieldDefinitionInterface|array<string, mixed> $definition
     */
    private static function normalizeFieldDefinition(string $fieldName, FieldDefinitionInterface|array $definition): FieldDefinitionInterface
    {
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

        return new FieldDefinition(
            name: $fieldName,
            type: (string) ($definition['type'] ?? 'string'),
            cardinality: (int) ($definition['cardinality'] ?? 1),
            settings: $settings,
            targetEntityTypeId: '',
            required: (bool) ($definition['required'] ?? false),
            read: ($definition['read'] ?? null) instanceof FieldReadLevel ? $definition['read'] : null,
        );
    }
}
