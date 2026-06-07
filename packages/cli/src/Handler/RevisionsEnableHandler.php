<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\CliIO;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\EntitySchemaSyncRunner;
use Waaseyaa\Foundation\Log\LoggerInterface;

/**
 * `revisions:enable <entity_type>` — make an existing entity type revisionable.
 *
 * Workflow: the operator flips the EntityType to `revisionable: true` in code
 * or config, then runs this command, which (1) ensures the revision table and
 * base `revision_id` pointer exist via the hardened schema sync, and (2)
 * backfills an initial revision for every existing row that lacks one. Both
 * steps are idempotent — re-running is safe.
 *
 * This turns "we have rows but no history" into "every row has revision 1 and
 * full history from here on", without the per-app raw-table workaround.
 *
 * @api
 */
final class RevisionsEnableHandler
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly DatabaseInterface $database,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function execute(CliIO $io): int
    {
        $typeId = (string) $io->argument('entity_type');

        if (!$this->entityTypeManager->hasDefinition($typeId)) {
            $io->error(sprintf('Unknown entity type "%s".', $typeId));
            return 1;
        }

        $type = $this->entityTypeManager->getDefinition($typeId);
        if (!$type->isRevisionable()) {
            $io->error(sprintf(
                'Entity type "%s" is not registered as revisionable. Set revisionable: true on its '
                . 'EntityType (code/config) first, then re-run.',
                $typeId,
            ));
            return 1;
        }

        $repository = $this->entityTypeManager->getRepository($typeId);
        if (!$repository instanceof EntityRepository) {
            $io->error('Revision backfill requires the SQL EntityRepository.');
            return 1;
        }

        if ((bool) $io->option('dry-run')) {
            $io->writeln(sprintf(
                '--dry-run: would ensure the revision schema for "%s" and backfill an initial revision for each row lacking one.',
                $typeId,
            ));
            return 0;
        }

        // 1. Ensure the revision table + base revision_id column exist.
        new EntitySchemaSyncRunner(
            $this->database,
            $this->entityTypeManager->getFieldRegistry(),
            $this->logger,
        )->run([$type]);
        $io->writeln(sprintf('Revision schema ensured for "%s".', $typeId));

        // 2. Backfill an initial revision for existing rows.
        $count = $repository->backfillInitialRevisions();
        $io->writeln(sprintf(
            'Revisions enabled for "%s": %d row(s) backfilled with an initial revision.',
            $typeId,
            $count,
        ));

        return 0;
    }
}
