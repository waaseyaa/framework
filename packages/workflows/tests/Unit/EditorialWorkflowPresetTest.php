<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Workflows\EditorialWorkflowPreset;
use Waaseyaa\Workflows\Workflow;

#[CoversClass(EditorialWorkflowPreset::class)]
final class EditorialWorkflowPresetTest extends TestCase
{
    public function testCreateReturnsWorkflowWithEditorialStates(): void
    {
        $workflow = EditorialWorkflowPreset::create();

        $this->assertInstanceOf(Workflow::class, $workflow);
        $this->assertSame('editorial', $workflow->id());

        $states = $workflow->getStates();
        $this->assertCount(4, $states);
        $this->assertArrayHasKey('draft', $states);
        $this->assertArrayHasKey('review', $states);
        $this->assertArrayHasKey('published', $states);
        $this->assertArrayHasKey('archived', $states);
    }

    public function testCreateReturnsWorkflowWithEditorialTransitions(): void
    {
        $workflow = EditorialWorkflowPreset::create();

        $transitions = $workflow->getTransitions();
        $this->assertCount(6, $transitions);
        $this->assertArrayHasKey('submit_for_review', $transitions);
        $this->assertArrayHasKey('send_back', $transitions);
        $this->assertArrayHasKey('publish', $transitions);
        $this->assertArrayHasKey('unpublish', $transitions);
        $this->assertArrayHasKey('archive', $transitions);
        $this->assertArrayHasKey('restore', $transitions);
    }

    public function testPublishTransitionGoesFromReviewToPublished(): void
    {
        $workflow = EditorialWorkflowPreset::create();
        $publish = $workflow->getTransition('publish');

        $this->assertNotNull($publish);
        $this->assertSame(['review'], $publish->from);
        $this->assertSame('published', $publish->to);
    }

    public function testPublishedStateCarriesLegacyStatusMetadata(): void
    {
        $workflow = EditorialWorkflowPreset::create();
        $published = $workflow->getState('published');

        $this->assertNotNull($published);
        $this->assertSame(1, $published->metadata['legacy_status']);
    }

    public function testNormalizeStateFallsBackToStatus(): void
    {
        $this->assertSame('published', EditorialWorkflowPreset::normalizeState(null, 1));
        $this->assertSame('draft', EditorialWorkflowPreset::normalizeState(null, 0));
        $this->assertSame('published', EditorialWorkflowPreset::normalizeState('', true));
        $this->assertSame('draft', EditorialWorkflowPreset::normalizeState('', false));
    }

    public function testStatusForState(): void
    {
        $this->assertSame(1, EditorialWorkflowPreset::statusForState('published'));
        $this->assertSame(0, EditorialWorkflowPreset::statusForState('draft'));
        $this->assertSame(0, EditorialWorkflowPreset::statusForState('review'));
        $this->assertSame(0, EditorialWorkflowPreset::statusForState('archived'));
    }

    public function testWorkflowIsTransitionAllowed(): void
    {
        $workflow = EditorialWorkflowPreset::create();

        $this->assertTrue($workflow->isTransitionAllowed('draft', 'review'));
        $this->assertTrue($workflow->isTransitionAllowed('review', 'published'));
        $this->assertFalse($workflow->isTransitionAllowed('draft', 'archived'));
    }

    public function testCustomNonEditorialWorkflow(): void
    {
        // Verify Workflow works with custom states (Claudriel commitment lifecycle)
        $workflow = new Workflow([
            'id' => 'commitment',
            'label' => 'Commitment Lifecycle',
            'states' => [
                'pending' => ['label' => 'Pending'],
                'active' => ['label' => 'Active'],
                'completed' => ['label' => 'Completed'],
                'archived' => ['label' => 'Archived'],
            ],
            'transitions' => [
                'activate' => ['label' => 'Activate', 'from' => ['pending'], 'to' => 'active'],
                'complete' => ['label' => 'Complete', 'from' => ['active'], 'to' => 'completed'],
                'archive' => ['label' => 'Archive', 'from' => ['completed'], 'to' => 'archived'],
                'reopen' => ['label' => 'Re-open', 'from' => ['archived'], 'to' => 'pending'],
            ],
        ]);

        $this->assertTrue($workflow->isTransitionAllowed('pending', 'active'));
        $this->assertFalse($workflow->isTransitionAllowed('pending', 'completed'));
        $this->assertCount(4, $workflow->getStates());
    }
}
