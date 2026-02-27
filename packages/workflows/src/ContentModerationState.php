<?php

declare(strict_types=1);

namespace Aurora\Workflows;

/**
 * Value object representing the current moderation state of an entity.
 *
 * Associates a specific entity with its workflow and current state
 * within that workflow.
 */
final readonly class ContentModerationState
{
    public function __construct(
        public string $entityTypeId,
        public int|string $entityId,
        public string $workflowId,
        public string $stateId,
    ) {}
}
