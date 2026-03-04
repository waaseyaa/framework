<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Workflows\EditorialWorkflowStateMachine;

#[CoversClass(EditorialWorkflowStateMachine::class)]
final class EditorialWorkflowStateMachineTest extends TestCase
{
    public function testStatesAreCanonicalAndOrdered(): void
    {
        $machine = new EditorialWorkflowStateMachine();

        $this->assertSame(
            ['draft', 'review', 'published', 'archived'],
            $machine->states(),
        );
    }

    public function testNormalizeStateFallsBackToStatus(): void
    {
        $machine = new EditorialWorkflowStateMachine();

        $this->assertSame('published', $machine->normalizeState(null, 1));
        $this->assertSame('draft', $machine->normalizeState(null, 0));
        $this->assertSame('published', $machine->normalizeState('', true));
        $this->assertSame('draft', $machine->normalizeState('', false));
    }

    public function testAssertTransitionAllowedForValidTransitions(): void
    {
        $machine = new EditorialWorkflowStateMachine();

        $draftToReview = $machine->assertTransitionAllowed('draft', 'review');
        $this->assertSame('submit_for_review', $draftToReview['id']);
        $this->assertSame('submit {bundle} for review', $draftToReview['permission']);

        $reviewToPublished = $machine->assertTransitionAllowed('review', 'published');
        $this->assertSame('publish', $reviewToPublished['id']);

        $publishedToArchived = $machine->assertTransitionAllowed('published', 'archived');
        $this->assertSame('archive', $publishedToArchived['id']);
    }

    public function testAssertTransitionAllowedThrowsForUnknownState(): void
    {
        $machine = new EditorialWorkflowStateMachine();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown workflow state: "unknown".');

        $machine->assertTransitionAllowed('unknown', 'draft');
    }

    public function testAssertTransitionAllowedThrowsForIllegalEdge(): void
    {
        $machine = new EditorialWorkflowStateMachine();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid workflow transition: draft -> archived.');

        $machine->assertTransitionAllowed('draft', 'archived');
    }

    public function testAvailableTransitionsFromReview(): void
    {
        $machine = new EditorialWorkflowStateMachine();
        $transitions = $machine->availableTransitions('review');

        $this->assertCount(2, $transitions);
        $this->assertSame('publish', $transitions[0]['id']);
        $this->assertSame('send_back', $transitions[1]['id']);
    }
}
