<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

/**
 * Canonical changed-field set for copy-forward revision restore.
 *
 * Restore writes the whole target snapshot, so every materially changed
 * content field that rollback will write must be authorized. Revision
 * bookkeeping and live-preserved keys are excluded because rollback never
 * copies them from the historical snapshot. Privilege-bearing and workflow
 * fields remain in the set.
 *
 * @api
 */
final class RevisionRestoreChangedFields
{
    /**
     * Revision-table bookkeeping columns that ride a loadRevision() snapshot.
     *
     * @var list<string>
     */
    public const array METADATA_KEYS = [
        'revision_id', 'revision_created', 'revision_log', 'revision_author',
        'is_default_revision', 'is_latest_revision', 'entity_id',
    ];

    /**
     * Credential keys never gated as restore-edit authority.
     *
     * @var list<string>
     */
    public const array CREDENTIAL_KEYS = ['pass', 'password', 'password_hash'];

    /**
     * Values rollback/setCurrentRevision preserve from the live base row.
     *
     * @var list<string>
     */
    public const array LIVE_PRESERVED_KEYS = [
        'published_revision_id',
        'status',
        ...self::CREDENTIAL_KEYS,
    ];

    private function __construct() {}

    /**
     * @return list<string> Exact changed content names; no value leaves the comparison authority.
     */
    public static function names(EntityInterface $current, EntityInterface $target): array
    {
        if ($current instanceof EntityBase || $target instanceof EntityBase) {
            if (!$current instanceof EntityBase || !$target instanceof EntityBase) {
                throw new \LogicException('A framework revision comparison requires two EntityBase views.');
            }
            $fields = array_values(array_filter(
                array_values(array_unique([
                    ...EntityValues::fieldNames($current),
                    ...EntityValues::fieldNames($target),
                ])),
                static fn(string $field): bool => !in_array($field, self::METADATA_KEYS, true)
                    && !in_array($field, self::LIVE_PRESERVED_KEYS, true),
            ));

            return new EntityValueComparator()->changedFieldNames($current, $target, $fields);
        }

        return array_keys(self::legacyChangedValues(self::legacyValues($current), self::legacyValues($target)));
    }

    /**
     * Third-party EntityInterface compatibility only.
     *
     * @return array<string, mixed>
     */
    public static function legacyValues(EntityInterface $entity): array
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
     * @param array<array-key, mixed> $currentValues
     * @param array<array-key, mixed> $targetValues
     * @return array<string, mixed>
     */
    public static function legacyChangedValues(array $currentValues, array $targetValues): array
    {
        $changed = [];
        foreach (array_unique([...array_keys($currentValues), ...array_keys($targetValues)]) as $field) {
            if (!is_string($field)
                || in_array($field, self::METADATA_KEYS, true)
                || in_array($field, self::LIVE_PRESERVED_KEYS, true)
            ) {
                continue;
            }
            $currentHas = array_key_exists($field, $currentValues);
            $targetHas = array_key_exists($field, $targetValues);
            if ($currentHas !== $targetHas || ($currentHas && $currentValues[$field] !== $targetValues[$field])) {
                $changed[$field] = $targetHas ? $targetValues[$field] : null;
            }
        }

        return $changed;
    }
}
