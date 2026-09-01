<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

/**
 * Outcome of an {@see EntitySchemaSyncRunner} run.
 *
 * `created` lists the base tables that did not exist before the run (and were
 * materialized, unless this was a dry run); `existing` lists the base tables
 * that were already present. `altered` is the subset of `existing` whose
 * synchronization adds columns, indexes, or other physical schema to that
 * already-present table (materialized, unless this was a dry run) — a
 * pre-existing table is not automatically an untouched one (#2732). The split
 * lets callers print a meaningful summary and lets tests assert idempotency (a
 * true no-op run reports an empty `created` and an empty `altered`).
 *
 * @api
 */
final class SchemaSyncReport
{
    /**
     * @param list<string> $created  Entity-type ids whose base table was absent before the run.
     * @param list<string> $existing Entity-type ids whose base table already existed before the run.
     * @param list<string> $altered  Entity-type ids drawn from $existing whose synchronization adds
     *   columns, indexes, or other physical schema to their already-present table.
     */
    public function __construct(
        public readonly array $created,
        public readonly array $existing,
        public readonly bool $dryRun,
        public readonly array $altered = [],
    ) {}

    /** True when this run created a table, or added schema to one that already existed. */
    public function changed(): bool
    {
        return $this->created !== [] || $this->altered !== [];
    }

    public function total(): int
    {
        return count($this->created) + count($this->existing);
    }

    /**
     * Entity-type ids in $existing that received no changes: a genuine no-op.
     *
     * @return list<string>
     */
    public function unchanged(): array
    {
        return array_values(array_diff($this->existing, $this->altered));
    }
}
