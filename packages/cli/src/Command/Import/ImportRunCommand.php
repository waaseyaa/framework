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
 * `bin/waaseyaa import:run <migration-id>` — execute one migration end-to-end.
 *
 * Exit codes per `kitty-specs/.../contracts/cli-runner.md`:
 *
 *  - 0 — full success (every record processed; zero failures).
 *  - 1 — partial: ≥1 per-record error captured; the run finished naturally.
 *  - 2 — usage error (unknown migration id, missing argument, malformed
 *    flag).
 *  - 3 — concurrency lock contention (FR-061): another process already
 *    holds the per-migration filesystem lock at
 *    `storage/migration-locks/<id>.lock`.
 *  - 5 — run-level fatal: source plugin crashed mid-iteration, framework
 *    fault, or `--halt-on-error` short-circuit on a per-record error. The
 *    contract surface deliberately collapses error-rate halt (code 4 in the
 *    contract) into 5 in WP06 because the error-rate threshold lives on
 *    {@see \Waaseyaa\Migration\MigrationDefinition} and is plumbed in by
 *    WP07's resume loop, not the WP06 minimum runner.
 *
 * The command is intentionally thin: it builds {@see RunOptions} from the
 * parsed CLI flags, hands them to {@see MigrationRunner::run()}, and renders
 * the resulting {@see RunReport} on stdout. All real work lives in the
 * runner.
 *
 * @spec FR-032 — import:run
 * @spec FR-039 — --dry-run
 * @spec FR-040 — --limit
 * @spec FR-047 — --halt-on-error
 * @spec FR-061 — per-migration concurrency lock
 * @api
 */
final class ImportRunCommand
{
    /** Cap on how many per-record errors render before the "...more" footer. */
    private const int ERROR_RENDER_CAP = 20;

    /**
     * @param \Closure(string): MigrationLock $lockFactory Builds a per-migration {@see MigrationLock}; injected so the lock directory is resolved by the service provider, not this thin CLI handler.
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
            $io->error('import:run: argument "migration_id" is required.');
            return 2;
        }

        if (!$this->registry->has($migrationId)) {
            $io->error(\sprintf('import:run: unknown migration "%s".', $migrationId));
            return 2;
        }

        try {
            $options = $this->buildOptions($io);
        } catch (\InvalidArgumentException $e) {
            $io->error('import:run: ' . $e->getMessage());
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
                $report = $this->runner->run($migrationId, $options);
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

        $runId = $io->option('run-id');
        $parsedRunId = null;
        if (\is_string($runId) && $runId !== '') {
            $parsedRunId = $runId;
        }

        return new RunOptions(
            dryRun: $dryRun,
            haltOnError: $haltOnError,
            limit: $parsedLimit,
            runId: $parsedRunId,
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
                '  ... %d more error(s) (full audit trail lands in migration_run_state — WP07).',
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
