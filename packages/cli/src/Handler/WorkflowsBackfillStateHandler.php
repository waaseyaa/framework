<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\RevisionableEntityInterface;
use Waaseyaa\Entity\RevisionableInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Workflows\Workflow;

/**
 * `workflows:backfill-state <entity_type> <workflow_id> [--bundle=]` — stamp
 * a `workflow_state` onto every existing row of an entity type/bundle that
 * does not yet carry one (CW-v1 WP-2 task 2.7, docs/specs/content-workflow.md,
 * docs/specs/operations-playbooks.md "binding-activation runbook").
 *
 * Workflow binding is BINDING-scoped (`workflows.assignments`), not
 * framework-scoped (decision 4, wp2-preamble): the framework has no way to
 * know in advance which workflow a site will bind an entity type/bundle to,
 * so this backfill is an explicit, operator-run CLI step rather than a
 * migration. It is deliberately **binding-agnostic**: it does not consult
 * `workflows.assignments` at all, and does not require a binding to exist —
 * the runbook runs it BEFORE the binding is added (see "activation runbook"),
 * precisely so `WorkflowBindingResolver`/`WorkflowStateGuard` are not yet
 * live for the type/bundle being backfilled and cannot interfere (force a
 * revision, deny a "transition") while this system-level pass runs.
 *
 * Target-state rule (verbatim, brief): for every row missing a non-empty
 * `workflow_state`, set it to the workflow's published-flagged
 * `default_revision: true` state (the state both {@see WorkflowState::$published}
 * and {@see WorkflowState::$defaultRevision} are true for — 'published' on
 * the shipped `editorial` workflow) when the row's own `status` column reads
 * published (`1`); otherwise set it to the workflow's `initial_state`. This
 * mirrors {@see \Waaseyaa\Workflows\Listener\WorkflowStateGuard}'s pointer
 * fallback: a legacy row's only truthful signal pre-backfill is its stored
 * `status`, so the backfilled state is derived from that, not guessed from
 * an unknowable prior editorial history (e.g. `archived` rows that never
 * carried `workflow_state` are indistinguishable from `draft` by `status`
 * alone and are conservatively backfilled to `initial_state`, exactly like
 * every other unpublished row).
 *
 * No revision churn: each save explicitly disables new-revision creation
 * (`setNewRevision(false)`) regardless of the entity type's
 * `revisionDefault` — a bulk state stamp is not new editorial content and
 * must not spawn a revision per row. `EntityRepository::save()`'s
 * non-revision-creating branch updates the base row AND the entity's own
 * CURRENT/tip revision row in the same write (see
 * `EntityRepository::doSave()`) — it does not retroactively touch a
 * *different*, older published-pointer revision row when the current tip
 * and the published pointer have already diverged (a forward draft in
 * progress). That divergence cannot exist on data reached by this runbook
 * step, because forward drafts are a WP-2 mechanic that ships together with
 * this very backfill and the runbook runs the backfill immediately after
 * `revisions:enable`, before any binding makes transitions/forward-drafts
 * reachable at all. Should this command ever run against data where that
 * divergence already exists, the safety net is
 * {@see \Waaseyaa\Workflows\Listener\WorkflowStateGuard::pointerStatus()}:
 * a published-pointer revision whose `workflow_state` is still unknown to
 * the workflow falls back to copying its own stored `status` column rather
 * than trusting an unrelated state — so an unstamped pointer row never
 * reports a wrong derived status.
 *
 * Entity queries use `->accessCheck(false)` — this is a system-level,
 * operator-run backfill with no acting account to bind (`bin/check-getquery-
 * bindings` requires this justification comment on every unbound chain).
 *
 * @api
 */
final class WorkflowsBackfillStateHandler
{
    private const int SAMPLE_LIMIT = 5;

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $entityTypeId = (string) $io->argument('entity_type');
        $workflowId = (string) $io->argument('workflow_id');
        $bundleOption = $io->option('bundle');
        $bundle = \is_string($bundleOption) && $bundleOption !== '' ? $bundleOption : null;
        $dryRun = (bool) $io->option('dry-run');

        if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
            $io->error(\sprintf('Unknown entity type "%s".', $entityTypeId));

            return 1;
        }

        if (!$this->entityTypeManager->hasDefinition('workflow')) {
            $io->error('The "workflow" entity type is not registered; is waaseyaa/workflows booted?');

            return 1;
        }

        $workflow = $this->entityTypeManager->getRepository('workflow')->find($workflowId);
        if (!$workflow instanceof Workflow) {
            $io->error(\sprintf('Unknown workflow "%s".', $workflowId));

            return 1;
        }

        $definition = $this->entityTypeManager->getDefinition($entityTypeId);
        $bundleKey = $definition->getKeys()['bundle'] ?? null;
        if ($bundle !== null && $bundleKey === null) {
            $io->error(\sprintf('Entity type "%s" has no bundle key; --bundle cannot be applied.', $entityTypeId));

            return 1;
        }

        $repository = $this->entityTypeManager->getRepository($entityTypeId);

        [$publishedDefaultState, $initialState] = $this->resolveTargetStates($workflow);

        // System-level backfill: there is no acting account for this bulk
        // operator command, and every row must be seen regardless of any
        // view-access policy — accessCheck(false) is the documented opt-out
        // (bin/check-getquery-bindings).
        $query = $repository->getQuery()->accessCheck(false);
        if ($bundle !== null && $bundleKey !== null) {
            $query->condition($bundleKey, $bundle);
        }
        $ids = $query->execute();

        $examined = 0;
        $backfilled = 0;
        $skipped = 0;
        $failed = 0;
        /** @var array<string, int> $targetCounts */
        $targetCounts = [];
        /** @var array<string, list<string>> $targetSamples */
        $targetSamples = [];
        /** @var list<string> $failures */
        $failures = [];

        foreach ($ids as $id) {
            $id = (string) $id;
            $entity = $repository->find($id);
            if ($entity === null) {
                continue;
            }
            ++$examined;

            $currentState = $entity->get('workflow_state');
            if (\is_string($currentState) && $currentState !== '') {
                ++$skipped;
                continue;
            }

            $targetState = $this->targetStateFor($entity, $publishedDefaultState, $initialState);
            $targetCounts[$targetState] = ($targetCounts[$targetState] ?? 0) + 1;
            if (\count($targetSamples[$targetState] ?? []) < self::SAMPLE_LIMIT) {
                $targetSamples[$targetState][] = $id;
            }

            if ($dryRun) {
                continue;
            }

            try {
                $entity->set('workflow_state', $targetState);
                $this->disableNewRevision($entity);
                // No validation: this is a system field stamp on legacy
                // content, not a user-facing edit — validating unrelated
                // fields on old rows would reject rows the operator cannot
                // fix from this command.
                $repository->save($entity, false);
                ++$backfilled;
            } catch (\Throwable $e) {
                ++$failed;
                $failures[] = \sprintf('id %s: %s', $id, $e->getMessage());
                $this->logger?->error(\sprintf(
                    'workflows:backfill-state failed for %s/%s: %s',
                    $entityTypeId,
                    $id,
                    $e->getMessage(),
                ));
            }
        }

        $label = $bundle !== null ? \sprintf('%s.%s', $entityTypeId, $bundle) : $entityTypeId;

        if ($dryRun) {
            $io->writeln(\sprintf(
                '--dry-run: %s against workflow "%s" — %d row(s) examined, %d would be backfilled, %d already set (no-op).',
                $label,
                $workflowId,
                $examined,
                $examined - $skipped,
                $skipped,
            ));
            foreach ($targetCounts as $state => $count) {
                $sampleIds = implode(', ', $targetSamples[$state] ?? []);
                $io->writeln(\sprintf('  -> %d row(s) would be set to "%s" (sample ids: %s)', $count, $state, $sampleIds));
            }

            return 0;
        }

        $io->writeln(\sprintf(
            '%s against workflow "%s": examined %d, backfilled %d, skipped %d, failed %d.',
            $label,
            $workflowId,
            $examined,
            $backfilled,
            $skipped,
            $failed,
        ));
        foreach ($targetCounts as $state => $count) {
            $io->writeln(\sprintf('  -> %d row(s) targeted for "%s"', $count, $state));
        }

        if ($failed > 0) {
            foreach ($failures as $failure) {
                $io->error($failure);
            }

            return 1;
        }

        return 0;
    }

    /**
     * Locate the workflow's published-flagged `default_revision: true`
     * state (there is at most one on a well-formed workflow) and its
     * `initial_state`.
     *
     * @return array{0: ?string, 1: string}
     */
    private function resolveTargetStates(Workflow $workflow): array
    {
        $publishedDefault = null;
        foreach ($workflow->getStates() as $state) {
            if ($state->published && $state->defaultRevision) {
                $publishedDefault = $state->id;
                break;
            }
        }

        return [$publishedDefault, $workflow->getInitialState()];
    }

    private function targetStateFor(EntityInterface $entity, ?string $publishedDefaultState, string $initialState): string
    {
        if ($publishedDefaultState === null) {
            return $initialState;
        }

        return (int) $entity->get('status') === 1 ? $publishedDefaultState : $initialState;
    }

    /**
     * Duck-checks both revision contracts, mirroring
     * {@see \Waaseyaa\Workflows\Listener\WorkflowStateGuard::forceNewRevision()}
     * (same pattern, inverse intent: force revision creation OFF for this
     * system-level stamp, regardless of `revisionDefault` or any earlier
     * `setNewRevision(true)`).
     */
    private function disableNewRevision(EntityInterface $entity): void
    {
        if ($entity instanceof RevisionableInterface) {
            $entity->setNewRevision(false);

            return;
        }

        if ($entity instanceof RevisionableEntityInterface && \method_exists($entity, 'setNewRevision')) {
            $entity->setNewRevision(false);
        }
    }
}
