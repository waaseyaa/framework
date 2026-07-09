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
            // CW-v1 WP-2 task 2.6 (#1920): the forward-draft entry edge —
            // editing live content back into 'draft' creates a new
            // non-default revision; the published revision keeps serving
            // until the next 'publish' promotes the draft. Mirrors Drupal
            // editorial's "Create New Draft" published -> draft edge;
            // required by the WP-2 integration spine (publish -> raw-save
            // edit into draft -> republish) on the shipped workflow.
            'revise' => [
                'label' => 'Revise',
                'from' => ['published'],
                'to' => 'draft',
                'permission' => 'use editorial transition revise',
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
            // CW-v1 WP-2 task 2.6 re-review (#1920): without this edge,
            // archived content is a dead end — 'restore' produces a forward
            // draft (the pointer stays on the archived revision), and that
            // draft's eventual publish is an archived -> published pointer
            // move the strict guard rule denies with no edge to satisfy it.
            // Mirrors Drupal editorial's "Restore" (archived_published)
            // edge, shipped alongside "Restore to draft" (archived_draft).
            // The existing 'restore' transition is deliberately NOT renamed
            // (its machine name and permission string are already live).
            'restore_to_published' => [
                'label' => 'Restore',
                'from' => ['archived'],
                'to' => 'published',
                'permission' => 'use editorial transition restore_to_published',
            ],
        ],
    ];
}
