<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Read;

use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityValues;
use Waaseyaa\Entity\FieldableInterface;

/** Closed reader for the legacy editorial service's exact state/audit shape. @internal */
final class EditorialWorkflowLegacySubjectReader
{
    /** @var \Closure(EntityBase): array<string, mixed> */
    private readonly \Closure $values;

    public function __construct()
    {
        $this->values = \Closure::bind(
            static fn(EntityBase $entity): array => $entity->valueContainer->rawValues(),
            null,
            EntityBase::class,
        );
    }

    public function read(FieldableInterface $entity): EditorialWorkflowLegacySubject
    {
        $values = $entity instanceof EntityBase
            ? ($this->values)($entity)
            : [
                'workflow_state' => $entity->get('workflow_state'),
                'status' => $entity->get('status'),
                'workflow_audit' => $entity->get('workflow_audit'),
            ];
        $audit = $values['workflow_audit'] ?? [];

        return new EditorialWorkflowLegacySubject(
            workflowState: is_string($values['workflow_state'] ?? null) && $values['workflow_state'] !== '' ? $values['workflow_state'] : null,
            status: EntityValues::statusToInt($values['status'] ?? 0),
            audit: is_array($audit) ? array_values(array_filter($audit, 'is_array')) : [],
        );
    }
}
