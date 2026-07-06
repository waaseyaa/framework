<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Listener;

use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Event\EntityEvent;
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
     * `status` consistency (the state owns status on bound types). A changed
     * `workflow_state` is validated exactly like
     * {@see \Waaseyaa\Workflows\Transition\TransitionService::transition()}:
     * the edge must exist, and — when an acting account context exists — the
     * account must hold the transition's permission. A null context (CLI,
     * queue, bootstrap) checks edge-legality only; programmatic callers that
     * need permission enforcement should use TransitionService directly.
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

        $this->applyState($entity, $workflow, $newState);
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

    private function applyState(EntityInterface $entity, Workflow $workflow, string $state): void
    {
        $entity->set('workflow_state', $state);
        $targetState = $workflow->getState($state);
        $entity->set('status', $targetState?->published === true ? 1 : 0);
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
