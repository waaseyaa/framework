<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Classification\Job;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Field\Entity\RetentionPolicy;

/**
 * Bounded, keyset-batched scan over the classification-labelled entities of a
 * single entity type, used by the retention jobs instead of an unbounded
 * loadMultiple() over every labelled row (L1-field.md M1).
 *
 * Pushes the age cutoff and (when a policy reduces to one pattern) the label
 * predicate into SQL, then pages by ascending id with `id > lastId` so the scan
 * is robust under in-loop deletion (PurgeJob) — offset paging would skip rows
 * after deletes. Never hydrates more than {@see self::$batchSize} rows at once.
 *
 * @internal
 */
final class RetentionScanner
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly int $batchSize = 500,
    ) {}

    /**
     * Derive the sound single-column label narrowing for a policy, or null when
     * the policy cannot be narrowed to one AND-able condition (bare `*`, empty
     * prefix, or multiple patterns) — in which case the caller keeps exists()
     * and the policy's own matchesLabel() filter does the exact matching.
     *
     * @return array{operator: string, value: string}|null
     */
    public static function labelCondition(RetentionPolicy $policy): ?array
    {
        $patterns = $policy->getAppliesTo();
        if (count($patterns) !== 1) {
            return null;
        }
        $pattern = $patterns[0];
        if ($pattern === '*' || $pattern === '') {
            return null;
        }
        if (str_ends_with($pattern, '*')) {
            $prefix = substr($pattern, 0, -1);
            if ($prefix === '') {
                return null;
            }
            return ['operator' => 'STARTS_WITH', 'value' => $prefix];
        }
        return ['operator' => '=', 'value' => $pattern];
    }

    /**
     * Yield classification-labelled entities of $entityTypeId in keyset batches.
     * Entity types lacking the classification_label / created_at columns are
     * skipped silently (the query throws and we stop) — matching the prior
     * find*()-returns-[] behaviour.
     *
     * @param array{operator: string, value: string}|null $labelCondition
     * @return iterable<EntityInterface>
     */
    public function scan(string $entityTypeId, ?string $cutoff = null, ?array $labelCondition = null): iterable
    {
        try {
            $storage = $this->entityTypeManager->getStorage($entityTypeId);
        } catch (\Throwable) {
            return;
        }

        $lastId = null;

        do {
            try {
                // System retention sweep: no user account in scope. accessCheck(false)
                // is the intentional opt-out (see CLAUDE.md §"Unbound getQuery() gate").
                $query = $storage->getQuery()
                    ->accessCheck(false)
                    ->exists('classification_label');

                if ($cutoff !== null) {
                    $query->condition('created_at', $cutoff, '<');
                }
                if ($labelCondition !== null) {
                    $query->condition('classification_label', $labelCondition['value'], $labelCondition['operator']);
                }
                if ($lastId !== null) {
                    $query->condition('id', $lastId, '>');
                }

                $ids = $query->sort('id', 'ASC')->range(0, $this->batchSize)->execute();
            } catch (\Throwable) {
                // Entity type lacks a classification_label / created_at column —
                // not a participant in classification. Skip silently.
                return;
            }

            if ($ids === []) {
                return;
            }

            $entities = $storage->loadMultiple($ids);
            // $ids is ordered id-ASC by the query; advance the keyset cursor to the max.
            $lastId = $ids[array_key_last($ids)];

            foreach ($ids as $id) {
                if (isset($entities[$id])) {
                    yield $entities[$id];
                }
            }
        } while (count($ids) === $this->batchSize);
    }
}
