<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Sync;

/**
 * Immutable aggregate result of one {@see ConfigImporter::import()} run.
 *
 * Carries the per-entity outcomes and a precomputed summary that matches
 * the canonical CLI summary line (`N created, M updated, K deleted,
 * J failed, P unchanged.`) declared in
 * `contracts/cli-namespace.md` §`config:import`.
 *
 * Stability scope (charter §5.5): the class FQCN, public properties, and
 * `summary()` line format are on stable surface for `waaseyaa/config` v1.x.
 *
 * @api
 */
final readonly class ConfigImportResult
{
    /**
     * @param list<ConfigImportEntryResult> $entries Per-entity outcomes, in topological apply order.
     * @param bool                          $dryRun  True when the run did not perform writes.
     */
    public function __construct(
        public array $entries,
        public bool $dryRun = false,
        public ?string $generationId = null,
        public ?string $planHash = null,
    ) {}

    /**
     * Total failures across the run; the CLI maps `> 0` to exit code 1.
     */
    public function failureCount(): int
    {
        $count = 0;
        foreach ($this->entries as $entry) {
            if ($entry->isFailure()) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Canonical CLI summary line: `X created, Y updated, Z deleted, W failed, U unchanged.`
     */
    public function summary(): string
    {
        $counts = [
            ConfigImportEntryResult::STATUS_CREATED => 0,
            ConfigImportEntryResult::STATUS_UPDATED => 0,
            ConfigImportEntryResult::STATUS_DELETED => 0,
            ConfigImportEntryResult::STATUS_FAILED => 0,
            ConfigImportEntryResult::STATUS_UNCHANGED => 0,
        ];
        foreach ($this->entries as $entry) {
            $counts[$entry->status]++;
        }

        return sprintf(
            '%d created, %d updated, %d deleted, %d failed, %d unchanged.',
            $counts[ConfigImportEntryResult::STATUS_CREATED],
            $counts[ConfigImportEntryResult::STATUS_UPDATED],
            $counts[ConfigImportEntryResult::STATUS_DELETED],
            $counts[ConfigImportEntryResult::STATUS_FAILED],
            $counts[ConfigImportEntryResult::STATUS_UNCHANGED],
        );
    }
}
