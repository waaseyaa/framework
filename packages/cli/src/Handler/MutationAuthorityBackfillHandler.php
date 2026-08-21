<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\EntityStorage\Exception\MutationAuthorityBackfillException;
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
        $failedEntityTypes = [];
        $createdByType = [];
        foreach (array_keys($definitions) as $entityTypeId) {
            try {
                $repository = $this->entityTypeManager->getRepository($entityTypeId);
            } catch (\Throwable) {
                $createdByType[$entityTypeId] = 0;
                $failedEntityTypes[] = $entityTypeId;
                continue;
            }
            if (!$repository instanceof LegacyMutationAuthorityBackfillRepositoryInterface) {
                $skippedEntityTypes[] = $entityTypeId;
                continue;
            }
            try {
                $supported = $repository->supportsMutationAuthorityBackfill();
            } catch (\Throwable) {
                $createdByType[$entityTypeId] = null;
                $failedEntityTypes[] = $entityTypeId;
                continue;
            }
            if (!$supported) {
                $skippedEntityTypes[] = $entityTypeId;
                continue;
            }
            $repositories[$entityTypeId] = $repository;
        }

        foreach ($repositories as $entityTypeId => $repository) {
            try {
                $createdByType[$entityTypeId] = $repository->backfillMutationAuthorities($reason);
            } catch (MutationAuthorityBackfillException $e) {
                $createdByType[$entityTypeId] = $e->committedCount;
                $failedEntityTypes[] = $entityTypeId;
            } catch (\Throwable) {
                $createdByType[$entityTypeId] = null;
                $failedEntityTypes[] = $entityTypeId;
            }
        }
        ksort($createdByType);
        $created = in_array(null, $createdByType, true) ? null : array_sum($createdByType);
        $exitCode = $failedEntityTypes === [] ? 0 : 1;

        if ((bool) $io->option('json')) {
            $io->writeln(json_encode([
                'reason_sha256' => hash('sha256', $reason),
                'created' => $created,
                'entity_types' => $createdByType,
                'skipped_entity_types' => $skippedEntityTypes,
                'failed_entity_types' => $failedEntityTypes,
            ], JSON_THROW_ON_ERROR));

            return $exitCode;
        }

        $io->writeln(sprintf(
            'Mutation-authority backfill: created=%s',
            $created === null ? 'unknown' : (string) $created,
        ));
        $io->writeln(sprintf('Reason SHA-256: %s', hash('sha256', $reason)));
        foreach ($createdByType as $entityTypeId => $count) {
            $io->writeln(sprintf('  %s: %s', $entityTypeId, $count === null ? 'unknown' : (string) $count));
        }
        foreach ($skippedEntityTypes as $entityTypeId) {
            $io->writeln(sprintf('  %s: skipped (repository is outside the framework repair boundary)', $entityTypeId));
        }
        foreach ($failedEntityTypes as $entityTypeId) {
            $io->writeln(sprintf('  %s: failed (see the reported committed count)', $entityTypeId));
        }

        return $exitCode;
    }
}
