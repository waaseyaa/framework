<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Publishing;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Publishing\ContentPublicationTransitionerInterface;
use Waaseyaa\Publishing\Exception\ContentWorkflowTransitionException;
use Waaseyaa\Workflows\Binding\WorkflowBindingResolver;
use Waaseyaa\Workflows\Transition\TransitionService;

/** Adapts generic publish/unpublish requests to legal workflow transitions. */
final readonly class WorkflowContentPublicationTransitioner implements ContentPublicationTransitionerInterface
{
    public function __construct(
        private WorkflowBindingResolver $bindings,
        private TransitionService $transitions,
        private EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function supports(EntityInterface $entity): bool
    {
        return $this->bindings->resolve($entity->getEntityTypeId(), $entity->bundle()) !== null;
    }

    public function setPublished(
        EntityInterface $entity,
        bool $published,
        AuthorizationPrincipalInterface $actor,
    ): EntityInterface {
        $workflow = $this->bindings->resolve($entity->getEntityTypeId(), $entity->bundle());
        if ($workflow === null) {
            throw new \LogicException('Workflow publication transitioner used for unbound content.');
        }

        $candidates = [];
        foreach ($this->transitions->getAvailableTransitions($entity, $actor) as $transition) {
            $target = $workflow->getState($transition->to);
            if ($target?->published === $published && $target->defaultRevision) {
                $candidates[] = $transition;
            }
        }
        if (count($candidates) !== 1) {
            throw new ContentWorkflowTransitionException($published);
        }

        $this->transitions->transition($entity, $candidates[0]->id, $actor);
        $repository = $this->entityTypeManager->getRepository($entity->getEntityTypeId());

        return $repository->loadWorkingCopy((string) $entity->id())
            ?? $repository->find((string) $entity->id())
            ?? $entity;
    }
}
