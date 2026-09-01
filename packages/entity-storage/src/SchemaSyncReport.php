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
 * `indeterminate` is a further subset of `existing` — disjoint from `altered`
 * — whose additive-work status could not be determined on this database
 * platform (anything other than SQLite) or coordinator state (a mutation
 * already active on the connection): the read-only preview
 * {@see EntitySchemaSync::planMutatingEntityTypeIds()} relies on has no
 * equivalent there. This is a distinct outcome from both "altered" and
 * "unchanged" — reporting it as either would misrepresent what is actually
 * known, which is nothing (#2732). `changed()` deliberately ignores it: an
 * unknown must never present as a confirmed change.
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
     * @param list<string> $indeterminate Entity-type ids drawn from $existing whose additive-work
     *   status could not be determined on this database platform/coordinator state — neither a
     *   confirmed alteration nor a confirmed no-op. Disjoint from $altered.
     */
    public function __construct(
        public readonly array $created,
        public readonly array $existing,
        public readonly bool $dryRun,
        public readonly array $altered = [],
        public readonly array $indeterminate = [],
    ) {}

    /**
     * True when this run created a table, or added schema to one that already
     * existed. Indeterminacy alone — pending work that could not be previewed
     * on this platform — is never sufficient to report true; an unresolved
     * "we don't know" must not present as a confirmed change (#2732).
     */
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
     * Excludes both $altered and $indeterminate — an id whose status could
     * not be determined is not a confirmed no-op either (#2732).
     *
     * @return list<string>
     */
    public function unchanged(): array
    {
        return array_values(array_diff($this->existing, $this->altered, $this->indeterminate));
    }
}
