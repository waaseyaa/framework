<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Validation;

use Symfony\Component\Validator\Constraint;
use Waaseyaa\Entity\EntityTypeInterface;

/**
 * Resolves the constraint map used by {@see \Waaseyaa\EntityStorage\EntityRepository} on save.
 *
 * Precedence: for each field name present in {@see EntityTypeInterface::getConstraints()}, those
 * constraints **replace** any constraints derived from {@see EntityTypeInterface::getFieldDefinitions()}.
 * Manual keys that do not appear in field definitions are still applied. Fields only described in
 * field definitions use derived constraints only.
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
    public static function forEntityType(EntityTypeInterface $entityType, ?array $fieldDefinitions = null): array
    {
        $merged = FieldDefinitionConstraintBuilder::build($fieldDefinitions ?? $entityType->getFieldDefinitions());

        foreach ($entityType->getConstraints() as $field => $manual) {
            $merged[$field] = self::normalizeToList($manual);
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
}
