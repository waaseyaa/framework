<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows;

/**
 * Value object representing a single state in a workflow.
 *
 * States are the nodes in a workflow graph (e.g. "draft", "published",
 * "archived"). Each state has a machine name, a human-readable label,
 * and a weight for ordering.
 *
 * CW-v1 WP-1 (docs/specs/content-workflow.md): `$published` drives the
 * entity's `status` field when this state's revision is the default
 * revision; `$defaultRevision` marks a state whose entry promotes its
 * revision to default (the forward-draft mechanic — publishing a draft
 * revision of already-published content promotes it to default/live).
 */
final readonly class WorkflowState
{
    public function __construct(
        public string $id,
        public string $label,
        public int $weight = 0,
        public array $metadata = [],
        public bool $published = false,
        public bool $defaultRevision = false,
    ) {}
}
