<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows;

/**
 * Declarative seed data for the framework-default `editorial` workflow
 * (CW-v1 WP-1, docs/specs/content-workflow.md). Definitions are data, not
 * code — this const array is seeded once (as a `workflow` config entity) by
 * {@see WorkflowServiceProvider::boot()}; the array shape is the exact
 * `Workflow` hydration contract ({@see Workflow::__construct()}), NOT a
 * preset-in-code canonical definition. The retired `EditorialWorkflowPreset`
 * class is the anti-pattern this deliberately avoids (docs/specs/
 * content-workflow.md, "The default `editorial` workflow ships as config
 * data, not code").
 *
 * @api
 */
final class DefaultWorkflows
{
    /** @var array<string, mixed> */
    public const array EDITORIAL = [
        'id' => 'editorial',
        'label' => 'Editorial',
        'initial_state' => 'draft',
        'states' => [
            'draft' => ['label' => 'Draft', 'published' => false, 'default_revision' => false],
            'review' => ['label' => 'In review', 'published' => false, 'default_revision' => false],
            'published' => ['label' => 'Published', 'published' => true, 'default_revision' => true],
            'archived' => ['label' => 'Archived', 'published' => false, 'default_revision' => true],
        ],
        'transitions' => [
            'submit_for_review' => [
                'label' => 'Submit for review',
                'from' => ['draft'],
                'to' => 'review',
                'permission' => 'use editorial transition submit_for_review',
            ],
            'publish' => [
                'label' => 'Publish',
                'from' => ['draft', 'review'],
                'to' => 'published',
                'permission' => 'use editorial transition publish',
            ],
            'reject' => [
                'label' => 'Send back',
                'from' => ['review'],
                'to' => 'draft',
                'permission' => 'use editorial transition reject',
            ],
            'archive' => [
                'label' => 'Archive',
                'from' => ['published'],
                'to' => 'archived',
                'permission' => 'use editorial transition archive',
            ],
            'restore' => [
                'label' => 'Restore to draft',
                'from' => ['archived'],
                'to' => 'draft',
                'permission' => 'use editorial transition restore',
            ],
        ],
    ];
}
