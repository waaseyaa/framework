<?php

declare(strict_types=1);

namespace Aurora\Workflows;

/**
 * Value object representing a single state in a workflow.
 *
 * States are the nodes in a workflow graph (e.g. "draft", "published",
 * "archived"). Each state has a machine name, a human-readable label,
 * and a weight for ordering.
 */
final readonly class WorkflowState
{
    public function __construct(
        public string $id,
        public string $label,
        public int $weight = 0,
    ) {}
}
