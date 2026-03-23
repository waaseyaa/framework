<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows;

use Waaseyaa\Relationship\VisibilityFilterInterface;

final class WorkflowVisibilityFilter implements VisibilityFilterInterface
{
    public function __construct(
        private readonly WorkflowVisibility $workflowVisibility = new WorkflowVisibility(),
    ) {}

    public function isEntityPublic(string $entityType, array $values): bool
    {
        return $this->workflowVisibility->isEntityPublic($entityType, $values);
    }
}
