<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Config;

use Waaseyaa\Config\Schema\ConfigSemanticValidatorInterface;
use Waaseyaa\Config\Schema\SchemaViolation;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Workflows\Validation\WorkflowAssignmentsValidator;

/** @internal */
final readonly class WorkflowAssignmentsSemanticValidator implements ConfigSemanticValidatorInterface
{
    public function __construct(
        private EntityTypeManagerInterface $entityTypeManager,
        private WorkflowAssignmentsValidator $assignments = new WorkflowAssignmentsValidator(),
    ) {}

    public function validate(array $fields): array
    {
        $violations = [];
        foreach ($fields as $binding => $workflowId) {
            if (preg_match('/^[a-z][a-z0-9_]*\.(?:[a-z][a-z0-9_]*|\*)$/D', $binding) !== 1) {
                $violations[] = new SchemaViolation(
                    $binding,
                    'Workflow assignment keys must use the canonical `<entity_type>.<bundle|*>` form.',
                );
                continue;
            }
            if (!is_string($workflowId) || preg_match('/^[a-z][a-z0-9_]*$/D', $workflowId) !== 1) {
                $violations[] = new SchemaViolation(
                    $binding,
                    'Workflow assignment values must be canonical workflow IDs.',
                );
                continue;
            }
            foreach ($this->assignments->validate([$binding => $workflowId], $this->entityTypeManager) as $message) {
                $violations[] = new SchemaViolation($binding, $message);
            }
        }

        return $violations;
    }
}
