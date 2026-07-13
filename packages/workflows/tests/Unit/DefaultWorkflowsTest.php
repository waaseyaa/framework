<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Workflows\DefaultWorkflows;
use Waaseyaa\Workflows\Workflow;

/**
 * @covers \Waaseyaa\Workflows\DefaultWorkflows
 */
#[CoversClass(DefaultWorkflows::class)]
final class DefaultWorkflowsTest extends TestCase
{
    #[Test]
    public function the_shipped_editorial_workflow_carries_the_revise_forward_draft_entry_edge(): void
    {
        // INVERSION, DELIBERATE (CW-v1 option-1 #1920 PR-6, design §7
        // "Restore `revise` to the shipped editorial workflow"): this test
        // used to be `..._has_no_published_to_draft_forward_draft_entry_edge`
        // and FORBADE any shipped transition whose `from` includes a
        // `published: true` state and whose `to` is a `default_revision:
        // false` state. That pin existed to block exactly this change until
        // the read side was safe (WP-2 rework, findings #1/#2/#4/#5/#11: no
        // reader was pointer-aware, so a forward draft's tip content leaked
        // publicly while status/pointer said "published"). Option-1 PRs 1-3
        // made the read side pointer-aware BY CONSTRUCTION (the base row
        // holds the published revision, not the tip — `find()`/JSON:API/SSR/
        // search-index readers serve it without per-reader patching), which
        // is exactly the condition the old pin's comment named as the
        // unblocking event. Per this PR's own instructions: never delete
        // this test, never work around it — invert it so a future regression
        // that silently drops the edge is caught immediately, the same way
        // the old pin caught a silent reintroduction.
        //
        // The pin now asserts the OPPOSITE shape: `revise` (published ->
        // draft, mirroring Drupal editorial's "Create New Draft") exists on
        // the shipped `EDITORIAL` const with exactly this shape. See
        // DefaultWorkflowSeedTopUpTest for the additive-top-up proof on
        // upgraded installs, and ForwardDraftFlowTest / the shipped-workflow
        // spine for the end-to-end mechanics.
        $transitions = DefaultWorkflows::EDITORIAL['transitions'];
        $this->assertArrayHasKey(
            'revise',
            $transitions,
            'The shipped `editorial` workflow must carry the `revise` forward-draft entry edge '
            . '(CW-v1 option-1 PR-6, #1920, design §7) — the read side has been pointer-aware since '
            . 'option-1 PRs 1-3, so the WP-2 rework descope this pin used to enforce no longer applies.',
        );

        $revise = $transitions['revise'];
        $this->assertSame('Create new draft', $revise['label'] ?? null);
        $this->assertSame(['published'], $revise['from'] ?? null);
        $this->assertSame('draft', $revise['to'] ?? null);
        $this->assertSame(
            'use editorial transition revise',
            $revise['permission'] ?? null,
            "The 'revise' permission must be the auto-derived `use editorial transition revise` shape "
            . '(Workflow::permissionFor()) — matching the pattern the other six shipped transitions follow.',
        );

        // The states involved must still carry the shape the forward-draft
        // mechanic depends on: 'published' is a published, default-revision
        // state; 'draft' is neither. If either state's flags ever drift, the
        // edge above stops meaning what this test says it means.
        $states = DefaultWorkflows::EDITORIAL['states'];
        $this->assertTrue($states['published']['published'] ?? false);
        $this->assertTrue($states['published']['default_revision'] ?? false);
        $this->assertFalse($states['draft']['published'] ?? true);
        $this->assertFalse($states['draft']['default_revision'] ?? true);
    }

    #[Test]
    public function the_shipped_editorial_workflow_carries_no_group_constraint(): void
    {
        // CW-v1 WP-3 (#1920): group_constraint is opt-in per transition. The
        // shipped `editorial` workflow must remain unconstrained — it
        // behaves exactly like Drupal core, with no department routing.
        $workflow = new Workflow(DefaultWorkflows::EDITORIAL);

        foreach ($workflow->getTransitions() as $transition) {
            $this->assertNull(
                $transition->groupConstraint,
                \sprintf("Transition '%s' unexpectedly carries a group_constraint.", $transition->id),
            );
        }

        foreach ($workflow->toConfig()['transitions'] as $transitionId => $transitionConfig) {
            $this->assertArrayNotHasKey(
                'group_constraint',
                $transitionConfig,
                \sprintf("Serialized transition '%s' unexpectedly carries a group_constraint key.", $transitionId),
            );
        }
    }
}
