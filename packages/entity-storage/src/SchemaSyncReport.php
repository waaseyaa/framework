<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

/**
 * Outcome of an {@see EntitySchemaSyncRunner} run.
 *
 * `created` lists the base tables that did not exist before the run (and were
 * materialized, unless this was a dry run); `existing` lists the base tables
 * that were already present (idempotent no-ops). The split lets callers print a
 * meaningful summary and lets tests assert idempotency (a second run reports an
 * empty `created`).
 *
 * @api
 */
final class SchemaSyncReport
{
    /**
     * @param list<string> $created  Entity-type ids whose base table was absent before the run.
     * @param list<string> $existing Entity-type ids whose base table already existed.
     */
    public function __construct(
        public readonly array $created,
        public readonly array $existing,
        public readonly bool $dryRun,
    ) {}

    public function changed(): bool
    {
        return $this->created !== [];
    }

    public function total(): int
    {
        return count($this->created) + count($this->existing);
    }
}
