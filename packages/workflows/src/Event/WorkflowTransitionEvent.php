<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Event;

use Symfony\Contracts\EventDispatcher\Event;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityInterface;

/**
 * Dispatched before ({@see WorkflowEvents::PRE_TRANSITION}) and after
 * ({@see WorkflowEvents::POST_TRANSITION}) a workflow transition is applied
 * by {@see \Waaseyaa\Workflows\Transition\TransitionService} (CW-v1 WP-1,
 * docs/specs/content-workflow.md). Mirrors the live `EntityEvent` lifecycle
 * shape: a plain Symfony `Event` subclass with public readonly properties,
 * not the framework-wide `DomainEvent` serialization envelope.
 *
 * @api
 */
final class WorkflowTransitionEvent extends Event
{
    public function __construct(
        public readonly EntityInterface $entity,
        public readonly string $workflowId,
        public readonly string $transitionId,
        public readonly string $fromState,
        public readonly string $toState,
        public readonly ?AccountInterface $account,
    ) {}
}
