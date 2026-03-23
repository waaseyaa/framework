<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows;

final class WorkflowVisibility
{
    public function __construct(
        private readonly Workflow $workflow = new Workflow(),
    ) {}

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
        return $this->nodeState($values) === 'published';
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
            return true;
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
