<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Relationship\EntityVisibilityFilterInterface;

final class WorkflowVisibilityFilter implements EntityVisibilityFilterInterface
{
    public function __construct(
        private readonly WorkflowVisibility $workflowVisibility = new WorkflowVisibility(),
    ) {}

    public function isEntityPublic(string $entityType, array $values): bool
    {
        return $this->workflowVisibility->isEntityPublic($entityType, $values);
    }

    public function isEntityPublicForEntity(EntityInterface $entity): bool
    {
        return $this->workflowVisibility->isEntityPublicForEntity($entity);
    }
}
