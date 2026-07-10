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
 * `workflows:backfill-state <entity_type> <workflow_id> [--bundle=]` —
 * stamps a `workflow_state` onto every existing row of an entity
 * type/bundle that does not yet carry one, AND establishes the published
 * revision pointer for every row that is (or becomes) published (CW-v1
 * WP-2 task 2.7 + WP-2 rework task 3, docs/specs/content-workflow.md,
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
 * Fail-fast on ambiguous workflow shapes (adversarial-panel critical fix):
 * a workflow may legally define no published+`default_revision: true` state
 * at all ({@see \Waaseyaa\Workflows\Validation\WorkflowValidator} accepts
 * e.g. a `published: true, default_revision: false` live state). On such a
 * workflow the status-derived routing above has no published target, so
 * genuinely-published rows would be silently mislabeled with
 * `initial_state`. The command therefore aborts (nonzero, zero writes,
 * dry-run included) when at least one status=1 row needs backfilling;
 * when none do, it proceeds — initial_state-only stamping is unambiguous —
 * with an explicit notice that no published-target state exists. Both real
 * and dry-run output always break the counts down per target state so the
 * routing is visible.
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
 * Pointer establishment (WP-2 rework task 3, review findings #3/#9): after
 * the state-stamp phase, a second phase establishes the published revision
 * pointer — {@see \Waaseyaa\Entity\Repository\EntityRepositoryInterface::setPublishedRevision()}
 * — on every EXAMINED row whose effective `workflow_state` (freshly stamped
 * by phase one, OR already set before this run — the WP-1 tail, i.e. a row
 * skipped by the state-stamp phase because it already carries a state)
 * equals the workflow's published+`default_revision: true` state. The
 * phase runs only when the entity type is revisionable AND that state was
 * resolved above ($publishedDefaultState !== null); on a non-revisionable
 * type or a workflow with no such state, pointer-dependent semantics are
 * undefined and the phase is skipped entirely — silently, not a no-op
 * attempt, because {@see \Waaseyaa\Entity\Repository\EntityRepositoryInterface::loadPublishedRevision()}
 * throws `\LogicException` on a type with no revision driver configured.
 * Idempotent: a row whose `loadPublishedRevision()` is already non-null is
 * left alone. A row that is revisionable at the TYPE level but has no
 * revision history yet (`revisions:enable` was never run for it) cannot
 * have a pointer established; it is skipped and counted separately
 * (reported in both dry-run and real output) so the operator notices,
 * rather than failing the whole command over something this command
 * cannot fix on its own. `setPublishedRevision()` is the sanctioned
 * pointer door: it dispatches `BeforeRevisionPointerMoveEvent` through the
 * same choke point every other pointer move uses, so
 * `WorkflowPointerMoveGuard` sees this backfill too — a no-op today
 * because the runbook runs this command before any binding exists; if an
 * operator mis-ordered the runbook and a binding IS already live, a guard
 * denial surfaces as an ordinary per-row failure via the same failure
 * accounting as the state-stamp phase (fail-loud is correct here, not a
 * bug in this command).
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

        // Phase 1 — classify only, zero writes. The write phase runs strictly
        // after the workflow-shape fail-fast check below, so an abort can
        // guarantee nothing was modified.
        $examined = 0;
        $skipped = 0;
        /** @var list<array{id: string, target: string, published: bool}> $pending */
        $pending = [];
        // Pointer-phase candidates (rework task 3, #3/#9): every EXAMINED
        // row whose EFFECTIVE workflow_state equals $publishedDefaultState —
        // newly-stamped rows (their computed 'target' below) and
        // already-stamped/skipped rows alike (the WP-1 tail).
        /** @var list<string> $publishedCandidateIds */
        $publishedCandidateIds = [];

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
                if ($publishedDefaultState !== null && $currentState === $publishedDefaultState) {
                    $publishedCandidateIds[] = $id;
                }
                continue;
            }

            $isPublished = (int) $entity->get('status') === 1;
            $target = $isPublished && $publishedDefaultState !== null ? $publishedDefaultState : $initialState;
            $pending[] = [
                'id' => $id,
                'target' => $target,
                'published' => $isPublished,
            ];
            if ($publishedDefaultState !== null && $target === $publishedDefaultState) {
                $publishedCandidateIds[] = $id;
            }
        }

        $label = $bundle !== null ? \sprintf('%s.%s', $entityTypeId, $bundle) : $entityTypeId;

        // Fail-fast (task 2.7 adversarial-panel critical fix): a workflow with
        // NO published+default_revision:true state is a shape WorkflowValidator
        // accepts, and on it the status-derived routing above sends EVERY row
        // — genuinely-published status=1 rows included — to initial_state.
        // Silently stamping published content as e.g. 'draft' and exiting 0 is
        // exactly the mislabeling this command exists to prevent, so: abort
        // (before the dry-run branch too — a dry run must surface the same
        // hard error) whenever at least one status=1 row needs backfilling.
        // With no such rows, initial_state-only stamping is unambiguous —
        // proceed, but say explicitly that no published-target state exists.
        if ($publishedDefaultState === null) {
            $publishedPending = \count(\array_filter($pending, static fn(array $p): bool => $p['published']));
            if ($publishedPending > 0) {
                $io->error(\sprintf(
                    'Workflow "%s" defines no state with published: true AND default_revision: true, but %d published '
                    . 'row(s) (status = 1) of %s are missing workflow_state. Refusing to stamp published content with '
                    . 'initial_state "%s". Fix the workflow definition (flag its live state default_revision: true), '
                    . 'or pre-stamp those rows manually, then re-run. No rows were modified.',
                    $workflowId,
                    $publishedPending,
                    $label,
                    $initialState,
                ));

                return 1;
            }

            $io->writeln(\sprintf(
                'Notice: workflow "%s" defines no published default_revision state; every backfilled row receives initial_state "%s".',
                $workflowId,
                $initialState,
            ));
        }

        /** @var array<string, int> $targetCounts */
        $targetCounts = [];
        /** @var array<string, list<string>> $targetSamples */
        $targetSamples = [];
        foreach ($pending as $p) {
            $targetCounts[$p['target']] = ($targetCounts[$p['target']] ?? 0) + 1;
            if (\count($targetSamples[$p['target']] ?? []) < self::SAMPLE_LIMIT) {
                $targetSamples[$p['target']][] = $p['id'];
            }
        }

        // Pointer-phase candidate resolution (rework task 3, #3/#9) — read
        // only, safe to run ahead of the dry-run branch. Gated on the entity
        // type being revisionable AND a published default_revision state
        // having been resolved; on a non-revisionable type
        // loadPublishedRevision() throws \LogicException (no revision
        // driver configured), so the gate is what prevents that call from
        // ever happening rather than catching it after the fact.
        $pointerPhaseActive = $definition->isRevisionable() && $publishedDefaultState !== null;
        /** @var list<array{id: string, revisionId: int}> $pointerPending */
        $pointerPending = [];
        $pointerNoRevisionHistory = 0;
        if ($pointerPhaseActive) {
            foreach ($publishedCandidateIds as $candidateId) {
                $candidateEntity = $repository->find($candidateId);
                if ($candidateEntity === null) {
                    continue;
                }
                // Idempotent: a pointer that is already set is left alone.
                if ($repository->loadPublishedRevision($candidateId) !== null) {
                    continue;
                }
                $revisionId = $this->currentRevisionId($candidateEntity);
                if ($revisionId === null) {
                    // No revision history yet (revisions:enable was never
                    // run for this row) — nothing to point at. Not a
                    // command failure: count it so the operator notices.
                    ++$pointerNoRevisionHistory;
                    continue;
                }
                $pointerPending[] = ['id' => $candidateId, 'revisionId' => $revisionId];
            }
        }

        if ($dryRun) {
            $io->writeln(\sprintf(
                '--dry-run: %s against workflow "%s" — %d row(s) examined, %d would be backfilled, %d already set (no-op).',
                $label,
                $workflowId,
                $examined,
                \count($pending),
                $skipped,
            ));
            foreach ($targetCounts as $state => $count) {
                $sampleIds = implode(', ', $targetSamples[$state] ?? []);
                $io->writeln(\sprintf('  -> %d row(s) would be set to "%s" (sample ids: %s)', $count, $state, $sampleIds));
            }
            if ($pointerPhaseActive) {
                $io->writeln(\sprintf(
                    '%d row(s) would have their published pointer established.',
                    \count($pointerPending),
                ));
                if ($pointerNoRevisionHistory > 0) {
                    $io->writeln(\sprintf(
                        'Notice: %d row(s) are published but have no revision history yet (run revisions:enable '
                        . 'first) — their pointer cannot be established by this command.',
                        $pointerNoRevisionHistory,
                    ));
                }
            }

            return 0;
        }

        // Phase 2 — write.
        $backfilled = 0;
        $failed = 0;
        /** @var array<string, int> $stateBackfilled */
        $stateBackfilled = [];
        /** @var list<string> $failures */
        $failures = [];

        foreach ($pending as $p) {
            try {
                $entity = $repository->find($p['id']);
                if ($entity === null) {
                    throw new \RuntimeException('row vanished between classification and write');
                }
                $entity->set('workflow_state', $p['target']);
                $this->disableNewRevision($entity);
                // No validation: this is a system field stamp on legacy
                // content, not a user-facing edit — validating unrelated
                // fields on old rows would reject rows the operator cannot
                // fix from this command.
                $repository->save($entity, false);
                ++$backfilled;
                $stateBackfilled[$p['target']] = ($stateBackfilled[$p['target']] ?? 0) + 1;
            } catch (\Throwable $e) {
                ++$failed;
                $failures[] = \sprintf('id %s: %s', $p['id'], $e->getMessage());
                $this->logger?->error(\sprintf(
                    'workflows:backfill-state failed for %s/%s: %s',
                    $entityTypeId,
                    $p['id'],
                    $e->getMessage(),
                ));
            }
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
        foreach ($stateBackfilled as $state => $count) {
            $io->writeln(\sprintf('  -> backfilled %d row(s) to "%s"', $count, $state));
        }

        // Phase 3 — pointer establishment (WP-2 rework task 3, #3/#9).
        // Failures here feed the SAME $failed/$failures accounting as
        // phase 2 — a pointer-move failure is reported per-row and turns
        // the overall exit code nonzero, exactly like a state-stamp
        // failure. This runs regardless of whether phase 2 had failures:
        // the two phases operate on largely disjoint concerns (a phase-2
        // failure on one row does not prevent establishing the pointer for
        // a different, already-published row).
        $pointerEstablished = 0;
        if ($pointerPhaseActive) {
            foreach ($pointerPending as $p) {
                try {
                    // The return value (the reloaded published-revision
                    // entity) isn't needed here, but it is captured to
                    // satisfy setPublishedRevision()'s #[\NoDiscard] contract
                    // (same convention as TransitionService::publish()).
                    $published = $repository->setPublishedRevision($p['id'], $p['revisionId']);
                    ++$pointerEstablished;
                } catch (\Throwable $e) {
                    ++$failed;
                    $failures[] = \sprintf('id %s: %s', $p['id'], $e->getMessage());
                    $this->logger?->error(\sprintf(
                        'workflows:backfill-state pointer establishment failed for %s/%s: %s',
                        $entityTypeId,
                        $p['id'],
                        $e->getMessage(),
                    ));
                }
            }

            $io->writeln(\sprintf('established %d published pointer(s).', $pointerEstablished));
            if ($pointerNoRevisionHistory > 0) {
                $io->writeln(\sprintf(
                    'Notice: %d row(s) are published but have no revision history yet (run revisions:enable '
                    . 'first) — their pointer cannot be established by this command.',
                    $pointerNoRevisionHistory,
                ));
            }
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

    /**
     * Duck-checks both revision contracts to read the entity's CURRENT
     * revision id, mirroring {@see \Waaseyaa\Workflows\Transition\TransitionService::revisionIdOf()}
     * (same shape: try {@see RevisionableInterface::getRevisionId()} first,
     * fall back to a `method_exists` probe for the legacy
     * {@see RevisionableEntityInterface}, otherwise null — no exception,
     * this is a best-effort read used only to decide whether a published
     * pointer can be established at all).
     */
    private function currentRevisionId(EntityInterface $entity): ?int
    {
        if ($entity instanceof RevisionableInterface) {
            return $entity->getRevisionId();
        }

        if ($entity instanceof RevisionableEntityInterface && \method_exists($entity, 'getRevisionId')) {
            $revisionId = $entity->getRevisionId();

            return \is_int($revisionId) ? $revisionId : null;
        }

        return null;
    }
}
