<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Listener;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\RevisionableEntityInterface;
use Waaseyaa\Entity\RevisionableInterface;
use Waaseyaa\Workflows\Binding\WorkflowBindingResolver;
use Waaseyaa\Workflows\Group\GroupConstraintChecker;
use Waaseyaa\Workflows\Republish\RepublishMarker;
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
 * **CW-v1 option-1 (#1920 PR-2) doctrine inversion:** raw saves never enact
 * STATE-CHANGING pointer moves — see the "Consequence" note on
 * {@see guardUpdate()}. But an AUTHORIZED same-state edit of a
 * `default_revision: true` state DOES re-publish served content, through the
 * `setPublishedRevision()` choke point (see {@see guardSameStateRepublish()}):
 * once an entity carries a published pointer, changing what serves IS
 * publishing, whether the edit changes `workflow_state` or not.
 *
 * @api
 */
final class WorkflowStateGuard
{
    public function __construct(
        private readonly WorkflowBindingResolver $bindings,
        private readonly ?EntityTypeManagerInterface $entityTypeManager = null,
        private readonly ?AccountContextInterface $accountContext = null,
        // CW-v1 WP-3 (#1920): optional, mirroring TransitionService's own
        // convention. Null no longer means "no group gating" — a
        // group_constraint on the transition is DENIED when the checker is
        // null, only when an acting account context exists (a null context
        // stays edge-legality only, unchanged — see guardUpdate()).
        // Production wiring ({@see \Waaseyaa\Workflows\WorkflowServiceProvider})
        // always injects a real checker via resolveOptional(), so a wiring
        // regression now denies loudly instead of silently un-gating.
        private readonly ?GroupConstraintChecker $groupConstraintChecker = null,
        // CW-v1 option-1 (#1920 PR-2): the arm-at-PRE_SAVE half of the
        // same-state republish two-step (see class docblock). Optional,
        // same convention as the collaborators above — a missing marker
        // (wiring regression) degrades to "same-state edits of served
        // content never re-publish", not a boot crash; production wiring
        // always injects the shared singleton.
        private readonly ?RepublishMarker $republishMarker = null,
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

        // CW-v1 option-1 (#1920 PR-2, design §1): set UNCONDITIONALLY on
        // every guarded save — never set-on-true-only, so a stale `true`
        // from a prior save of a long-lived entity object can never leak
        // into a later, unguarded save. `$workflow !== null` here already
        // guarantees the entity type is single-axis revisionable (bindings
        // hard-throw for non-revisionable and revisionable+translatable
        // types before this point is ever reached), so no further
        // revisionable/translatable check is needed. Duck-checked
        // (method_exists) the same way the rest of this class duck-checks
        // revision capability — an entity class not built on
        // RevisionableEntityTrait simply has nothing to set.
        $this->setDiscipline($entity);

        // Fix-wave stale-arm fix (#1920 PR-2 adversarial review): clear any
        // republish arm left on this entity OBJECT by a previous,
        // PRE_SAVE-aborted save (a later listener threw AFTER
        // guardSameStateRepublish() armed). Unconditional at the start of
        // every guarded save, mirroring the discipline-flag reset above —
        // without this, a later state-CHANGING save of the same object
        // would consume the stale arm at POST_SAVE and silently promote its
        // new draft tip.
        $this->republishMarker?->clear($entity);

        if ($entity->isNew()) {
            $this->guardCreate($entity, $workflow);

            return;
        }

        $this->guardUpdate($entity, $event->originalEntity, $workflow);
    }

    /**
     * CW-v1 option-1 (#1920 PR-2, design §1): `$pointered` = the live
     * `published_revision_id` on the base row. For a create (no id yet),
     * {@see loadPublishedRevision()} returns null and this sets `false` —
     * harmless either way, since storage additionally requires `!isNew()`
     * before honoring the flag.
     */
    private function setDiscipline(EntityInterface $entity): void
    {
        if (!\method_exists($entity, 'setDefaultRevisionDiscipline')) {
            return;
        }

        $entity->setDefaultRevisionDiscipline($this->loadPublishedRevision($entity) !== null);
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
     * pointered entities, state-derived otherwise) and, on a disciplined
     * default-revision-state save, gates + arms the same-state republish
     * (see {@see guardSameStateRepublish()}). A changed `workflow_state` is
     * validated exactly like
     * {@see \Waaseyaa\Workflows\Transition\TransitionService::transition()}:
     * the edge must exist, and — when an acting account context exists — the
     * account must hold the transition's permission. A null context (CLI,
     * queue, bootstrap) checks edge-legality only; programmatic callers that
     * need permission enforcement should use TransitionService directly.
     * A validated state change into a `default_revision: false` state on a
     * pointered entity additionally forces a new revision (forward drafts
     * always revision — see the inline comment below).
     *
     * **CW-v1 option-1 (#1920 PR-2):** the ORIGINAL-state basis is the
     * entity's stored WORKING COPY ({@see workingCopyBasis()}), not the
     * base row `$originalEntity` — under discipline the base row stays
     * 'published' throughout a draft window, so basing this comparison on
     * it would spuriously re-validate every draft edit as
     * `published -> draft`. `loadWorkingCopy()` is mechanically safe on any
     * entity (undisciplined ones have no divergence, so it degenerates to
     * `$originalEntity`), so this basis switch is unconditional.
     */
    private function guardUpdate(EntityInterface $entity, ?EntityInterface $originalEntity, Workflow $workflow): void
    {
        $basisEntity = $this->workingCopyBasis($entity, $originalEntity);
        $originalState = $basisEntity !== null
            ? $this->stateOf($basisEntity, $workflow)
            : $workflow->getInitialState();
        $newState = $this->stateOf($entity, $workflow);

        if ($newState === $originalState) {
            $this->guardSameStateRepublish($entity, $workflow, $newState);
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

            // CW-v1 WP-3 (#1920): group-constraint parity with
            // TransitionService::transition() — checked immediately after
            // permission, and ONLY when an acting account context exists
            // (a null context stays edge-legality-only, unchanged). This is
            // what closes the raw-save bypass: without this gate, a PATCH
            // that mutates workflow_state could enact a group-constrained
            // transition that TransitionService itself would deny.
            if ($transition->groupConstraint !== null
                && ($this->groupConstraintChecker === null
                    || !$this->groupConstraintChecker->satisfies($transition, $entity->getEntityTypeId(), (string) $entity->id(), $account->id()))
            ) {
                throw new TransitionDeniedException(
                    TransitionDeniedException::REASON_GROUP_CONSTRAINT,
                    \sprintf(
                        "Transition '%s' requires group constraint '%s', which the account does not satisfy.",
                        $transition->id,
                        $transition->groupConstraint,
                    ),
                );
            }
        }
        // Null context: no acting account to check permission (or group
        // constraint) against — edge-legality above is the only enforceable
        // guarantee here.

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
        // revision"), INVERTED by CW-v1 option-1 (#1920 PR-2, design §3.1
        // finding A3): raw saves never enact STATE-CHANGING pointer moves.
        // A raw save into a `default_revision: true` state creates an
        // unpromoted tip carrying that state while the pointer — and the
        // pointer-derived `status` ({@see applyState()}) — stay truthful;
        // enacting a STATE CHANGE into a default-revision state (moving the
        // pointer across an edge) is exclusively
        // {@see \Waaseyaa\Workflows\Transition\TransitionService}'s job.
        // An AUTHORIZED SAME-state edit of an already-default-revision
        // state is different: it re-publishes through the
        // `setPublishedRevision()` choke point via the arm/consume two-step
        // — see {@see guardSameStateRepublish()} and
        // {@see \Waaseyaa\Workflows\Listener\WorkflowRepublishListener}.
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
     * CW-v1 option-1 (#1920 PR-2, design §3.1 "Same-state republish").
     *
     * A SAME-state save whose state is `default_revision: true` (published
     * AND archived, uniformly) does not change `workflow_state` — but under
     * discipline, changing what CONTENT serves at that state IS publishing,
     * because the base row only ever holds the published revision. Gates +
     * arms exactly the shape that closes the coherence trap: a plain content
     * PATCH on a bound published node would otherwise fork a revision-only
     * tip stamped 'published' that nothing can ever promote (`publish.from`
     * never includes 'published' itself) — the edit silently never goes
     * live.
     *
     * No-op (returns without gating or arming) when:
     * - the entity has no published pointer (undisciplined / never-published
     *   — nothing to protect, nothing to arm);
     * - the target state is not `default_revision: true` (a draft-state
     *   same-state save IS the forward-draft case itself — no republish).
     *
     * Otherwise the any-of authorization applies UNCONDITIONALLY — to
     * revision-creating AND in-place (non-revision-creating) saves alike
     * (fix-wave, #1920 PR-2 adversarial review): an in-place save of the
     * published tip reaches the SERVED base row directly via the writeBase
     * rule in {@see \Waaseyaa\EntityStorage\EntityRepository::doSave()}, so
     * gating only revision-creating saves let a `new_revision: false`
     * bundle's plain content save put unauthorized content live with no
     * workflow permission checked (in the NodeRevisionDefaultListener-first
     * PRE_SAVE order the earlier gate short-circuited before the auth
     * check). This deliberately also gates TransitionService's own second
     * (post-pointer-move, status-flip) save and any sanctioned in-place
     * published edit — both pass, because the acting account holds the
     * fired transition's own permission (by definition a transition INTO
     * the target state), or the context is null (edge-legality only).
     *
     * The any-of rule itself is the same one
     * {@see \Waaseyaa\Workflows\Listener\WorkflowPointerMoveGuard}'s
     * same-state branch uses (permission AND satisfied group constraint of
     * at least one transition INTO the state; a null account context
     * passes). Denial throws before anything commits.
     *
     * ARMING stays scoped to revision-creating saves only
     * ({@see willCreateRevision()}): an in-place save leaves no orphan tip
     * to promote, so a POST_SAVE promotion would be redundant — and in the
     * guard-first PRE_SAVE listener order willCreateRevision() can report a
     * pre-bundle-default answer, so a spurious arm is still possible; the
     * republish listener's already-published self-skip is the second half
     * of that defense (see
     * {@see \Waaseyaa\Workflows\Listener\WorkflowRepublishListener::onPostSave()}).
     */
    private function guardSameStateRepublish(EntityInterface $entity, Workflow $workflow, string $state): void
    {
        if ($this->republishMarker === null) {
            return;
        }

        if ($this->loadPublishedRevision($entity) === null) {
            return;
        }

        $targetState = $workflow->getState($state);
        if ($targetState === null || $targetState->defaultRevision !== true) {
            return;
        }

        $account = $this->accountContext?->current();
        if ($account !== null && !$this->satisfiesAnyTransitionInto($workflow, $state, $entity, $account)) {
            throw new TransitionDeniedException(
                TransitionDeniedException::REASON_PERMISSION,
                \sprintf(
                    "Same-state save into default-revision state '%s' denied: re-publishing served content "
                    . 'requires the permission (and, where applicable, satisfied group constraint) of at '
                    . "least one transition into state '%s' in workflow '%s'.",
                    $state,
                    $state,
                    (string) $workflow->id(),
                ),
            );
        }

        if ($this->willCreateRevision($entity)) {
            $this->republishMarker->arm($entity);
        }
    }

    /**
     * @see \Waaseyaa\Workflows\Listener\WorkflowPointerMoveGuard's identical
     *   any-of rule, applied here to the save-path guard's own same-state
     *   republish gate.
     */
    private function satisfiesAnyTransitionInto(Workflow $workflow, string $state, EntityInterface $entity, AccountInterface $account): bool
    {
        foreach ($workflow->getTransitions() as $transition) {
            if ($transition->to !== $state) {
                continue;
            }

            if (!$account->hasPermission($workflow->permissionFor($transition))) {
                continue;
            }

            if ($transition->groupConstraint !== null
                && ($this->groupConstraintChecker === null
                    || !$this->groupConstraintChecker->satisfies($transition, $entity->getEntityTypeId(), (string) $entity->id(), $account->id()))
            ) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Duck-checks whether THIS save will create a revision, the same way
     * {@see \Waaseyaa\EntityStorage\EntityRepository}'s private
     * `shouldCreateRevision()` will resolve it: a caller override
     * (`isNewRevision()`, legacy or WP07 contract) takes precedence; absent
     * that, the entity type's `revisionDefault`. Called only from the
     * same-state branch (never from the state-changing branch, which always
     * forces `true` via {@see forceNewRevision()} first), and only for an
     * existing (non-new) entity — the two conditions
     * `EntityRepository::shouldCreateRevision()` special-cases (non-new,
     * non-revisionable) do not apply here: `guardUpdate()` only runs for
     * `!isNew()` entities, and this method is only reached once a workflow
     * bound the entity type, which (per {@see WorkflowBindingResolver})
     * guarantees `isRevisionable() === true`.
     */
    private function willCreateRevision(EntityInterface $entity): bool
    {
        if ($entity instanceof RevisionableInterface) {
            $override = $entity->isNewRevision();
            if ($override !== null) {
                return $override;
            }
        } elseif ($entity instanceof RevisionableEntityInterface && \method_exists($entity, 'isNewRevision')) {
            $override = $entity->isNewRevision();
            if (\is_bool($override)) {
                return $override;
            }
        }

        if ($this->entityTypeManager === null) {
            return false;
        }

        return $this->entityTypeManager->getDefinition($entity->getEntityTypeId())->getRevisionDefault();
    }

    /**
     * CW-v1 option-1 (#1920 PR-2, design §3.1): the ORIGINAL-state basis for
     * {@see guardUpdate()}. `loadWorkingCopy()` is mechanically safe on any
     * entity (undisciplined ones have no divergence between the tip and the
     * base pointer, so it degenerates to `find()` === `$originalEntity`) —
     * this basis switch therefore needs no "is this entity disciplined"
     * check of its own. Falls back to `$originalEntity` when no
     * entity-type manager is wired, the entity has no id yet, or the
     * repository reports no working copy (e.g. the row vanished between
     * `doSave()`'s own `$originalEntity` load and this call).
     */
    private function workingCopyBasis(EntityInterface $entity, ?EntityInterface $originalEntity): ?EntityInterface
    {
        if ($this->entityTypeManager === null) {
            return $originalEntity;
        }

        $id = $entity->id();
        if ($id === null || $id === '') {
            return $originalEntity;
        }

        $workingCopy = $this->entityTypeManager
            ->getRepository($entity->getEntityTypeId())
            ->loadWorkingCopy((string) $id);

        return $workingCopy ?? $originalEntity;
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
