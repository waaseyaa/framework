<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Config;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Config\Sync\ConfigImportEntryResult;
use Waaseyaa\Config\Sync\ConfigImporter;

/**
 * `bin/waaseyaa config:import [--dry-run] [--delete-orphans] [--halt-on-error] [--no-dependency-check]`
 * — apply the sync store to the active store in DAG order.
 *
 * Per-entity output: `<verb> <ref>` where `<verb>` is one of `imported`,
 * `unchanged`, `deleted`, or `failed` (matches the spec §7.2 wording).
 * Trailing summary line: `N created, M updated, K deleted, J failed, P unchanged.`
 * (FR-029, contracts/cli-namespace.md).
 *
 * Exit codes (FR-029, contracts/cli-namespace.md):
 *  - `0` — every entry applied (`J failed === 0`).
 *  - `1` — any per-entity failure, or a dependency-resolver error pre-import.
 *
 * Reserved-verb collision handling (FR-048) lives at the kernel level.
 *
 * @spec FR-022 — DAG-ordered apply
 * @spec FR-023 — per-entity transactions (delegated to {@see \Waaseyaa\Config\Sync\ConfigImportApplyHookInterface})
 * @spec FR-024 / FR-025 — --dry-run with per-entity preview
 * @spec FR-026 — orphan handling (warn / --delete-orphans)
 * @spec FR-027 / FR-028 — typed failures + --halt-on-error / --no-dependency-check
 * @spec FR-029 — command registration + summary + exit code
 * @api
 */
final class ConfigImportCommand
{
    public function __construct(
        private readonly ConfigImporter $importer,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $dryRun = (bool) $io->option('dry-run');
        $deleteOrphans = (bool) $io->option('delete-orphans');
        $haltOnError = (bool) $io->option('halt-on-error');
        $noDependencyCheck = (bool) $io->option('no-dependency-check');

        $result = $this->importer->import(
            dryRun: $dryRun,
            deleteOrphans: $deleteOrphans,
            haltOnError: $haltOnError,
            noDependencyCheck: $noDependencyCheck,
        );

        foreach ($result->entries as $entry) {
            $line = $this->renderLine($entry, $dryRun);
            if ($entry->isFailure()) {
                $io->error($line);
            } else {
                $io->writeln($line);
            }
        }

        $summary = $result->summary();
        if ($dryRun) {
            $summary = '[dry-run] ' . $summary;
        }
        $io->writeln($summary);

        return $result->failureCount() === 0 ? 0 : 1;
    }

    private function renderLine(ConfigImportEntryResult $entry, bool $dryRun): string
    {
        // PHPStan widens the constant-string return of `status` to `string` so
        // an explicit `default` arm is required even though the importer only
        // emits documented STATUS_* values.
        $verb = match ($entry->status) {
            ConfigImportEntryResult::STATUS_CREATED,
            ConfigImportEntryResult::STATUS_UPDATED => 'imported',
            ConfigImportEntryResult::STATUS_UNCHANGED => 'unchanged',
            ConfigImportEntryResult::STATUS_DELETED => 'deleted',
            ConfigImportEntryResult::STATUS_FAILED => 'failed',
            default => $entry->status,
        };

        $suffix = match ($entry->status) {
            ConfigImportEntryResult::STATUS_CREATED => ' (created)',
            ConfigImportEntryResult::STATUS_UPDATED => ' (updated)',
            default => '',
        };

        $line = sprintf('%s %s%s', $verb, $entry->ref, $suffix);
        if ($entry->reason !== null && $entry->reason !== '') {
            $line .= sprintf(': %s', $entry->reason);
        }
        if ($dryRun) {
            $line = '[dry-run] ' . $line;
        }

        return $line;
    }
}
