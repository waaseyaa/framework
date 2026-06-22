<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Config;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Config\Exception\ConfigSerializationException;
use Waaseyaa\Config\Sync\ConfigExporter;
use Waaseyaa\Config\Sync\ConfigExportFileResult;

/**
 * `bin/waaseyaa config:export [--diff] [--dry-run]` — write the active store
 * out to the sync store.
 *
 * Per-file output renders as `<status> <filename>` (status one of `created`,
 * `updated`, `unchanged`); the trailing summary line is
 * `X created, Y updated, Z unchanged.` per FR-020.
 *
 * Exit codes (FR-021 / contracts/cli-namespace.md):
 *
 *  - `0` — success.
 *  - `1` — any {@see ConfigSerializationException} thrown from the exporter
 *    (e.g. malformed entity field value, unknown field type). The exception
 *    message reaches stderr and the run aborts; files written before the
 *    failure remain on disk — operators inspect the per-file output to
 *    identify the failing entity.
 *
 * Reserved-verb collision handling (FR-048) lives at the kernel level and
 * does not concern this thin handler. This class is a member of the reserved
 * verb namespace itself, so it cannot collide with itself.
 *
 * @spec FR-017 — orchestrator iterates the active store
 * @spec FR-018 — --diff (mtime-aware writes)
 * @spec FR-019 — --dry-run (no filesystem effects)
 * @spec FR-020 — summary line
 * @spec FR-021 — exit-code policy + command registration
 * @api
 */
final class ConfigExportCommand
{
    public function __construct(
        private readonly ConfigExporter $exporter,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $diff = (bool) $io->option('diff');
        $dryRun = (bool) $io->option('dry-run');

        try {
            $result = $this->exporter->export(diff: $diff, dryRun: $dryRun);
        } catch (ConfigSerializationException $e) {
            // FR-021: any serialization error → exit 1. The exception's
            // message already carries entity / field context (set by the
            // factory methods on ConfigSerializationException).
            $io->error(sprintf('config:export: %s', $e->getMessage()));
            return 1;
        }

        foreach ($result->files as $file) {
            $io->writeln($this->renderLine($file, $dryRun));
        }

        $summary = $result->summary();
        if ($dryRun) {
            $summary = '[dry-run] ' . $summary;
        }
        $io->writeln($summary);

        return 0;
    }

    private function renderLine(ConfigExportFileResult $file, bool $dryRun): string
    {
        // STATUS_* values are already the operator-facing verbs ('created',
        // 'updated', 'unchanged'). The match is retained as a typed gate so
        // a future status addition surfaces here at PHPStan time rather than
        // silently leaking an internal constant value.
        $verb = match ($file->status) {
            ConfigExportFileResult::STATUS_CREATED => 'created',
            ConfigExportFileResult::STATUS_UPDATED => 'updated',
            ConfigExportFileResult::STATUS_UNCHANGED => 'unchanged',
        };

        $line = sprintf('%s %s', $verb, $file->filename);
        if ($dryRun && $file->status !== ConfigExportFileResult::STATUS_UNCHANGED) {
            $line = '[dry-run] ' . $line;
        }

        return $line;
    }
}
