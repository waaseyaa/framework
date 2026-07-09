<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Transition;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\RevisionableEntityInterface;
use Waaseyaa\Entity\RevisionableInterface;
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

        $repository = $this->entityTypeManager->getRepository($entityTypeId);
        $targetState = $workflow->getState($transition->to);

        // CW-v1 WP-2 decision 2 (two-pointer status semantics,
        // docs/specs/content-workflow.md "forward draft"): read the
        // CURRENTLY published revision (if any) before mutating anything —
        // this decides which of the three cases below applies.
        $publishedRevision = $repository->loadPublishedRevision((string) $entity->id());

        $entity->set('workflow_state', $transition->to);

        // Every transition-service save creates its own tip revision,
        // regardless of the bundle's `new_revision` default (Task 2.3): a
        // bundle opting out would otherwise save in place and clobber
        // whatever revision happens to be loaded — frequently the published
        // one — silently corrupting a forward draft. Duck-checked (not
        // assumed) because not every workflow-bound content entity type is
        // guaranteed revisionable (#1654 pattern, mirrored from
        // `EntityRepository::doSave()`).
        $isRevisionable = $this->entityTypeManager->getDefinition($entityTypeId)->isRevisionable()
            && $this->markNewRevision($entity, true);

        if ($targetState?->defaultRevision === true) {
            // Publish/promote: persist the new revision WITHOUT flipping
            // `status` yet — `status` must only ever reflect the
            // *published-pointer* revision's state (decision 2). Flipping
            // it before the pointer move commits would leave the base row
            // lying about the live state if the move is denied (the pointer
            // guard, task 2.5, validates like a transition and can throw).
            $repository->save($entity);

            $newRevisionId = $isRevisionable ? $this->revisionIdOf($entity) : null;
            if ($newRevisionId !== null) {
                // May throw TransitionDeniedException — deliberately BEFORE
                // the status flip below, so a denial leaves `status`
                // untouched and the pointer unmoved: never "status flipped
                // but pointer stuck". The return value (a freshly reloaded
                // published-revision entity) isn't needed here — `$entity`
                // already carries the correct new content — but it is
                // captured to satisfy the "don't discard a meaningful
                // return value" static-analysis rule.
                $publishedNow = $repository->setPublishedRevision((string) $entity->id(), $newRevisionId);

                // Refresh the entity's OWN copy of the pointer BEFORE the
                // follow-up save below. `published_revision_id` is a
                // base-table-only column with no field definition, but
                // `EntityRepository::find()` still hydrates it into the
                // generic values bag `toArray()` returns — so an entity
                // loaded before this call carries the OLD pointer value in
                // memory. `doSave()` writes whatever `toArray()` returns
                // into the base row on every save, unconditionally; without
                // this line, the very next save (two lines down) would
                // silently write that stale value back, undoing the pointer
                // move this line just made. Verified empirically: omitting
                // this line reverts `published_revision_id` to its
                // pre-transition value every time (dual-state bug pattern,
                // CLAUDE.md "Architecture Gotchas").
                $entity->set('published_revision_id', $newRevisionId);

                // Pointer moved: now it is safe to make `status` agree with
                // it. A second, non-revision-creating save (setNewRevision
                // false) updates the SAME tip revision's status in place —
                // it does not create a second revision.
                $entity->set('status', $targetState->published === true ? 1 : 0);
                $this->markNewRevision($entity, false);
                $repository->save($entity);
            } else {
                if ($isRevisionable) {
                    // A revisionable entity that reported no revision id
                    // after a revision-creating save is NOT the benign
                    // non-revisionable case below — it means the save
                    // pipeline did not hand the new revision id back (a
                    // storage/hydration defect, or an entity class whose
                    // revision key diverges from what save() sets). The
                    // pointer therefore CANNOT be moved; falling through to
                    // the direct status flip keeps WP-1 behavior but the
                    // two-pointer promotion silently did not happen — say
                    // so loudly instead of masking it.
                    ($this->logger ?? new NullLogger())->warning('workflows.transition_missing_revision_id', [
                        'entity_type' => $entityTypeId,
                        'entity_id' => (string) $entity->id(),
                        'transition' => $transitionId,
                        'to_state' => $transition->to,
                        'effect' => 'published pointer NOT moved; status set directly from the target state',
                    ]);
                }

                // Non-revisionable entity type (or the defect logged above):
                // no pointer to move — WP-1 behavior (status follows state
                // directly).
                $entity->set('status', $targetState->published === true ? 1 : 0);
                $repository->save($entity);
            }
        } elseif ($publishedRevision !== null) {
            // Forward draft: a new, non-default-revision tip carries the
            // target state. Leave the published pointer untouched — the
            // live version keeps serving. Re-assert the *existing*
            // published revision's own `status` (rather than leaving
            // whatever was already on the entity) so this save's otherwise
            // unavoidable base-row write
            // ({@see \Waaseyaa\EntityStorage\EntityRepository::doSave()}
            // writes whatever `status` sits on the entity into the base row
            // on every save, with no special-casing for that column) is
            // idempotent, not a silent flip.
            $entity->set('status', $publishedRevision->get('status'));
            $repository->save($entity);
        } else {
            // Never published: WP-1 behavior stands — status follows the
            // target state directly, there is no published pointer to
            // protect yet.
            $entity->set('status', $targetState?->published === true ? 1 : 0);
            $repository->save($entity);
        }

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

    /**
     * Duck-checks both the legacy {@see RevisionableInterface} (which
     * declares `setNewRevision()` directly) and the newer
     * {@see RevisionableEntityInterface} (a marker interface — concrete
     * classes built on `RevisionableEntityTrait` carry the method without
     * declaring the legacy interface). Mirrors the #1654 pattern in
     * `EntityRepository::doSave()`/`EntityRepository::save()` rather than
     * assuming every workflow-bound content entity type is revisionable.
     *
     * @return bool Whether the entity actually supports `setNewRevision()`
     *   (so the caller knows whether {@see revisionIdOf()} can return a
     *   meaningful value afterward).
     */
    private function markNewRevision(EntityInterface $entity, bool $value): bool
    {
        if ($entity instanceof RevisionableInterface) {
            $entity->setNewRevision($value);

            return true;
        }

        if ($entity instanceof RevisionableEntityInterface && \method_exists($entity, 'setNewRevision')) {
            $entity->setNewRevision($value);

            return true;
        }

        return false;
    }

    /**
     * @see markNewRevision() for the duck-check rationale.
     */
    private function revisionIdOf(EntityInterface $entity): ?int
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
