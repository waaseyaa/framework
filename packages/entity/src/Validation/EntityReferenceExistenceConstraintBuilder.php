<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Validation;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\All;
use Waaseyaa\Entity\Repository\EntityIdentifierResolver;
use Waaseyaa\Field\FieldDefinitionInterface;
use Waaseyaa\Validation\ConstraintFactory;

/**
 * Derives {@see \Waaseyaa\Validation\Constraint\EntityExists} constraints for
 * `entity_reference` field definitions on the active save-time validation path.
 *
 * Resolution is access-neutral ({@see EntityIdentifierResolver}); violation
 * messages follow the EntityExists contract and do not disclose whether a
 * hidden row exists.
 */
final class EntityReferenceExistenceConstraintBuilder
{
    public const MISSING_RESOLVER_MESSAGE = 'Save-time validation is active for entity type "%s" but no entity-reference existence resolver is configured.';

    public const MISSING_TARGET_MESSAGE = 'Entity reference field "%s" requires a non-empty target_entity_type_id when save-time validation is active.';

    /**
     * @param array<string, FieldDefinitionInterface|array<string, mixed>> $fieldDefinitions
     */
    public static function definitionsRequireReferenceResolver(array $fieldDefinitions): bool
    {
        foreach ($fieldDefinitions as $definition) {
            if (self::isEntityReference($definition)) {
                return true;
            }
        }

        return false;
    }

    public static function missingResolverMessage(string $entityTypeId): string
    {
        return sprintf(self::MISSING_RESOLVER_MESSAGE, $entityTypeId);
    }

    /**
     * @return list<Constraint>
     */
    public static function existenceConstraints(
        string $fieldName,
        FieldDefinitionInterface $definition,
        EntityIdentifierResolver $resolver,
    ): array {
        $targetType = self::resolveTargetEntityTypeId($fieldName, $definition);
        $exists = ConstraintFactory::entityExists(
            $targetType,
            new EntityReferenceExistenceChecker(
                $resolver,
                $targetType,
                $definition->getCardinality() === 1,
            ),
        );

        if ($definition->getCardinality() === 1) {
            return [$exists];
        }

        return [new All(constraints: [$exists])];
    }

    /**
     * @param FieldDefinitionInterface|array<string, mixed> $definition
     */
    private static function isEntityReference(FieldDefinitionInterface|array $definition): bool
    {
        if ($definition instanceof FieldDefinitionInterface) {
            return $definition->getType() === 'entity_reference';
        }

        return ($definition['type'] ?? '') === 'entity_reference';
    }

    private static function resolveTargetEntityTypeId(string $fieldName, FieldDefinitionInterface $definition): string
    {
        $settings = $definition->getSettings();
        $rawTarget = $settings['target_entity_type_id']
            ?? $settings['targetEntityTypeId']
            ?? $settings['target_type']
            ?? null;
        if (!is_string($rawTarget)) {
            throw new \LogicException(sprintf(self::MISSING_TARGET_MESSAGE, $fieldName));
        }

        $target = trim($rawTarget);
        if ($target === '') {
            throw new \LogicException(sprintf(self::MISSING_TARGET_MESSAGE, $fieldName));
        }

        return $target;
    }
}
