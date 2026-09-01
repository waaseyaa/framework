<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\EntitySchemaSyncRunner;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * `schema:sync` — materialize the storage schema for every registered entity type.
 *
 * Boots inside the normal console kernel (so all service-provider and
 * app-defined entity types are registered), enumerates the EntityTypeManager
 * definitions, and ensures each one's tables exist via the hardened
 * {@see EntitySchemaSyncRunner} (base table, translation/revision tables, and
 * per-bundle subtables via the field registry). Idempotent — a second run is a
 * no-op. With `--dry-run` it reports what would be created without writing.
 *
 * This closes the app-entity persistence gap: an app can register an entity
 * type and get its table created + migrated cleanly on deploy, instead of the
 * raw-table workaround.
 *
 * On a database platform other than SQLite (or a connection where a
 * migration is already applying), whether an already-existing table needs
 * additive column/index work cannot be previewed ahead of applying it
 * ({@see SchemaSyncReport::$indeterminate}). This handler reports that
 * explicitly rather than silently claiming the table is either "altered" or
 * "up to date" — doing so on supported production platforms (MySQL, MariaDB,
 * PostgreSQL) was the #2732 defect.
 *
 * @api
 */
final class SchemaSyncHandler
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly DatabaseInterface $database,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $dryRun = (bool) $io->option('dry-run');

        $runner = new EntitySchemaSyncRunner(
            $this->database,
            $this->entityTypeManager->getFieldRegistry(),
            $this->logger ?? new NullLogger(),
        );

        $report = $runner->run($this->entityTypeManager->getDefinitions(), $dryRun);

        if ($report->total() === 0) {
            $io->writeln('No registered entity types — nothing to sync.');
            return 0;
        }

        $unchanged = $report->unchanged();
        $indeterminate = $report->indeterminate;

        // A table already existing is not the same as that table needing no
        // work: `changed()` also covers columns/indexes registered since the
        // last sync (#2732). Only report "nothing to do" when it genuinely
        // is — which excludes indeterminate ids: "cannot be previewed" is not
        // "confirmed up to date".
        if (!$report->changed() && $indeterminate === []) {
            $io->writeln(sprintf(
                '%s: all %d registered entity table(s) already exist and are up to date. Nothing to change.',
                $dryRun ? '--dry-run' : 'Schema in sync',
                $report->total(),
            ));
            return 0;
        }

        if ($dryRun) {
            if ($report->created !== []) {
                $io->writeln(sprintf('--dry-run: would create %d table(s):', count($report->created)));
                foreach ($report->created as $table) {
                    $io->writeln(sprintf('  + %s', $table));
                }
            }
            if ($report->altered !== []) {
                $io->writeln(sprintf('--dry-run: would alter %d existing table(s) (add columns/indexes):', count($report->altered)));
                foreach ($report->altered as $table) {
                    $io->writeln(sprintf('  ~ %s', $table));
                }
            }
            if ($indeterminate !== []) {
                $io->writeln(sprintf(
                    '--dry-run: %d existing table(s); pending column/index work cannot be previewed on this database platform — apply to find out:',
                    count($indeterminate),
                ));
                foreach ($indeterminate as $table) {
                    $io->writeln(sprintf('  ? %s', $table));
                }
            }
            if ($unchanged !== []) {
                $io->writeln(sprintf('%d table(s) already exist and are up to date.', count($unchanged)));
            }
            return 0;
        }

        if ($report->created !== []) {
            $io->writeln(sprintf('Created %d table(s):', count($report->created)));
            foreach ($report->created as $table) {
                $io->writeln(sprintf('  + %s', $table));
            }
        }
        if ($report->altered !== []) {
            $io->writeln(sprintf('Altered %d existing table(s) (added columns/indexes):', count($report->altered)));
            foreach ($report->altered as $table) {
                $io->writeln(sprintf('  ~ %s', $table));
            }
        }
        if ($indeterminate !== []) {
            $io->writeln(sprintf(
                'Synced %d existing table(s); pending column/index work could not be previewed on this database platform before applying.',
                count($indeterminate),
            ));
            foreach ($indeterminate as $table) {
                $io->writeln(sprintf('  ? %s', $table));
            }
        }
        if ($unchanged !== []) {
            $io->writeln(sprintf('%d table(s) already existed and needed no changes.', count($unchanged)));
        }
        $io->writeln('Schema sync complete.');

        return 0;
    }
}
