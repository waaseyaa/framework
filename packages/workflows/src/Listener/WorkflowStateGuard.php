<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Listener;

use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\RevisionableEntityInterface;
use Waaseyaa\Entity\RevisionableInterface;
use Waaseyaa\Workflows\Binding\WorkflowBindingResolver;
use Waaseyaa\Workflows\Transition\TransitionDeniedException;
use Waaseyaa\Workflows\Workflow;
use Waaseyaa\Workflows\WorkflowTransition;

/**
 * Makes the raw entity save path equivalent to
 * {@see \Waaseyaa\Workflows\Transition\TransitionService} for workflow-bound
 * entity types (CW-v1 WP-1, docs/specs/content-workflow.md, "Save-path
 * guard"). A raw `PATCH /api/{type}/{id}` cannot do what the service would
 * refuse: a save that mutates `workflow_state` is validated as if
 * `TransitionService::transition()` had been called by the acting account —
 * same denial, same exception.
 *
 * Registered against {@see \Waaseyaa\Entity\Event\EntityEvents::PRE_SAVE} —
 * the same event {@see \Waaseyaa\Audit\Listener\EntityLifecycleAuditListener}
 * subscribes to for the live save path.
 *
 * Entity types/bundles that do not resolve to a bound workflow are untouched
 * (return immediately) — this listener only governs workflow-bound content.
 *
 * @api
 */
final class WorkflowStateGuard
{
    public function __construct(
        private readonly WorkflowBindingResolver $bindings,
        private readonly ?EntityTypeManagerInterface $entityTypeManager = null,
        private readonly ?AccountContextInterface $accountContext = null,
    ) {}

    /**
     * @throws TransitionDeniedException
     */
    public function onPreSave(EntityEvent $event): void
    {
        $entity = $event->entity;
        $workflow = $this->bindings->resolve($entity->getEntityTypeId(), $entity->bundle());
        if ($workflow === null) {
            return;
        }

        if ($entity->isNew()) {
            $this->guardCreate($entity, $workflow);

            return;
        }

        $this->guardUpdate($entity, $event->originalEntity, $workflow);
    }

    /**
     * Rule 1 (create): force `initial_state` when unset. A create that names
     * a non-initial state is allowed only when the acting account can reach
     * it via a single legal+permitted transition from the initial state —
     * this closes the born-published hole (`Node` defaults `status = 1`) for
     * workflow-bound types (docs/specs/content-workflow.md).
     */
    private function guardCreate(EntityInterface $entity, Workflow $workflow): void
    {
        $initialState = $workflow->getInitialState();
        $requestedState = $this->explicitState($entity);

        if ($requestedState === null || $requestedState === $initialState) {
            $this->applyState($entity, $workflow, $initialState);

            return;
        }

        $transition = $this->findTransition($workflow, $initialState, $requestedState);
        if ($transition === null) {
            throw new TransitionDeniedException(
                TransitionDeniedException::REASON_ILLEGAL_EDGE,
                \sprintf(
                    "Cannot create content directly in state '%s': no transition from initial state '%s' in workflow '%s'.",
                    $requestedState,
                    $initialState,
                    (string) $workflow->id(),
                ),
            );
        }

        $account = $this->accountContext?->current();
        $permission = $workflow->permissionFor($transition);
        if ($account === null || !$account->hasPermission($permission)) {
            throw new TransitionDeniedException(
                TransitionDeniedException::REASON_PERMISSION,
                \sprintf("Cannot create content directly in state '%s': acting account lacks permission '%s'.", $requestedState, $permission),
            );
        }

        $this->applyState($entity, $workflow, $requestedState);
    }

    /**
     * Rules 2 + 3 (update): an unchanged `workflow_state` only re-forces
     * `status` consistency (see {@see applyState()}: pointer-derived on
     * pointered entities, state-derived otherwise). A changed
     * `workflow_state` is validated exactly like
     * {@see \Waaseyaa\Workflows\Transition\TransitionService::transition()}:
     * the edge must exist, and — when an acting account context exists — the
     * account must hold the transition's permission. A null context (CLI,
     * queue, bootstrap) checks edge-legality only; programmatic callers that
     * need permission enforcement should use TransitionService directly.
     * A validated state change into a `default_revision: false` state on a
     * pointered entity additionally forces a new revision (forward drafts
     * always revision — see the inline comment below).
     */
    private function guardUpdate(EntityInterface $entity, ?EntityInterface $originalEntity, Workflow $workflow): void
    {
        $originalState = $originalEntity !== null
            ? $this->stateOf($originalEntity, $workflow)
            : $workflow->getInitialState();
        $newState = $this->stateOf($entity, $workflow);

        if ($newState === $originalState) {
            $this->applyState($entity, $workflow, $newState);

            return;
        }

        $transition = $this->findTransition($workflow, $originalState, $newState);
        if ($transition === null) {
            throw new TransitionDeniedException(
                TransitionDeniedException::REASON_ILLEGAL_EDGE,
                \sprintf(
                    "No transition from state '%s' to '%s' in workflow '%s'.",
                    $originalState,
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
                    \sprintf("Account lacks permission '%s' required by transition '%s'.", $permission, $transition->id),
                );
            }
        }
        // Null context: no acting account to check permission against —
        // edge-legality above is the only enforceable guarantee here.

        // CW-v1 WP-2 task 2.6 panel fix B, made UNIFORM by the verifier's
        // residual finding (#1920): ANY state-changing save on an entity
        // whose published pointer exists requires a new revision —
        // regardless of the target state's `default_revision` flag. On a
        // `new_revision: false` bundle (entity-type `revisionDefault:
        // false`, or NodeType opt-out) the save would otherwise update the
        // CURRENT revision in place; when the current revision IS the
        // published one, that writes the new content and workflow_state
        // into the very row the published pointer serves — live content
        // corruption. The original scoping to `default_revision: false`
        // targets left exactly that hole for raw saves into 'archived' /
        // 'published': a pointered opt-out row raw-saved published ->
        // archived committed ONE row with workflow_state='archived' AND
        // the pointer-derived status=1.
        //
        // Consequence, documented (spec "Forward drafts always create a
        // revision"): raw saves NEVER enact pointer moves. A raw save into
        // a `default_revision: true` state creates an unpromoted tip
        // carrying that state while the pointer — and the pointer-derived
        // `status` ({@see applyState()}) — stay truthful; enacting
        // default-revision states (moving the pointer) is exclusively
        // {@see \Waaseyaa\Workflows\Transition\TransitionService}'s job.
        //
        // Precedence: the bundle opt-out governs ordinary
        // NON-state-changing edits; state-changing saves on pointered
        // entities always revision. Set unconditionally (overriding even an
        // explicit earlier setNewRevision(false)) so the outcome is
        // identical in both listener orders relative to Task 2.3's
        // NodeRevisionDefaultListener: that listener respects an
        // already-set non-null value (guard-first order → its skip keeps
        // `true`), and this write overrides a bundle-derived `false`
        // (node-listener-first order → still `true`).
        if ($this->loadPublishedRevision($entity) !== null) {
            $this->forceNewRevision($entity);
        }

        $this->applyState($entity, $workflow, $newState);
    }

    /**
     * Duck-checks both revision contracts, mirroring the #1654 pattern in
     * `EntityRepository::doSave()` and `TransitionService::markNewRevision()`:
     * the legacy {@see RevisionableInterface} declares `setNewRevision()`
     * directly; `ContentEntityBase` subclasses built on
     * `RevisionableEntityTrait` carry the method via the trait without
     * declaring the legacy interface.
     */
    private function forceNewRevision(EntityInterface $entity): void
    {
        if ($entity instanceof RevisionableInterface) {
            $entity->setNewRevision(true);

            return;
        }

        if ($entity instanceof RevisionableEntityInterface && \method_exists($entity, 'setNewRevision')) {
            $entity->setNewRevision(true);
        }
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
     * CW-v1 WP-2 task 2.6, amended by the panel-fix A (#1920,
     * docs/specs/content-workflow.md "two-pointer status semantics"):
     * **status rides the pointer, uniformly.** Once an entity has a
     * published revision pointer, this guard NEVER derives `status` from
     * the state the save happens to be entering — not for forward drafts
     * (that was the original task-2.6 rule) and not for
     * `default_revision: true` targets either. The earlier version set
     * `status` from the target state's `published` flag on
     * default-revision-target saves, which defeated `TransitionService`'s
     * deliberate ordering: the service leaves `status` alone on its first
     * (revision-creating) save and only flips it AFTER
     * `setPublishedRevision()` succeeds, precisely so a guard-denied
     * pointer move cannot leave `status` flipped with the pointer stuck.
     * This guard fires on that same first save — deriving from the target
     * state there committed `status = 1` in the first save's transaction
     * before the pointer move had a chance to be denied.
     *
     * For pointered entities, `status` is therefore derived from the
     * **published-pointer revision's own `workflow_state`** mapped through
     * the workflow's `published` flag (decision 2's literal definition —
     * "the base row's status reflects the published-pointer revision's
     * workflow state"). Deriving from the pointer's STATE rather than
     * copying its stored `status` column matters on TransitionService's
     * second (post-pointer-move, status-flip) save: the pointer has
     * already moved to the just-promoted revision, whose STORED status
     * column is still the pre-flip value — the state-derived value agrees
     * with the flip for both the publish (→ 1) and archive (→ 0) paths,
     * while a stored-status copy would have overwritten the flip. A
     * pointer revision whose `workflow_state` is unknown to the workflow
     * (pre-backfill data) falls back to copying its stored `status` —
     * best-effort, never derived from the save's target state.
     *
     * The base-row `status` write itself cannot be skipped —
     * {@see \Waaseyaa\EntityStorage\EntityRepository::doSave()} writes
     * whatever `status` sits on the entity into the base row on every
     * save, with no special-casing — so re-asserting the pointer-derived
     * value makes the unavoidable write idempotent rather than a flip.
     *
     * Never-published entities keep WP-1 behavior: `status` follows the
     * target state's `published` flag directly.
     */
    private function applyState(EntityInterface $entity, Workflow $workflow, string $state): void
    {
        $entity->set('workflow_state', $state);

        $published = $this->loadPublishedRevision($entity);
        if ($published !== null) {
            $entity->set('status', $this->pointerStatus($published, $workflow));

            return;
        }

        $targetState = $workflow->getState($state);
        $entity->set('status', $targetState?->published === true ? 1 : 0);
    }

    /**
     * @see applyState() — the pointer revision's state, mapped through the
     *   workflow's `published` flag; stored-status fallback for pointer
     *   revisions whose state is unknown to the workflow.
     */
    private function pointerStatus(EntityInterface $published, Workflow $workflow): mixed
    {
        $pointerStateId = $this->explicitState($published);
        $pointerState = $pointerStateId !== null ? $workflow->getState($pointerStateId) : null;

        if ($pointerState !== null) {
            return $pointerState->published ? 1 : 0;
        }

        return $published->get('status');
    }

    private function loadPublishedRevision(EntityInterface $entity): ?EntityInterface
    {
        if ($this->entityTypeManager === null) {
            return null;
        }

        $id = $entity->id();
        if ($id === null || $id === '') {
            return null;
        }

        return $this->entityTypeManager
            ->getRepository($entity->getEntityTypeId())
            ->loadPublishedRevision((string) $id);
    }

    private function stateOf(EntityInterface $entity, Workflow $workflow): string
    {
        return $this->explicitState($entity) ?? $workflow->getInitialState();
    }

    private function explicitState(EntityInterface $entity): ?string
    {
        $state = $entity->get('workflow_state');

        return \is_string($state) && $state !== '' ? $state : null;
    }
}
