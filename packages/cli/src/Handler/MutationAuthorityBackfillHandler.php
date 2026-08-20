<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\EntityStorage\LegacyMutationAuthorityBackfillRepositoryInterface;

/**
 * Restricted, explicit repair for aggregate rows persisted before DB-03.
 *
 * @api Dynamically dispatched by the supported console command.
 */
final readonly class MutationAuthorityBackfillHandler
{
    public function __construct(private EntityTypeManagerInterface $entityTypeManager) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $reasonOption = $io->option('reason');
        $reason = is_string($reasonOption) ? trim($reasonOption) : '';
        if ($reason === '') {
            $io->error('A non-empty --reason is required for mutation-authority backfill.');

            return 2;
        }

        $definitions = $this->entityTypeManager->getDefinitions();
        ksort($definitions);

        /** @var array<string, EntityRepositoryInterface&LegacyMutationAuthorityBackfillRepositoryInterface> $repositories */
        $repositories = [];
        $skippedEntityTypes = [];
        foreach (array_keys($definitions) as $entityTypeId) {
            $repository = $this->entityTypeManager->getRepository($entityTypeId);
            if (!$repository instanceof LegacyMutationAuthorityBackfillRepositoryInterface) {
                $skippedEntityTypes[] = $entityTypeId;
                continue;
            }
            $repositories[$entityTypeId] = $repository;
        }

        $createdByType = [];
        $failedEntityTypes = [];
        foreach ($repositories as $entityTypeId => $repository) {
            try {
                $createdByType[$entityTypeId] = $repository->backfillMutationAuthorities($reason);
            } catch (\Throwable) {
                $createdByType[$entityTypeId] = 0;
                $failedEntityTypes[] = $entityTypeId;
            }
        }
        $created = array_sum($createdByType);
        $exitCode = $failedEntityTypes === [] ? 0 : 1;

        if ((bool) $io->option('json')) {
            $io->writeln(json_encode([
                'created' => $created,
                'entity_types' => $createdByType,
                'skipped_entity_types' => $skippedEntityTypes,
                'failed_entity_types' => $failedEntityTypes,
            ], JSON_THROW_ON_ERROR));

            return $exitCode;
        }

        $io->writeln(sprintf('Mutation-authority backfill: created=%d', $created));
        foreach ($createdByType as $entityTypeId => $count) {
            $io->writeln(sprintf('  %s: %d', $entityTypeId, $count));
        }
        foreach ($skippedEntityTypes as $entityTypeId) {
            $io->writeln(sprintf('  %s: skipped (repository is outside the framework repair boundary)', $entityTypeId));
        }
        foreach ($failedEntityTypes as $entityTypeId) {
            $io->writeln(sprintf('  %s: failed (no authority was established for this type)', $entityTypeId));
        }

        return $exitCode;
    }
}
