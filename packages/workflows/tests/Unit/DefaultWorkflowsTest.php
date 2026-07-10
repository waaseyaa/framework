<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Workflows\DefaultWorkflows;

/**
 * @covers \Waaseyaa\Workflows\DefaultWorkflows
 */
#[CoversClass(DefaultWorkflows::class)]
final class DefaultWorkflowsTest extends TestCase
{
    #[Test]
    public function the_shipped_editorial_workflow_has_no_published_to_draft_forward_draft_entry_edge(): void
    {
        // WP-2 rework (#1920): the final whole-branch review found no read
        // path is pointer-aware for a forward draft (findings #1/#4/#11) —
        // the human chose option 2, deferring forward drafts on the shipped
        // `editorial` workflow to a follow-up (option 1, true
        // default-revision semantics). This pins the descope at the data
        // level: the shipped `EDITORIAL` const must never again carry a
        // transition whose `from` includes a `published: true` state and
        // whose `to` is a state with `default_revision: false` — i.e. no
        // shipped forward-draft entry edge. (The engine itself still
        // supports this shape for custom workflows — see
        // ForwardDraftFlowTest / ForwardDraftIntegrationTest, which
        // re-anchor on a test-local `editorial_forward` workflow.)
        $states = DefaultWorkflows::EDITORIAL['states'];
        $publishedStates = array_keys(array_filter(
            $states,
            static fn(array $state): bool => ($state['published'] ?? false) === true,
        ));
        $nonDefaultRevisionStates = array_keys(array_filter(
            $states,
            static fn(array $state): bool => ($state['default_revision'] ?? false) === false,
        ));

        foreach (DefaultWorkflows::EDITORIAL['transitions'] as $transitionId => $transition) {
            $from = (array) ($transition['from'] ?? []);
            $to = (string) ($transition['to'] ?? '');

            $entersFromPublished = array_intersect($from, $publishedStates) !== [];
            $entersNonDefaultRevisionState = \in_array($to, $nonDefaultRevisionStates, true);

            $this->assertFalse(
                $entersFromPublished && $entersNonDefaultRevisionState,
                \sprintf(
                    "Transition '%s' (from published -> non-default-revision state '%s') is a shipped "
                    . 'forward-draft entry edge. Forward drafts on the shipped `editorial` workflow are '
                    . 'deferred (WP-2 rework, #1920, option-1 follow-up — review findings #1/#4/#11): no '
                    . 'read path is pointer-aware yet. Do not reintroduce this edge on the shipped workflow; '
                    . 'the engine still supports it via a custom workflow.',
                    $transitionId,
                    $to,
                ),
            );
        }
    }
}
