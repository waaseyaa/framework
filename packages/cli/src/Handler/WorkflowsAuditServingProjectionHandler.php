<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Workflows\Binding\WorkflowBindingResolver;
use Waaseyaa\Workflows\Read\WorkflowEntitySnapshotReader;

/** Report-first, confirmation-bound repair for impossible workflow serving projections. @api */
final class WorkflowsAuditServingProjectionHandler
{
    private readonly WorkflowEntitySnapshotReader $snapshotReader;

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly ConfigFactoryInterface $configFactory,
        private readonly WorkflowBindingResolver $bindingResolver,
        ?WorkflowEntitySnapshotReader $snapshotReader = null,
    ) {
        $this->snapshotReader = $snapshotReader ?? new WorkflowEntitySnapshotReader();
    }

    public function execute(SymfonyCommandIO $io): int
    {
        $repairOption = $io->option('repair');
        $repairId = \is_string($repairOption) && $repairOption !== '' ? $repairOption : null;
        $confirmationOption = $io->option('confirm');
        $confirmation = \is_string($confirmationOption) && $confirmationOption !== '' ? $confirmationOption : null;
        if (($repairId === null) !== ($confirmation === null)) {
            $io->error('--repair=<entity-id> and --confirm=<finding-fingerprint> must be supplied together.');

            return 1;
        }

        $assignments = $this->configFactory->get('workflows.assignments')->getRawData();
        $entityTypeIds = [];
        foreach ($assignments as $binding => $workflowId) {
            if (!\is_string($binding) || !\is_string($workflowId) || $workflowId === '' || !\str_contains($binding, '.')) {
                $io->error('workflows.assignments is malformed; no rows were modified.');

                return 1;
            }
            $entityTypeIds[\substr($binding, 0, (int) \strrpos($binding, '.'))] = true;
        }

        /** @var list<array<string, int|string>> $findings */
        $findings = [];
        /** @var list<string> $faults */
        $faults = [];
        $examined = 0;
        $bound = 0;

        foreach (\array_keys($entityTypeIds) as $entityTypeId) {
            if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
                $faults[] = \sprintf('binding names unknown entity type "%s"', $entityTypeId);
                continue;
            }
            $definition = $this->entityTypeManager->getDefinition($entityTypeId);
            if (!$definition->isRevisionable() || $definition->isTranslatable()) {
                $faults[] = \sprintf('binding for "%s" has unsupported storage shape', $entityTypeId);
                continue;
            }

            $repository = $this->entityTypeManager->getRepository($entityTypeId);
            // Trusted operator audit: every persisted candidate must be seen.
            $ids = $repository->getQuery()->accessCheck(false)->execute();
            foreach ($ids as $rawId) {
                $id = (string) $rawId;
                ++$examined;
                $served = $repository->find($id);
                $working = $repository->loadWorkingCopy($id);
                if ($served === null || $working === null) {
                    $faults[] = \sprintf('%s/%s vanished during audit', $entityTypeId, $id);
                    continue;
                }

                try {
                    $workflow = $this->bindingResolver->resolve($entityTypeId, $served->bundle());
                } catch (\Throwable) {
                    $faults[] = \sprintf('%s/%s binding or workflow cannot be resolved', $entityTypeId, $id);
                    continue;
                }
                if ($workflow === null) {
                    continue;
                }
                ++$bound;

                $servedSnapshot = $this->snapshotReader->read($served);
                $workingSnapshot = $this->snapshotReader->read($working);
                $pointer = $repository->loadPublishedRevision($id);
                $pointerSnapshot = $pointer !== null ? $this->snapshotReader->read($pointer) : null;
                $authorityState = $pointerSnapshot !== null
                    ? $pointerSnapshot->workflowState
                    : $workingSnapshot->workflowState;
                $state = $authorityState !== null ? $workflow->getState($authorityState) : null;
                if ($state === null) {
                    $faults[] = \sprintf('%s/%s has an absent or unknown authoritative workflow state', $entityTypeId, $id);
                    continue;
                }

                $expectedStatus = $state->published ? 1 : 0;
                $expectedRevisionId = $pointerSnapshot?->revisionId;
                $revisionMismatch = $expectedRevisionId !== null
                    && (string) $servedSnapshot->revisionId !== (string) $expectedRevisionId;
                if ($servedSnapshot->status === $expectedStatus && !$revisionMismatch) {
                    // This deliberately includes draft/review tips over a live pointer.
                    continue;
                }

                $finding = [
                    'entity_type' => $entityTypeId,
                    'entity_id' => $id,
                    'binding' => $entityTypeId . '.' . $served->bundle() . '=>' . $workflow->id(),
                    'working_revision' => (string) ($workingSnapshot->revisionId ?? 'none'),
                    'working_state' => $workingSnapshot->workflowState ?? 'none',
                    'published_revision' => (string) ($pointerSnapshot !== null ? $pointerSnapshot->revisionId : 'none'),
                    'published_state' => $pointerSnapshot !== null ? $pointerSnapshot->workflowState ?? 'none' : 'none',
                    'current_status' => $servedSnapshot->status,
                    'current_revision' => (string) ($servedSnapshot->revisionId ?? 'none'),
                    'proposed_status' => $expectedStatus,
                    'proposed_revision' => (string) ($expectedRevisionId ?? 'none'),
                    'aggregate_version' => $served instanceof EntityBase && $served->mutationToken() !== null
                        ? $served->mutationToken()->aggregateVersion
                        : 0,
                    'repairable' => $pointerSnapshot !== null ? 1 : 0,
                ];
                $finding['fingerprint'] = \substr(\hash('sha256', \json_encode($finding, JSON_THROW_ON_ERROR)), 0, 24);
                $findings[] = $finding;
            }
        }

        foreach ($findings as $finding) {
            $io->writeln('FINDING ' . \json_encode($finding, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
        $io->writeln(\sprintf(
            'Serving-projection audit: examined %d row(s), %d bound row(s), %d finding(s), %d fault(s); report only.',
            $examined,
            $bound,
            \count($findings),
            \count($faults),
        ));

        if ($faults !== []) {
            foreach ($faults as $fault) {
                $io->error('FAIL-CLOSED: ' . $fault);
            }
            $io->error('Audit authority was incomplete; no rows were modified.');

            return 1;
        }
        if ($repairId === null) {
            return 0;
        }

        $matches = \array_values(\array_filter(
            $findings,
            static fn(array $finding): bool => $finding['entity_id'] === $repairId,
        ));
        if (\count($matches) !== 1) {
            $io->error(\sprintf('Repair target "%s" is not exactly one current finding; no rows were modified.', $repairId));

            return 1;
        }
        $finding = $matches[0];
        if (!\hash_equals((string) $finding['fingerprint'], (string) $confirmation)) {
            $io->error('Confirmation does not match the current finding fingerprint; no rows were modified.');

            return 1;
        }
        if ($finding['repairable'] !== 1) {
            $io->error('The finding has no published pointer and cannot be repaired automatically; no rows were modified.');

            return 1;
        }

        $entityTypeId = (string) $finding['entity_type'];
        $repository = $this->entityTypeManager->getRepository($entityTypeId);
        $fresh = $repository->find($repairId);
        if (!$fresh instanceof EntityBase || $fresh->mutationToken() === null) {
            $io->error('A fresh aggregate mutation token is unavailable; no rows were modified.');

            return 1;
        }
        if ($fresh->mutationToken()->aggregateVersion !== (int) $finding['aggregate_version']) {
            $io->error('The aggregate changed after the confirmed report; no rows were modified. Re-run the audit.');

            return 1;
        }
        $pointerRevisionId = (int) $finding['published_revision'];
        try {
            // Re-publishing the existing pointer is the repository-owned projection
            // rebuild: workflow guards derive authority from that revision and the
            // aggregate CAS prevents a concurrent edit from being overwritten.
            $repairedPointer = $repository->setPublishedRevision($repairId, $pointerRevisionId, $fresh->mutationToken());
        } catch (\Throwable) {
            $io->error('The guarded projection rebuild failed; no correction was confirmed.');

            return 1;
        }

        $afterEntity = $repository->find($repairId);
        if ($afterEntity === null) {
            $io->error('Post-repair verification could not reload the materialized projection.');

            return 1;
        }
        $after = $this->snapshotReader->read($afterEntity);
        if ($after->status !== (int) $finding['proposed_status']
            || (string) $after->revisionId !== (string) $finding['proposed_revision']
        ) {
            $io->error('Post-repair verification failed; recovery is required before another attempt.');

            return 1;
        }
        $io->writeln('REPAIRED ' . \json_encode([
            'entity_type' => $entityTypeId,
            'entity_id' => $repairId,
            'before_status' => $finding['current_status'],
            'before_revision' => $finding['current_revision'],
            'after_status' => $after->status,
            'after_revision' => (string) $after->revisionId,
            'published_revision' => $finding['published_revision'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return 0;
    }
}
