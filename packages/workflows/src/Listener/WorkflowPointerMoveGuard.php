<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Listener;

use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\EntityStorage\Event\BeforeRevisionPointerMoveEvent;
use Waaseyaa\Workflows\Binding\WorkflowBindingResolver;
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

        if ($newState === $currentState) {
            return;
        }

        // CW-v1 WP-2 task 2.6 (#1920) reconciliation, carried from task
        // 2.5's review: `TransitionService::transition()` validates a
        // forward draft's PUBLISH against the tip revision's OWN, real
        // predecessor state (e.g. review -> published, or draft ->
        // published after a `restore`) — the correct basis, because that
        // is the actual edge the acting account requested and was granted
        // permission for. This guard, by contrast, only ever sees a
        // before/after snapshot of the *published pointer* itself
        // ($currentState above), which can legitimately be several
        // transitions stale by the time a forward draft catches up and
        // gets published: archived -> [restore, no pointer move] -> draft
        // -> [submit_for_review, no pointer move] -> review ->
        // [publish, pointer moves NOW] -> published. None of the
        // intermediate hops touch the pointer, so $currentState is still
        // 'archived' when this final publish fires. Re-deriving a literal
        // $currentState -> $newState edge in that case looks for
        // 'archived' -> 'published', which was never meant to exist as a
        // direct edge — a false positive that would wrongly deny an
        // already-permission-checked, fully sanctioned publish.
        //
        // Fix, scoped narrowly: for the 'publish' operation, when the
        // TARGET state is itself flagged `defaultRevision: true` (the
        // workflow declares it a promotable/"live-tier" destination),
        // validate against ANY transition that legally reaches that state
        // — regardless of its declared `from` — instead of specifically
        // from the stale pointer state. This does not reopen the pointer-
        // move bypass task 2.4/2.5 closed: a revision can only carry a
        // `workflow_state` of 'published'/'archived' in the first place
        // because some earlier save legitimately reached it — either via
        // `TransitionService::transition()` (this same call, moments
        // earlier) or a raw save validated by `WorkflowStateGuard` (which
        // requires an edge + permission just the same). This guard's job is
        // to stop a *bypass* caller from promoting a revision that was
        // never legitimately reached at all (e.g. a hand-rolled
        // `setPublishedRevision()` call pointed at a 'draft' revision) —
        // not to re-litigate a promotion whose destination is structurally
        // a valid publish target. A target that is NOT `defaultRevision:
        // true` (or any non-'publish' operation: rollback/revert) still
        // goes through the original, stricter $currentState -> $newState
        // edge check below — exactly the scenario this guard exists to
        // catch. Permission is still enforced, just resolved from whichever
        // transition targets $newState (in the reference `editorial`
        // workflow, exactly one transition ever targets a given
        // default-revision state, so this is unambiguous).
        $targetStateDefinition = $workflow->getState($newState);
        $isPointerPromotion = $event->operation === 'publish' && $targetStateDefinition?->defaultRevision === true;

        $transition = $isPointerPromotion
            ? $this->findTransitionTo($workflow, $newState)
            : $this->findTransition($workflow, $currentState, $newState);

        if ($transition === null) {
            throw new TransitionDeniedException(
                TransitionDeniedException::REASON_ILLEGAL_EDGE,
                $isPointerPromotion
                    ? \sprintf(
                        "Pointer-move operation '%s' cannot promote entity '%s:%s' to state '%s': no transition in workflow '%s' reaches it.",
                        $event->operation,
                        $event->entityTypeId,
                        $event->entityId,
                        $newState,
                        (string) $workflow->id(),
                    )
                    : \sprintf(
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
        }
        // Null context (CLI, queue, bootstrap): no acting account to check
        // permission against — edge-legality above is the only enforceable
        // guarantee here, mirroring WorkflowStateGuard::guardUpdate().
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
     * Used only for the reconciled 'publish'-into-a-`defaultRevision: true`-
     * state basis (see `onBeforePointerMove()` docblock) — deliberately NOT
     * filtered by `from`, unlike {@see findTransition()}. If a custom
     * workflow declared multiple transitions targeting the same
     * default-revision state with different permissions, this returns
     * whichever is declared first; the reference `editorial` workflow never
     * has more than one.
     */
    private function findTransitionTo(Workflow $workflow, string $to): ?WorkflowTransition
    {
        foreach ($workflow->getTransitions() as $transition) {
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
