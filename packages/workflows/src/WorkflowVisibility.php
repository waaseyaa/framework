<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Workflows\Read\WorkflowEntitySnapshotReader;

final class WorkflowVisibility
{
    private readonly WorkflowEntitySnapshotReader $snapshotReader;

    public function __construct(?WorkflowEntitySnapshotReader $snapshotReader = null)
    {
        $this->snapshotReader = $snapshotReader ?? new WorkflowEntitySnapshotReader();
    }

    /** Resolve a proposed state through the workflow declaration, never its id. */
    public function isCandidateStatePublic(Workflow $workflow, string $stateId): bool
    {
        $state = $workflow->getState($stateId);

        return $state !== null && $state->published;
    }

    /**
     * Answer whether the entity's materialized serving projection is public.
     *
     * For a forward draft, `workflow_state` may describe the working-copy tip
     * while `status` deliberately remains derived from the published pointer.
     */
    public function isEntityServedPublicForEntity(EntityInterface $entity): bool
    {
        $snapshot = $this->snapshotReader->read($entity);

        return $this->isStatusPublic($snapshot->status);
    }

    /**
     * @param array<string, mixed> $values
     */
    public function isEntityServedPublic(string $entityType, array $values): bool
    {
        // Entity type is retained because array visibility adapters carry it;
        // serving publication itself is the shared materialized status field.
        if (!array_key_exists('status', $values)) {
            return false;
        }

        return $this->isStatusPublic($values['status']);
    }

    public function isStatusPublic(mixed $status): bool
    {
        if (is_bool($status)) {
            return $status;
        }
        if (is_numeric($status)) {
            return ((int) $status) === 1;
        }
        if (is_string($status)) {
            return in_array(strtolower(trim($status)), ['1', 'true', 'published', 'yes'], true);
        }

        return false;
    }
}
