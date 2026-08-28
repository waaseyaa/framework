<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Entity;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\EntityValues;
use Waaseyaa\Entity\Exception\FieldReadDenied;
use Waaseyaa\Entity\Exception\MissingFieldReadContext;

/**
 * Shared field-projection steps for entity tools that expose or search stored
 * field values (EntityReadTool, EntitySearchTool): (1) drop hardcoded
 * credential keys, (2) drop fields whose FieldDefinition marks them internal,
 * (3) omit fields the WP4 accessor denies. Steps (1) and (2) are the same
 * boundary the JSON:API serializer enforces before attributes; step (3)
 * matches ResourceSerializer's per-field FieldReadDenied catch.
 *
 * Deliberately does NOT apply the per-account FieldAccessPolicy filter — that
 * step lives on {@see \Waaseyaa\AI\Tools\AbstractAgentTool::applyFieldAccessFilter()}
 * and callers run it themselves after enumerating names, so credentials never
 * reach the policy layer at all (defense in depth, mirrors EntityReadTool's
 * original three-step order).
 */
final class EntityFieldRedaction
{
    /**
     * Field names never returned, even without a FieldDefinition — defense in
     * depth for raw `_data` credential keys (mirrors ResourceSerializer).
     *
     * @var list<string>
     */
    public const array ALWAYS_INTERNAL_FIELDS = ['pass', 'legacy_pass', 'password', 'password_hash'];

    private function __construct() {}

    /**
     * Enumerate ordinary-readable field names without obtaining their values.
     *
     * @return list<string>
     */
    public static function ordinaryFieldNames(EntityTypeManagerInterface $entityTypeManager, EntityInterface $entity): array
    {
        $names = EntityValues::ordinaryFieldNames($entity);

        return array_keys(self::stripInternal($entityTypeManager, $entity, array_fill_keys($names, true)));
    }

    /**
     * Cast-aware projection that maps a denied or unscoped field read to
     * omission — the same contract JSON:API applies on attributes
     * (`ResourceSerializer::attributesFromEntity()`).
     *
     * The WP4 accessor is the final Protected-read authority. A legacy
     * {@see \Waaseyaa\Access\FieldAccessPolicyInterface} may be Neutral while
     * that authority denies; omit the field rather than turning a
     * view-authorized entity into `INTERNAL_ERROR`. The field is never named
     * in an error envelope, so this does not reopen the existence oracle
     * {@see EntityReadTool} closed for absent vs view-forbidden entities.
     *
     * @param list<string> $fieldNames
     * @return array<string, mixed>
     */
    public static function toReadableCastAwareMap(EntityInterface $entity, array $fieldNames): array
    {
        $map = [];
        foreach ($fieldNames as $fieldName) {
            try {
                $map[$fieldName] = $entity->get($fieldName);
            } catch (FieldReadDenied | MissingFieldReadContext) {
                continue;
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function stripInternal(EntityTypeManagerInterface $entityTypeManager, EntityInterface $entity, array $values): array
    {
        if ($values === []) {
            return $values;
        }

        foreach (self::ALWAYS_INTERNAL_FIELDS as $credentialKey) {
            unset($values[$credentialKey]);
        }

        $fieldDefinitions = $entityTypeManager->resolveFieldDefinitions(
            $entity->getEntityTypeId(),
            $entity->bundle(),
        );
        foreach (array_keys($values) as $name) {
            if (isset($fieldDefinitions[$name]) && $fieldDefinitions[$name]->getSetting('internal') === true) {
                unset($values[$name]);
            }
        }

        return $values;
    }
}
