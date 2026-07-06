<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Transition;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Workflows\Binding\WorkflowBindingResolver;
use Waaseyaa\Workflows\Event\WorkflowEvents;
use Waaseyaa\Workflows\Event\WorkflowTransitionEvent;
use Waaseyaa\Workflows\Workflow;
use Waaseyaa\Workflows\WorkflowTransition;

/**
 * The one enforcement door for workflow state changes (CW-v1 WP-1,
 * docs/specs/content-workflow.md, design invariant 1).
 *
 * `transition()` validates → applies → announces, in that order, never
 * partially: a denied transition mutates nothing and dispatches nothing but
 * the (best-effort) denial audit entry. The save-path guard
 * ({@see \Waaseyaa\Workflows\Listener\WorkflowStateGuard}) enforces the same
 * validation for raw entity saves, so a `PATCH` cannot do what this service
 * would refuse.
 *
 * Deviation from the plan's literal text: persistence goes through
 * `EntityTypeManagerInterface::getRepository()->save()` (the canonical
 * EntityRepository pipeline that dispatches PRE_SAVE/POST_SAVE — see
 * `docs/specs/content-workflow.md` "TransitionService — the one door" and
 * the repo's entity-storage-invariant rule), not `getStorage()->save()`
 * (the plan's literal text) — the raw storage driver does not dispatch
 * entity lifecycle events at all.
 *
 * @api
 */
final class TransitionService
{
    public function __construct(
        private readonly WorkflowBindingResolver $bindings,
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly ?\Symfony\Contracts\EventDispatcher\EventDispatcherInterface $dispatcher = null,
        private readonly ?AuditWriterInterface $auditWriter = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @throws TransitionDeniedException
     */
    public function transition(EntityInterface $entity, string $transitionId, AccountInterface $account): TransitionResult
    {
        $entityTypeId = $entity->getEntityTypeId();
        $bundle = $entity->bundle();

        $workflow = $this->bindings->resolve($entityTypeId, $bundle);
        if ($workflow === null) {
            $this->denyAndThrow(
                $entity,
                $account,
                $transitionId,
                '',
                '',
                '',
                TransitionDeniedException::REASON_UNBOUND,
                \sprintf("Entity type '%s' bundle '%s' is not bound to a workflow.", $entityTypeId, $bundle),
            );
        }

        $workflowId = (string) $workflow->id();

        $transition = $workflow->getTransition($transitionId);
        if ($transition === null) {
            $this->denyAndThrow(
                $entity,
                $account,
                $transitionId,
                $workflowId,
                '',
                '',
                TransitionDeniedException::REASON_UNKNOWN_TRANSITION,
                \sprintf("Workflow '%s' has no transition '%s'.", $workflowId, $transitionId),
            );
        }

        $fromState = $this->currentState($entity, $workflow);
        if (!\in_array($fromState, $transition->from, true)) {
            $this->denyAndThrow(
                $entity,
                $account,
                $transitionId,
                $workflowId,
                $fromState,
                $transition->to,
                TransitionDeniedException::REASON_ILLEGAL_EDGE,
                \sprintf(
                    "Transition '%s' cannot fire from state '%s' (allowed from: %s).",
                    $transitionId,
                    $fromState,
                    \implode(', ', $transition->from),
                ),
            );
        }

        $permission = $workflow->permissionFor($transition);
        if (!$account->hasPermission($permission)) {
            $this->denyAndThrow(
                $entity,
                $account,
                $transitionId,
                $workflowId,
                $fromState,
                $transition->to,
                TransitionDeniedException::REASON_PERMISSION,
                \sprintf("Account lacks permission '%s' required by transition '%s'.", $permission, $transitionId),
            );
        }

        // Announce (pre), apply, persist, announce (post), audit — in order.
        $this->dispatcher?->dispatch(
            new WorkflowTransitionEvent($entity, $workflowId, $transitionId, $fromState, $transition->to, $account),
            WorkflowEvents::PRE_TRANSITION->value,
        );

        $entity->set('workflow_state', $transition->to);
        $targetState = $workflow->getState($transition->to);
        $entity->set('status', $targetState?->published === true ? 1 : 0);

        $this->entityTypeManager->getRepository($entityTypeId)->save($entity);

        $this->dispatcher?->dispatch(
            new WorkflowTransitionEvent($entity, $workflowId, $transitionId, $fromState, $transition->to, $account),
            WorkflowEvents::POST_TRANSITION->value,
        );

        $this->audit($entity, $account, $workflowId, $transitionId, $fromState, $transition->to, 'allowed');

        return new TransitionResult($fromState, $transition->to, $transitionId);
    }

    /**
     * The transitions the account may fire from the entity's current state —
     * the read-side {@see \Waaseyaa\Workflows\WorkflowVisibility} equivalent
     * for the write side; the sanctioned way for UIs to decide what to offer.
     *
     * @return list<WorkflowTransition>
     */
    public function getAvailableTransitions(EntityInterface $entity, AccountInterface $account): array
    {
        $workflow = $this->bindings->resolve($entity->getEntityTypeId(), $entity->bundle());
        if ($workflow === null) {
            return [];
        }

        $fromState = $this->currentState($entity, $workflow);

        $available = [];
        foreach ($workflow->getValidTransitions($fromState) as $transition) {
            if ($account->hasPermission($workflow->permissionFor($transition))) {
                $available[] = $transition;
            }
        }

        return $available;
    }

    private function currentState(EntityInterface $entity, Workflow $workflow): string
    {
        $state = $entity->get('workflow_state');

        return \is_string($state) && $state !== '' ? $state : $workflow->getInitialState();
    }

    /**
     * @throws TransitionDeniedException Always — records the denial audit
     *   entry first (best-effort), then throws.
     */
    private function denyAndThrow(
        EntityInterface $entity,
        AccountInterface $account,
        string $transitionId,
        string $workflowId,
        string $fromState,
        string $toState,
        string $reason,
        string $message,
    ): never {
        $this->audit($entity, $account, $workflowId, $transitionId, $fromState, $toState, 'denied');

        throw new TransitionDeniedException($reason, $message);
    }

    private function audit(
        EntityInterface $entity,
        AccountInterface $account,
        string $workflowId,
        string $transitionId,
        string $fromState,
        string $toState,
        string $outcome,
    ): void {
        try {
            $this->auditWriter?->record(new AuditEventDescriptor(
                kind: AuditEventKind::WorkflowTransition,
                accountUid: $account->isAuthenticated() ? (int) $account->id() : 0,
                subjectUri: \sprintf('entity:%s/%s', $entity->getEntityTypeId(), (string) $entity->id()),
                outcome: $outcome,
                severity: $outcome === 'denied' ? 'warning' : 'notice',
                entityTypeId: $entity->getEntityTypeId(),
                attributes: [
                    'workflow' => $workflowId,
                    'transition' => $transitionId,
                    'from' => $fromState,
                    'to' => $toState,
                ],
            ));
        } catch (\Throwable $e) {
            ($this->logger ?? new NullLogger())->warning('workflows.transition_audit_failed', [
                'error' => $e->getMessage(),
                'transition' => $transitionId,
            ]);
        }
    }
}
