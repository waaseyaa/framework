<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Import;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Migration\Discovery\MigrationRegistry;
use Waaseyaa\Migration\Exception\MigrationAbortedException;
use Waaseyaa\Migration\Exception\MigrationConcurrencyException;
use Waaseyaa\Migration\Runner\MigrationLock;
use Waaseyaa\Migration\Runner\MigrationRunner;
use Waaseyaa\Migration\Runner\RecordError;
use Waaseyaa\Migration\Runner\RunOptions;
use Waaseyaa\Migration\Runner\RunReport;

/**
 * `bin/waaseyaa import:resume <migration-id>` — resume the most recent run
 * of one migration from its last completed checkpoint (FR-037).
 *
 * The runner reads the prior `run_id` from `migration_run_state` and reuses
 * it so the second physical invocation is part of the same logical run.
 * Records up to and including the prior `MAX(position)` are skipped without
 * touching the destination.
 *
 * Exit codes mirror `import:run`:
 *  - 0 — full success (every remaining record processed; zero failures).
 *  - 1 — partial: ≥1 per-record error captured, OR no prior run exists for
 *    the migration (operator-actionable error).
 *  - 2 — usage error (unknown migration id, missing argument, malformed flag).
 *  - 3 — concurrency lock contention (FR-061): another process already
 *    holds the per-migration filesystem lock.
 *  - 5 — run-level fatal: source plugin crashed mid-iteration, framework
 *    fault, or `--halt-on-error` short-circuit on a per-record error.
 *
 * The command is intentionally thin: it builds {@see RunOptions} from the
 * parsed CLI flags, hands them to {@see MigrationRunner::runResume()}, and
 * renders the resulting {@see RunReport} on stdout. All real work lives in
 * the runner.
 *
 * @spec FR-037 — resume from the prior run's checkpoint
 * @spec FR-061 — per-migration concurrency lock
 * @api
 */
final class ImportResumeCommand
{
    /** Cap on how many per-record errors render before the "...more" footer. */
    private const int ERROR_RENDER_CAP = 20;

    /**
     * @param \Closure(string): MigrationLock $lockFactory Builds a per-migration {@see MigrationLock}; injected so the lock directory is resolved by the service provider.
     */
    public function __construct(
        private readonly MigrationRunner $runner,
        private readonly MigrationRegistry $registry,
        private readonly \Closure $lockFactory,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $migrationId = $io->argument('migration_id');
        if (!\is_string($migrationId) || $migrationId === '') {
            $io->error('import:resume: argument "migration_id" is required.');
            return 2;
        }

        if (!$this->registry->has($migrationId)) {
            $io->error(\sprintf('import:resume: unknown migration "%s".', $migrationId));
            return 2;
        }

        try {
            $options = $this->buildOptions($io);
        } catch (\InvalidArgumentException $e) {
            $io->error('import:resume: ' . $e->getMessage());
            return 2;
        }

        $lock = ($this->lockFactory)($migrationId);

        try {
            $lock->acquire();
        } catch (MigrationConcurrencyException $e) {
            $io->error($e->getMessage());
            return 3;
        }

        try {
            try {
                $report = $this->runner->runResume($migrationId, $options);
            } catch (\InvalidArgumentException $e) {
                // No prior run recorded for the migration — operator must run
                // `import:run <id>` first.
                $io->error('import:resume: ' . $e->getMessage());
                return 1;
            } catch (MigrationAbortedException $e) {
                $this->renderReport($io, $e->report);
                $io->error(\sprintf('Aborted: %s', $e->getMessage()));
                return 5;
            }

            $this->renderReport($io, $report);

            return $report->failed > 0 ? 1 : 0;
        } finally {
            $lock->release();
        }
    }

    /**
     * Build {@see RunOptions} from the parsed CLI input. Surfaces validation
     * errors as `\InvalidArgumentException` so the caller can return a
     * usage-error exit code.
     *
     * Note: `--run-id` is intentionally NOT exposed — the runner reads the
     * prior run id from `migration_run_state` for resume (FR-037 contract:
     * the operator does not pick the run id).
     */
    private function buildOptions(SymfonyCommandIO $io): RunOptions
    {
        $dryRun = (bool) $io->option('dry-run');
        $haltOnError = (bool) $io->option('halt-on-error');

        $limit = $io->option('limit');
        $parsedLimit = null;
        if ($limit !== null && $limit !== false && $limit !== '') {
            if (!\is_numeric($limit)) {
                throw new \InvalidArgumentException(
                    \sprintf('--limit must be a positive integer, got %s.', \var_export($limit, true)),
                );
            }
            $parsedLimit = (int) $limit;
        }

        return new RunOptions(
            dryRun: $dryRun,
            haltOnError: $haltOnError,
            limit: $parsedLimit,
            // runId is supplied by MigrationRunner::runResume() — operators
            // cannot override the prior run's id.
            runId: null,
        );
    }

    private function renderReport(SymfonyCommandIO $io, RunReport $report): void
    {
        $io->writeln($report->summaryLine());

        if ($report->errors === []) {
            return;
        }

        $io->writeln('');
        $io->writeln('Errors:');
        $io->writeln(\sprintf(
            '  %-12s  %-22s  %-40s  message',
            'STAGE',
            'CODE',
            'SOURCE',
        ));

        $rendered = 0;
        foreach ($report->errors as $error) {
            if ($rendered >= self::ERROR_RENDER_CAP) {
                break;
            }
            $io->writeln(\sprintf(
                '  %-12s  %-22s  %-40s  %s',
                $error->stage,
                $error->code,
                $this->sourceHandle($error),
                $error->message,
            ));
            $rendered++;
        }

        $remaining = \count($report->errors) - $rendered;
        if ($remaining > 0) {
            $io->writeln(\sprintf(
                '  ... %d more error(s) (see migration_run_state for the full audit trail).',
                $remaining,
            ));
        }
    }

    /**
     * Compose a short "source" handle for the error table — prefer the
     * sourceField when known, otherwise the truncated source id hash.
     */
    private function sourceHandle(RecordError $error): string
    {
        if ($error->sourceField !== null) {
            return $error->sourceField;
        }

        return \substr($error->sourceIdHash, 0, 16);
    }
}
