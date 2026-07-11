<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Listener;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\EntityStorage\Event\BeforeRevisionPointerMoveEvent;
use Waaseyaa\Workflows\Binding\WorkflowBindingResolver;
use Waaseyaa\Workflows\Group\GroupConstraintChecker;
use Waaseyaa\Workflows\Transition\TransitionDeniedException;
use Waaseyaa\Workflows\Workflow;
use Waaseyaa\Workflows\WorkflowTransition;

/**
 * Closes the pointer-move bypass (CW-v1 WP-2 task 2.5, #1920,
 * docs/specs/content-workflow.md, "Save-path guard"): {@see WorkflowStateGuard}
 * only observes `EntityRepository::save()` via `EntityEvents::PRE_SAVE`, so
 * `rollback()`, `setCurrentRevision()`, and `setPublishedRevision()` — which
 * move the base-row pointer WITHOUT a `doSave()` write — could silently move
 * a workflow-bound entity's effective state across an illegal or unpermitted
 * edge. Registered (by FQCN, task 2.4) on
 * {@see BeforeRevisionPointerMoveEvent}, dispatched BEFORE any write on all
 * six pointer-move paths, so a thrown denial leaves storage untouched.
 *
 * Validates like a transition, reusing {@see Workflow}'s own domain methods
 * (edge lookup via `getValidTransitions()`, `permissionFor()`) the same way
 * {@see WorkflowStateGuard::guardUpdate()} does — this class does not
 * reimplement `TransitionService`'s enforcement, it applies the identical
 * rule (edge must exist; permission required only when an acting account
 * context exists; a null context checks edge-legality only) to a different
 * trigger (pointer move, not save).
 *
 * The event carries only `entityTypeId` + `entityId`, not a bundle, so the
 * bundle is derived from `$revisionValues` using the entity type's own
 * `bundle` key — {@see self::resolveBundle()} mirrors
 * {@see \Waaseyaa\Entity\EntityBase::bundle()} exactly (default bundle is the
 * entity type id itself when no bundle key/value exists) rather than loading
 * the entity a second time.
 *
 * `translation_save` is a deliberate, unconditional pass-through: v1 workflow
 * state is tracked per REVISION, not per revision-translation
 * (docs/specs/content-workflow.md, "Staged limitation" — "translations share
 * their revision's state"). A translation write carries translated field
 * values only; it never implies a `workflow_state` change on its own, so
 * there is nothing for this guard to validate. `WorkflowStateGuard` similarly
 * has no translation-awareness today — this keeps both guards consistent
 * until per-translation state lands as a later stage.
 *
 * @api
 */
final class WorkflowPointerMoveGuard
{
    public function __construct(
        private readonly WorkflowBindingResolver $bindings,
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly ?AccountContextInterface $accountContext = null,
        // CW-v1 WP-3 (#1920): optional, mirroring TransitionService's and
        // WorkflowStateGuard's own convention. Null no longer means "no
        // group gating" — see satisfiesGroupConstraint(): a non-null
        // groupConstraint is DENIED when the checker is null, only
        // unconstrained transitions are unaffected. The event carries
        // entityTypeId + entityId (no bundle/entity load needed) — that pair
        // is exactly what the checker's content-group lookup takes. Checked
        // only when an acting account context exists (a null context stays
        // edge-legality only, unchanged).
        private readonly ?GroupConstraintChecker $groupConstraintChecker = null,
    ) {}

    /**
     * @throws TransitionDeniedException
     */
    public function onBeforePointerMove(BeforeRevisionPointerMoveEvent $event): void
    {
        // See class docblock: translations share their revision's state in
        // v1, so a translation write implies no workflow_state change.
        if ($event->operation === 'translation_save') {
            return;
        }

        $bundle = $this->resolveBundle($event->entityTypeId, $event->revisionValues);
        $workflow = $this->bindings->resolve($event->entityTypeId, $bundle);
        if ($workflow === null) {
            return;
        }

        $newState = $this->explicitState($event->revisionValues) ?? $workflow->getInitialState();
        $currentState = $this->currentlyEffectiveState($event, $workflow);

        // CW-v1 WP-2 task 2.6 (#1920), two-branch rule — the reconciliation
        // between TransitionService's from-state basis (the tip's real
        // predecessor state) and this guard's basis (the prior pointer's
        // state, which the sanctioned two-step legitimately leaves equal to
        // the target state: TransitionService saves the 'published' tip
        // first, THEN moves the pointer, so the guard sees published ->
        // published).
        //
        // 1. SAME-state move ($newState === $currentState): the workflow
        //    state does not change — only which revision serves. State-legal
        //    without an edge (workflows declare edges between DIFFERENT
        //    states; a self-loop is not a state change). Permission, when an
        //    acting account context exists: the account must hold the
        //    permission of AT LEAST ONE transition targeting $newState —
        //    checked over ALL transitions into the state (any-of, not
        //    first-declared), because holding any legal way INTO the state
        //    is what authorizes controlling which of its revisions serves.
        //    A null account context is allowed outright (edge-legality
        //    only, and there is no state change to check).
        //    This branch applies uniformly to publish/rollback/revert:
        //    e.g. rolling back to an earlier 'published'-stamped revision
        //    while current is also 'published' must succeed with the
        //    publish permission ('published' -> 'published' has no edge, so
        //    a strict-only rule would always deny it).
        //
        // 2. DIFFERENT-state move: strict rule, no exceptions — the
        //    $currentState -> $newState edge must exist in the workflow,
        //    and its own permission is required when an account context
        //    exists. No any-transition-into-state fallback: an earlier
        //    revision of this task relaxed the edge check for 'publish'
        //    into default-revision states, which reopened the bypass on the
        //    shipped editorial workflow (an account holding only 'publish'
        //    could resurrect an old 'published'-stamped revision while the
        //    pointer sat on 'archived', despite no archived -> published
        //    edge existing). Denying that here is correct: crossing states
        //    via a pointer move must be exactly as hard as crossing them
        //    via a transition.
        if ($newState === $currentState) {
            $account = $this->accountContext?->current();
            if ($account === null) {
                return;
            }

            // Known degenerate-graph quirk: for a state with NO incoming
            // transitions at all (e.g. an initial-only state no edge ever
            // targets), the any-of loop below is a check over an empty set —
            // an authenticated account is always DENIED (nothing to hold),
            // while a null account context (above) is always ALLOWED. That
            // asymmetry is inherent to the any-of rule, accepted as-is: no
            // shipped workflow has such a state, and fail-closed for
            // authenticated actors is the safe default (design invariant 5).
            //
            // CW-v1 WP-3 (#1920): the any-of rule tightens from "permission
            // alone" to "permission AND satisfied group constraint" per
            // candidate transition — a constraint-less transition into the
            // state keeps counting on permission alone, so the any-of
            // semantics are unchanged for workflows with no group
            // constraints.
            foreach ($workflow->getTransitions() as $transition) {
                if ($transition->to === $newState
                    && $account->hasPermission($workflow->permissionFor($transition))
                    && $this->satisfiesGroupConstraint($transition, $event, $account)
                ) {
                    return;
                }
            }

            throw new TransitionDeniedException(
                TransitionDeniedException::REASON_PERMISSION,
                \sprintf(
                    "Pointer-move operation '%s' denied: moving which '%s' revision of entity '%s:%s' serves requires the permission (and, where applicable, satisfied group constraint) of at least one transition into state '%s' in workflow '%s'.",
                    $event->operation,
                    $newState,
                    $event->entityTypeId,
                    $event->entityId,
                    $newState,
                    (string) $workflow->id(),
                ),
            );
        }

        $transition = $this->findTransition($workflow, $currentState, $newState);
        if ($transition === null) {
            throw new TransitionDeniedException(
                TransitionDeniedException::REASON_ILLEGAL_EDGE,
                \sprintf(
                    "Pointer-move operation '%s' cannot move entity '%s:%s' from state '%s' to '%s': no transition in workflow '%s'.",
                    $event->operation,
                    $event->entityTypeId,
                    $event->entityId,
                    $currentState,
                    $newState,
                    (string) $workflow->id(),
                ),
            );
        }

        $account = $this->accountContext?->current();
        if ($account !== null) {
            $permission = $workflow->permissionFor($transition);
            if (!$account->hasPermission($permission)) {
                throw new TransitionDeniedException(
                    TransitionDeniedException::REASON_PERMISSION,
                    \sprintf(
                        "Pointer-move operation '%s' denied: account lacks permission '%s' required by transition '%s'.",
                        $event->operation,
                        $permission,
                        $transition->id,
                    ),
                );
            }

            // CW-v1 WP-3 (#1920): the different-state edge's OWN group
            // constraint must also be satisfied — same order as
            // WorkflowStateGuard/TransitionService (permission first, group
            // constraint second). Uses entityTypeId + entityId straight off
            // the event; the pointer guard never loads the entity.
            if (!$this->satisfiesGroupConstraint($transition, $event, $account)) {
                throw new TransitionDeniedException(
                    TransitionDeniedException::REASON_GROUP_CONSTRAINT,
                    \sprintf(
                        "Pointer-move operation '%s' denied: transition '%s' requires group constraint '%s', which the account does not satisfy.",
                        $event->operation,
                        $transition->id,
                        (string) $transition->groupConstraint,
                    ),
                );
            }
        }
        // Null context (CLI, queue, bootstrap): no acting account to check
        // permission or group constraint against — edge-legality above is
        // the only enforceable guarantee here, mirroring
        // WorkflowStateGuard::guardUpdate().
    }

    /**
     * CW-v1 WP-3 (#1920, adversarial-review fix): true when `$transition`
     * carries no group constraint, or when a wired checker confirms
     * `$account` satisfies it. A null checker with a NON-null constraint now
     * fails closed (returns false) instead of the earlier fail-open "no
     * group gating" behavior — mirrors TransitionService's/
     * WorkflowStateGuard's own fail-closed convention. Uses the event's
     * entityTypeId/entityId directly — the pointer guard never loads the
     * entity.
     */
    private function satisfiesGroupConstraint(
        WorkflowTransition $transition,
        BeforeRevisionPointerMoveEvent $event,
        AccountInterface $account,
    ): bool {
        if ($transition->groupConstraint === null) {
            return true;
        }

        if ($this->groupConstraintChecker === null) {
            return false;
        }

        return $this->groupConstraintChecker->satisfies($transition, $event->entityTypeId, $event->entityId, $account->id());
    }

    /**
     * @param array<string, mixed> $revisionValues
     */
    private function resolveBundle(string $entityTypeId, array $revisionValues): string
    {
        $bundleKey = $this->entityTypeManager->getDefinition($entityTypeId)->getKeys()['bundle'] ?? 'bundle';
        $bundle = $revisionValues[$bundleKey] ?? null;

        return $bundle !== null ? (string) $bundle : $entityTypeId;
    }

    /**
     * The state the pointer move implicitly transitions FROM: the
     * published-pointer revision's state for `publish` (fromRevisionId is the
     * prior `published_revision_id`), or the current-pointer revision's state
     * for `rollback`/`revert` (fromRevisionId is the prior `revision_id`) —
     * `EntityRepository` already resolves the correct prior pointer per
     * operation before dispatching the event, so a single lookup here serves
     * both cases uniformly. Falls back to the workflow's initial state when
     * there is no prior revision to compare against (never-published entity,
     * or the prior revision could not be loaded).
     */
    private function currentlyEffectiveState(BeforeRevisionPointerMoveEvent $event, Workflow $workflow): string
    {
        if ($event->fromRevisionId === null) {
            return $workflow->getInitialState();
        }

        $fromRevision = $this->entityTypeManager
            ->getRepository($event->entityTypeId)
            ->loadRevision($event->entityId, $event->fromRevisionId);

        if ($fromRevision === null) {
            return $workflow->getInitialState();
        }

        return $this->explicitState($fromRevision) ?? $workflow->getInitialState();
    }

    private function findTransition(Workflow $workflow, string $from, string $to): ?WorkflowTransition
    {
        foreach ($workflow->getValidTransitions($from) as $transition) {
            if ($transition->to === $to) {
                return $transition;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|EntityInterface $source
     */
    private function explicitState(array|EntityInterface $source): ?string
    {
        $state = $source instanceof EntityInterface ? $source->get('workflow_state') : ($source['workflow_state'] ?? null);

        return \is_string($state) && $state !== '' ? $state : null;
    }
}
