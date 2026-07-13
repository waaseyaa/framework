<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows;

/**
 * Declarative seed data for the framework-default `editorial` workflow
 * (CW-v1 WP-1, docs/specs/content-workflow.md). Definitions are data, not
 * code — this const array is seeded (as a `workflow` config entity) by
 * {@see WorkflowServiceProvider::boot()}; the array shape is the exact
 * `Workflow` hydration contract ({@see Workflow::__construct()}), NOT a
 * preset-in-code canonical definition. The retired `EditorialWorkflowPreset`
 * class is the anti-pattern this deliberately avoids (docs/specs/
 * content-workflow.md, "The default `editorial` workflow ships as config
 * data, not code").
 *
 * Upgrade contract (WP-2 rework Task 2, #1920, final-review finding #7): the
 * boot seed guarantees this SET of states and transitions exists on the
 * persisted `editorial` entity, version-independently of when that entity
 * was first created — {@see WorkflowServiceProvider::seedDefaultEditorialWorkflow()}
 * additively tops up an already-persisted `editorial` with any entry present
 * here but absent BY MACHINE NAME from the persisted entity, never modifying
 * or removing an entry that already exists. Operators customize the shipped
 * workflow by editing existing states/transitions (preserved verbatim by the
 * top-up) or by binding their own workflow id via `workflows.assignments`
 * instead. DELETING a shipped state or transition from the persisted entity
 * is re-added at the next boot — this const is the floor, not a ceiling.
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
            // CW-v1 option-1 (#1920 PR-6, design §7 "Restore `revise` to the
            // shipped editorial workflow"): mirrors Drupal editorial's
            // "Create New Draft" (published -> draft). This edge was pulled
            // from the shipped workflow during the WP-2 rework because no
            // read path was pointer-aware for a forward draft (final-review
            // findings #1/#2/#4/#5/#11) — see the retired deferral note this
            // PR closes in docs/specs/content-workflow.md. It is sanctioned
            // again now that the read side is pointer-aware BY CONSTRUCTION
            // (option-1 PRs 1-3: the base row holds the published revision,
            // so `find()`/JSON:API/SSR/search-index readers serve it without
            // patching). DefaultWorkflowsTest's structural pin — which used
            // to FORBID this exact shape — is inverted in this same PR to
            // assert this edge exists (see that test for the inversion
            // rationale). Upgraded installs receive it automatically via
            // WorkflowServiceProvider's additive top-up (machine-name keyed;
            // see DefaultWorkflowSeedTopUpTest).
            'revise' => [
                'label' => 'Create new draft',
                'from' => ['published'],
                'to' => 'draft',
                'permission' => 'use editorial transition revise',
            ],
        ],
    ];
}
