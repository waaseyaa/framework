<?php

declare(strict_types=1);

namespace Aurora\Workflows\Tests\Unit;

use Aurora\Workflows\ContentModerationState;
use Aurora\Workflows\ContentModerator;
use Aurora\Workflows\Workflow;
use Aurora\Workflows\WorkflowState;
use Aurora\Workflows\WorkflowTransition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContentModerator::class)]
final class ContentModeratorTest extends TestCase
{
    private function createEditorialWorkflow(): Workflow
    {
        $workflow = new Workflow([
            'id' => 'editorial',
            'label' => 'Editorial',
        ]);

        $workflow->addState(new WorkflowState('draft', 'Draft'));
        $workflow->addState(new WorkflowState('review', 'In Review'));
        $workflow->addState(new WorkflowState('published', 'Published'));

        $workflow->addTransition(new WorkflowTransition(
            id: 'submit',
            label: 'Submit for Review',
            from: ['draft'],
            to: 'review',
        ));
        $workflow->addTransition(new WorkflowTransition(
            id: 'publish',
            label: 'Publish',
            from: ['draft', 'review'],
            to: 'published',
        ));
        $workflow->addTransition(new WorkflowTransition(
            id: 'send_back',
            label: 'Send Back to Draft',
            from: ['review'],
            to: 'draft',
        ));

        return $workflow;
    }

    // ---------------------------------------------------------------
    //  Workflow management
    // ---------------------------------------------------------------

    public function testAddAndGetWorkflow(): void
    {
        $moderator = new ContentModerator();
        $workflow = $this->createEditorialWorkflow();

        $moderator->addWorkflow($workflow);

        $this->assertSame($workflow, $moderator->getWorkflow('editorial'));
    }

    public function testGetWorkflowReturnsNullForUnknown(): void
    {
        $moderator = new ContentModerator();

        $this->assertNull($moderator->getWorkflow('nonexistent'));
    }

    public function testConstructorAcceptsWorkflows(): void
    {
        $workflow = $this->createEditorialWorkflow();
        $moderator = new ContentModerator(['editorial' => $workflow]);

        $this->assertSame($workflow, $moderator->getWorkflow('editorial'));
    }

    // ---------------------------------------------------------------
    //  Transition happy path
    // ---------------------------------------------------------------

    public function testTransitionHappyPath(): void
    {
        $moderator = new ContentModerator();
        $moderator->addWorkflow($this->createEditorialWorkflow());

        $currentState = new ContentModerationState(
            entityTypeId: 'node',
            entityId: 1,
            workflowId: 'editorial',
            stateId: 'draft',
        );

        $newState = $moderator->transition($currentState, 'review');

        $this->assertSame('review', $newState->stateId);
        $this->assertSame('node', $newState->entityTypeId);
        $this->assertSame(1, $newState->entityId);
        $this->assertSame('editorial', $newState->workflowId);
    }

    public function testTransitionChain(): void
    {
        $moderator = new ContentModerator();
        $moderator->addWorkflow($this->createEditorialWorkflow());

        $state = new ContentModerationState('node', 1, 'editorial', 'draft');

        // draft -> review
        $state = $moderator->transition($state, 'review');
        $this->assertSame('review', $state->stateId);

        // review -> published
        $state = $moderator->transition($state, 'published');
        $this->assertSame('published', $state->stateId);
    }

    public function testTransitionDirectPublish(): void
    {
        $moderator = new ContentModerator();
        $moderator->addWorkflow($this->createEditorialWorkflow());

        $state = new ContentModerationState('node', 1, 'editorial', 'draft');

        // draft -> published (skip review)
        $state = $moderator->transition($state, 'published');
        $this->assertSame('published', $state->stateId);
    }

    // ---------------------------------------------------------------
    //  Transition error paths
    // ---------------------------------------------------------------

    public function testTransitionThrowsForInvalidTransition(): void
    {
        $moderator = new ContentModerator();
        $moderator->addWorkflow($this->createEditorialWorkflow());

        $currentState = new ContentModerationState('node', 1, 'editorial', 'published');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Transition from "published" to "draft" is not allowed');

        $moderator->transition($currentState, 'draft');
    }

    public function testTransitionThrowsForUnknownWorkflow(): void
    {
        $moderator = new ContentModerator();

        $currentState = new ContentModerationState('node', 1, 'nonexistent', 'draft');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Workflow "nonexistent" not found');

        $moderator->transition($currentState, 'published');
    }

    // ---------------------------------------------------------------
    //  Available transitions
    // ---------------------------------------------------------------

    public function testGetAvailableTransitionsFromDraft(): void
    {
        $moderator = new ContentModerator();
        $moderator->addWorkflow($this->createEditorialWorkflow());

        $state = new ContentModerationState('node', 1, 'editorial', 'draft');
        $transitions = $moderator->getAvailableTransitions($state);

        $this->assertCount(2, $transitions);
        $this->assertArrayHasKey('submit', $transitions);
        $this->assertArrayHasKey('publish', $transitions);
    }

    public function testGetAvailableTransitionsFromPublished(): void
    {
        $moderator = new ContentModerator();
        $moderator->addWorkflow($this->createEditorialWorkflow());

        $state = new ContentModerationState('node', 1, 'editorial', 'published');
        $transitions = $moderator->getAvailableTransitions($state);

        // No transitions defined from published in this test workflow.
        $this->assertCount(0, $transitions);
    }

    public function testGetAvailableTransitionsThrowsForUnknownWorkflow(): void
    {
        $moderator = new ContentModerator();

        $state = new ContentModerationState('node', 1, 'nonexistent', 'draft');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Workflow "nonexistent" not found');

        $moderator->getAvailableTransitions($state);
    }

    // ---------------------------------------------------------------
    //  Edge cases
    // ---------------------------------------------------------------

    public function testAddWorkflowWithoutIdThrows(): void
    {
        $moderator = new ContentModerator();
        $workflow = new Workflow(['label' => 'No ID']);

        $this->expectException(\InvalidArgumentException::class);

        $moderator->addWorkflow($workflow);
    }

    public function testTransitionPreservesEntityMetadata(): void
    {
        $moderator = new ContentModerator();
        $moderator->addWorkflow($this->createEditorialWorkflow());

        $currentState = new ContentModerationState(
            entityTypeId: 'article',
            entityId: 'uuid-abc-123',
            workflowId: 'editorial',
            stateId: 'draft',
        );

        $newState = $moderator->transition($currentState, 'review');

        $this->assertSame('article', $newState->entityTypeId);
        $this->assertSame('uuid-abc-123', $newState->entityId);
        $this->assertSame('editorial', $newState->workflowId);
    }

    public function testMultipleWorkflows(): void
    {
        $editorial = $this->createEditorialWorkflow();

        $simple = new Workflow(['id' => 'simple', 'label' => 'Simple']);
        $simple->addState(new WorkflowState('unpublished', 'Unpublished'));
        $simple->addState(new WorkflowState('published', 'Published'));
        $simple->addTransition(new WorkflowTransition(
            id: 'publish',
            label: 'Publish',
            from: ['unpublished'],
            to: 'published',
        ));

        $moderator = new ContentModerator();
        $moderator->addWorkflow($editorial);
        $moderator->addWorkflow($simple);

        // Use editorial workflow.
        $state1 = new ContentModerationState('node', 1, 'editorial', 'draft');
        $state1 = $moderator->transition($state1, 'review');
        $this->assertSame('review', $state1->stateId);

        // Use simple workflow.
        $state2 = new ContentModerationState('page', 2, 'simple', 'unpublished');
        $state2 = $moderator->transition($state2, 'published');
        $this->assertSame('published', $state2->stateId);
    }
}
