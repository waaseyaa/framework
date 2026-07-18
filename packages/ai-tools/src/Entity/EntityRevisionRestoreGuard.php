<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Entity;

use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityValueComparator;
use Waaseyaa\Entity\EntityValues;

/**
 * Field-level write guard shared by the two whole-row revision-restore tools
 * (EntityRollbackTool, EntitySetCurrentRevisionTool).
 *
 * Both write the ENTIRE target-revision row back over the current entity, so
 * restoring an old revision can silently re-apply a privileged field change
 * (e.g. user.roles) the calling account could never make through
 * entity.update directly — reopening the self-escalation class entity.update
 * already closed via {@see \Waaseyaa\AI\Tools\AbstractAgentTool::requireFieldEditAccess()}
 * (#1638). Computing only the fields the restore would actually CHANGE (vs.
 * authorizing the whole target-revision field set) lets a legitimate restore
 * that leaves privileged fields untouched still succeed.
 */
final class EntityRevisionRestoreGuard
{
    /**
     * Revision-table bookkeeping columns that ride alongside real field
     * values on an {@see \Waaseyaa\EntityStorage\EntityRepository::loadRevision()}
     * result but are never themselves editable content fields — excluded so
     * they never spuriously appear in the "changed fields" set passed to
     * requireFieldEditAccess().
     *
     * @var list<string>
     */
    private const array METADATA_KEYS = [
        'revision_id', 'revision_created', 'revision_log', 'revision_author',
        'is_default_revision', 'is_latest_revision', 'entity_id',
    ];

    private function __construct() {}

    /**
     * @return list<string> Exact changed content names; no value leaves the comparison authority.
     */
    public static function changedFieldNames(EntityInterface $current, EntityInterface $target): array
    {
        if ($current instanceof EntityBase || $target instanceof EntityBase) {
            if (!$current instanceof EntityBase || !$target instanceof EntityBase) {
                throw new \LogicException('A framework revision comparison requires two EntityBase views.');
            }
            $fields = array_values(array_filter(
                EntityValues::fieldNames($target),
                static fn(string $field): bool => !in_array($field, self::METADATA_KEYS, true)
                    && !in_array($field, EntityFieldRedaction::ALWAYS_INTERNAL_FIELDS, true),
            ));

            return new EntityValueComparator()->changedFieldNames($current, $target, $fields);
        }

        return array_keys(self::changedValues(self::values($current), self::values($target)));
    }

    /**
     * Third-party compatibility only. Framework entities use the closed name-only comparator.
     *
     * @return array<string, mixed>
     */
    public static function values(EntityInterface $entity): array
    {
        $values = [];
        if (method_exists($entity, 'getValues')) {
            $curated = $entity->getValues();
            $values = is_array($curated) ? $curated : [];
        }
        if ($values === []) {
            $values = $entity->toArray();
        }

        return $values;
    }

    /**
     * Fields the restore would actually change: present in the target
     * revision with a value that differs from (or is absent on) the current
     * entity. Bookkeeping keys are excluded. Errs toward requiring MORE
     * access, never less — a field present in both with an identical value
     * is not re-authorized, but any type/value drift is treated as changed.
     *
     * Credential fields ({@see EntityFieldRedaction::ALWAYS_INTERNAL_FIELDS})
     * are excluded from the change set: they are unconditionally edit-Forbidden
     * for everyone (managed by dedicated flows, not the generic field surface —
     * see UserAccessPolicy::CREDENTIAL_FIELDS) and are NOT a privilege-
     * escalation vector, yet they ride every revision snapshot and the whole-row
     * restore writes them regardless. Gating the restore on them would only
     * break rollback/set-current across any password rotation for everyone,
     * administrators included. This mirrors how EntityFieldRedaction strips them
     * on the read/search path. Privilege-bearing fields (roles/permissions/…)
     * are deliberately NOT excluded — those are the real escalation vector and
     * carry an 'administer users' bypass, so the escalation guard is preserved.
     *
     * @param array<array-key, mixed> $currentValues
     * @param array<array-key, mixed> $targetValues
     * @return array<string, mixed>
     */
    public static function changedValues(array $currentValues, array $targetValues): array
    {
        $changed = [];
        foreach ($targetValues as $field => $value) {
            if (!is_string($field)
                || in_array($field, self::METADATA_KEYS, true)
                || in_array($field, EntityFieldRedaction::ALWAYS_INTERNAL_FIELDS, true)
            ) {
                continue;
            }
            if (!array_key_exists($field, $currentValues) || $currentValues[$field] !== $value) {
                $changed[$field] = $value;
            }
        }

        return $changed;
    }
}
