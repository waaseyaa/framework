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

    /**
     * @param array<string, mixed> $values
     */
    public function nodeState(array $values): string
    {
        $status = $values['status'] ?? 0;
        if (is_string($status)) {
            $normalized = strtolower(trim($status));
            if (in_array($normalized, ['published', 'yes'], true)) {
                $status = 1;
            }
        }

        return EditorialWorkflowPreset::normalizeState(
            workflowState: $values['workflow_state'] ?? null,
            status: $status,
        );
    }

    /**
     * @param array<string, mixed> $values
     */
    public function isNodePublic(array $values): bool
    {
        return $this->nodeState($values) === EditorialWorkflowPreset::STATE_PUBLISHED;
    }

    public function isNodePublicForEntity(EntityInterface $entity): bool
    {
        $snapshot = $this->snapshotReader->read($entity);

        return $this->isNodePublic([
            'workflow_state' => $snapshot->workflowState,
            'status' => $snapshot->status,
        ]);
    }

    public function isEntityPublicForEntity(EntityInterface $entity): bool
    {
        $snapshot = $this->snapshotReader->read($entity);

        if ($entity->getEntityTypeId() === 'node') {
            return $this->isNodePublic([
                'workflow_state' => $snapshot->workflowState,
                'status' => $snapshot->status,
            ]);
        }

        return $this->isStatusPublic($snapshot->status);
    }

    /**
     * @param array<string, mixed> $values
     */
    public function isEntityPublic(string $entityType, array $values): bool
    {
        if ($entityType === 'node') {
            return $this->isNodePublic($values);
        }

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
