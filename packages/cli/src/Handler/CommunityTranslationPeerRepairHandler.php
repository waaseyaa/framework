<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Tenancy\CommunityTranslationPeerRepairer;

/**
 * `tenancy:repair-translation-peers <entity_type>` operator repair.
 *
 * Dynamically invoked by HandlerCommand.
 * @api
 */
final readonly class CommunityTranslationPeerRepairHandler
{
    public function __construct(
        private EntityTypeManager $entityTypeManager,
        private CommunityTranslationPeerRepairer $repairer,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $typeId = (string) $io->argument('entity_type');
        if (!$this->entityTypeManager->hasDefinition($typeId)) {
            $io->error(sprintf('Unknown entity type "%s".', $typeId));
            return 1;
        }

        try {
            $report = $this->repairer->repair(
                $this->entityTypeManager->getDefinition($typeId),
                (bool) $io->option('dry-run'),
            );
        } catch (\InvalidArgumentException|\LogicException $e) {
            $io->error($e->getMessage());
            return 1;
        }

        if ((bool) $io->option('json')) {
            $io->writeln(json_encode($report->toArray(), \JSON_THROW_ON_ERROR));
            return 0;
        }

        $io->writeln(sprintf(
            '%s translation-peer tenancy repair for "%s": examined %d, eligible %d, repaired %d, skipped %d.',
            $report->dryRun ? 'Dry-run' : 'Completed',
            $typeId,
            $report->examined,
            $report->eligible,
            $report->repaired,
            $report->skipped,
        ));

        return 0;
    }
}
