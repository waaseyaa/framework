<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Relationship\EntityVisibilityFilterInterface;

/** Served-projection adapter for relationship visibility interfaces. */
final class WorkflowVisibilityFilter implements EntityVisibilityFilterInterface
{
    public function __construct(
        private readonly WorkflowVisibility $workflowVisibility = new WorkflowVisibility(),
    ) {}

    public function isEntityPublic(string $entityType, array $values): bool
    {
        return $this->workflowVisibility->isEntityServedPublic($entityType, $values);
    }

    public function isEntityPublicForEntity(EntityInterface $entity): bool
    {
        return $this->workflowVisibility->isEntityServedPublicForEntity($entity);
    }
}
