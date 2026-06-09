<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

/**
 * Immutable value object holding the result of a revision pruning pass.
 *
 * Returned by {@see EntityRepository::pruneRevisions()}. When pruning is
 * disabled (the default — auto-pruning is a non-goal), `$pruned` is `0` and
 * `$skipped` carries a single entry explaining why.
 *
 * @api
 */
final class RevisionPruningReport
{
    /**
     * @param int      $candidatesFound Total revisions considered for pruning.
     * @param int      $pruned          Revisions actually deleted.
     * @param int      $retained        Revisions kept because they matched a policy rule.
     * @param string[] $skipped         Human-readable reasons for skipped operations.
     */
    public function __construct(
        public readonly int $candidatesFound = 0,
        public readonly int $pruned = 0,
        public readonly int $retained = 0,
        public readonly array $skipped = [],
    ) {}

    /**
     * Return a no-op report for a disabled pruner.
     */
    public static function disabled(): self
    {
        return new self(
            candidatesFound: 0,
            pruned: 0,
            retained: 0,
            skipped: ['Revision pruning is disabled; auto-pruning is a non-goal (spec §1.2).'],
        );
    }
}
