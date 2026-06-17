<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Import;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Migration\Discovery\MigrationRegistry;
use Waaseyaa\Migration\Exception\MigrationConcurrencyException;
use Waaseyaa\Migration\MigrationIdMap;
use Waaseyaa\Migration\MigrationRunState;
use Waaseyaa\Migration\Runner\MigrationLock;

/**
 * `bin/waaseyaa import:reset <migration-id>` — clear the id-map (and
 * progress run-state) for one migration without touching destination
 * entities (FR-036).
 *
 * Use case: operators recovering from drift — the destination entities
 * exist but the source-side keys have shifted, so future re-imports
 * should re-create rows under fresh `source_id_hash`es. Compare with
 * `import:rollback`, which DELETES the destination entities.
 *
 * Destructive operation: requires `--confirm` to proceed. Without it,
 * the command prints a count and a hint and exits 0.
 *
 * Exit codes:
 *  - 0 — always (operator-driven destructive op; no per-record failures).
 *  - 2 — usage error (missing argument, unknown migration id).
 *  - 3 — concurrency lock contention (FR-061): another `import:*` process
 *    already holds the per-migration filesystem lock.
 *
 * @api
 *
 * @spec FR-036 — `import:reset` clears id-map without touching entities
 * @spec FR-061 — per-migration concurrency lock
 */
final class ImportResetCommand
{
    /**
     * @param \Closure(string): MigrationLock $lockFactory Builds a per-migration {@see MigrationLock}; injected so the lock directory is resolved by the service provider.
     */
    public function __construct(
        private readonly MigrationRegistry $registry,
        private readonly MigrationIdMap $idMap,
        private readonly MigrationRunState $runState,
        private readonly \Closure $lockFactory,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $migrationId = $io->argument('migration_id');
        if (!\is_string($migrationId) || $migrationId === '') {
            $io->error('import:reset: argument "migration_id" is required.');
            return 2;
        }

        if (!$this->registry->has($migrationId)) {
            $io->error(\sprintf('import:reset: unknown migration "%s".', $migrationId));
            return 2;
        }

        $confirmed = (bool) $io->option('confirm');

        $idMapCount = $this->idMap->countForMigration($migrationId);

        // The no-confirm preview path is read-only — no lock needed; it
        // only reads the id-map row count and prints a warning.
        if (!$confirmed) {
            $io->writeln(\sprintf(
                'WARNING: import:reset will delete %d id-map entr%s for migration "%s".',
                $idMapCount,
                $idMapCount === 1 ? 'y' : 'ies',
                $migrationId,
            ));
            $io->writeln('Destination entities will NOT be touched.');
            $io->writeln('Re-runs will re-import as new entities.');
            $io->writeln('Re-run with --confirm to proceed.');
            return 0;
        }

        $lock = ($this->lockFactory)($migrationId);

        try {
            $lock->acquire();
        } catch (MigrationConcurrencyException $e) {
            $io->error($e->getMessage());
            return 3;
        }

        try {
            $idMapDeleted = $this->idMap->deleteAllForMigration($migrationId);
            $runStateDeleted = $this->runState->deleteAllForMigration($migrationId);

            $io->writeln(\sprintf(
                '%s: reset complete (%d id-map rows + %d run-state rows deleted)',
                $migrationId,
                $idMapDeleted,
                $runStateDeleted,
            ));

            return 0;
        } finally {
            $lock->release();
        }
    }
}
