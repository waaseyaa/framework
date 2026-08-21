<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Entity;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\RevisionRestoreChangedFields;

/**
 * Field-level write guard shared by the two whole-row revision-restore tools
 * (EntityRollbackTool, EntitySetCurrentRevisionTool).
 *
 * @api
 *
 * Both write the ENTIRE target-revision row back over the current entity, so
 * restoring an old revision can silently re-apply a privileged field change
 * (e.g. user.roles) the calling account could never make through
 * entity.update directly — reopening the self-escalation class entity.update
 * already closed via {@see \Waaseyaa\AI\Tools\AbstractAgentTool::requireFieldEditAccess()}
 * (#1638). Computing only the fields the restore would actually CHANGE (vs.
 * authorizing the whole target-revision field set) lets a legitimate restore
 * that leaves privileged fields untouched still succeed.
 *
 * The comparison itself is the package-layer-correct
 * {@see RevisionRestoreChangedFields} helper so Admin restore cannot drift.
 */
final class EntityRevisionRestoreGuard
{
    private function __construct() {}

    /**
     * @return list<string> Exact changed content names; no value leaves the comparison authority.
     */
    public static function changedFieldNames(EntityInterface $current, EntityInterface $target): array
    {
        return RevisionRestoreChangedFields::names($current, $target);
    }

    /**
     * Third-party compatibility only. Framework entities use the closed name-only comparator.
     *
     * @return array<string, mixed>
     */
    public static function values(EntityInterface $entity): array
    {
        return RevisionRestoreChangedFields::legacyValues($entity);
    }

    /**
     * @param array<array-key, mixed> $currentValues
     * @param array<array-key, mixed> $targetValues
     * @return array<string, mixed>
     */
    public static function changedValues(array $currentValues, array $targetValues): array
    {
        return RevisionRestoreChangedFields::legacyChangedValues($currentValues, $targetValues);
    }
}
