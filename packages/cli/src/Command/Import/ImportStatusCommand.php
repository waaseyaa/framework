<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Import;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Migration\Discovery\MigrationRegistry;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\MigrationIdMap;
use Waaseyaa\Migration\MigrationRunState;

/**
 * `bin/waaseyaa import:status [<migration-id>]` — surface per-migration state.
 *
 * Columns rendered per spec §9.2:
 *
 *   - `STATE` is computed from `migration_id_map` row count vs source count
 *     (`pending` / `partial` / `complete`).
 *
 *   - `TOTAL` is the source plugin's reported `count()` (or `?` if unknown).
 *
 *   - `IMPORTED` is {@see MigrationIdMap::countForMigration()} — the cheapest
 *     row-level signal available without touching the destination storage.
 *
 *   - `FAILED` / `SKIPPED` come from {@see MigrationRunState::countByStatus()}.
 *     Records that have never been touched do not show up here — the columns
 *     reflect the LATEST per-record outcome (FR-038).
 *
 *   - `LAST RUN` is {@see MigrationIdMap::maxLastImportedAt()}.
 *
 * Exit code is always 0; `import:status` is informational.
 *
 * @spec FR-034 — import:status
 * @spec FR-038 — populated FAILED / SKIPPED columns
 * @api
 */
final class ImportStatusCommand
{
    public function __construct(
        private readonly MigrationRegistry $registry,
        private readonly MigrationIdMap $idMap,
        private readonly MigrationRunState $runState,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $filter = $io->argument('migration_id');
        $filter = \is_string($filter) && $filter !== '' ? $filter : null;

        $definitions = $this->registry->all();
        if ($filter !== null) {
            $definitions = \array_values(\array_filter(
                $definitions,
                static fn(MigrationDefinition $d): bool => $d->id === $filter,
            ));
            if ($definitions === []) {
                $io->error(\sprintf('import:status: unknown migration "%s".', $filter));
                return 2;
            }
        }

        // Header row matches spec §9.2 column ordering. Widths picked so the
        // common identifiers (e.g. `wp_comments_to_engagement`) fit without
        // wrapping; identifiers longer than 30 chars overflow gracefully (the
        // table is meant for operators, not downstream parsers — a `--format=json`
        // mode is tracked as a separate enhancement).
        $io->writeln(\sprintf(
            '%-30s  %-10s  %6s  %8s  %6s  %7s  %s',
            'ID',
            'STATE',
            'TOTAL',
            'IMPORTED',
            'FAILED',
            'SKIPPED',
            'LAST RUN',
        ));

        foreach ($definitions as $definition) {
            $row = $this->statusRow($definition);
            $io->writeln(\sprintf(
                '%-30s  %-10s  %6s  %8s  %6s  %7s  %s',
                $row['id'],
                $row['state'],
                $row['total'],
                $row['imported'],
                $row['failed'],
                $row['skipped'],
                $row['last_run'],
            ));
        }

        return 0;
    }

    /**
     * Compute one display row for a single migration. Returns string-valued
     * cells so the calling sprintf renders a stable layout regardless of
     * whether a number is known.
     *
     * @return array{id: string, state: string, total: string, imported: string, failed: string, skipped: string, last_run: string}
     */
    private function statusRow(MigrationDefinition $definition): array
    {
        // Source `count()` is allowed to throw or return null per FR-005;
        // coerce both to "unknown".
        try {
            $sourceCount = $definition->source->count();
        } catch (\Throwable) {
            $sourceCount = null;
        }

        $importedCount = $this->idMap->countForMigration($definition->id);
        $lastRun = $this->idMap->maxLastImportedAt($definition->id);

        // FR-038 — populate FAILED / SKIPPED from `migration_run_state`.
        // Records that have never been touched do not contribute; the counts
        // reflect the LATEST per-record outcome.
        $bucket = $this->runState->countByStatus($definition->id);

        $state = match (true) {
            $importedCount === 0 && $bucket['error'] === 0 => 'pending',
            $sourceCount !== null && $importedCount >= $sourceCount => 'complete',
            default => 'partial',
        };

        return [
            'id' => $definition->id,
            'state' => $state,
            'total' => $sourceCount === null ? '-' : (string) $sourceCount,
            'imported' => (string) $importedCount,
            'failed' => (string) $bucket['error'],
            'skipped' => (string) $bucket['skipped'],
            'last_run' => $lastRun ?? '-',
        ];
    }
}
