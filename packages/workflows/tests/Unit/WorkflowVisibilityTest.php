<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Workflows\Workflow;
use Waaseyaa\Workflows\WorkflowState;
use Waaseyaa\Workflows\WorkflowVisibility;

/**
 * @covers \Waaseyaa\Workflows\WorkflowVisibility
 */
#[CoversClass(WorkflowVisibility::class)]
final class WorkflowVisibilityTest extends TestCase
{
    #[Test]
    public function candidate_visibility_uses_the_bound_workflow_state_declaration(): void
    {
        $visibility = new WorkflowVisibility();
        $workflow = new Workflow(['id' => 'custom', 'label' => 'Custom']);
        $workflow->addState(new WorkflowState(id: 'live', label: 'Live', published: true));
        $workflow->addState(new WorkflowState(id: 'published', label: 'Published', published: false));

        self::assertTrue($visibility->isCandidateStatePublic($workflow, 'live'));
        self::assertFalse($visibility->isCandidateStatePublic($workflow, 'published'));
        self::assertFalse($visibility->isCandidateStatePublic($workflow, 'missing'));
    }

    #[Test]
    public function served_visibility_uses_the_materialized_publication_projection(): void
    {
        $visibility = new WorkflowVisibility();

        self::assertTrue($visibility->isEntityServedPublic('node', [
            'workflow_state' => 'draft',
            'status' => 1,
        ]));
        self::assertFalse($visibility->isEntityServedPublic('node', [
            'workflow_state' => 'published',
            'status' => 0,
        ]));
    }

    #[Test]
    public function nonNodeEntitiesUseStatusFlagSemantics(): void
    {
        $visibility = new WorkflowVisibility();

        $this->assertTrue($visibility->isEntityServedPublic('relationship', ['status' => 1]));
        $this->assertFalse($visibility->isEntityServedPublic('relationship', ['status' => 0]));
        $this->assertTrue($visibility->isEntityServedPublic('relationship', ['status' => 'yes']));

        // A non-node entity type without a `status` key at all must fail closed
        // (not-visibly-published), the same as a present-but-garbage status value.
        // Previously this returned true (fail-open); audit #1915 R16.
        $this->assertFalse($visibility->isEntityServedPublic('taxonomy_term', []));
    }

    #[Test]
    public function nonNodeEntityMissingStatusKeyFailsClosed(): void
    {
        $visibility = new WorkflowVisibility();

        $this->assertFalse($visibility->isEntityServedPublic('workflow', []));
        $this->assertFalse($visibility->isEntityServedPublic('workflow', ['other_field' => 'x']));
    }

    #[Test]
    public function nonNodeEntityRecognizedPublishedStatusValuesArePublic(): void
    {
        $visibility = new WorkflowVisibility();

        $this->assertTrue($visibility->isEntityServedPublic('workflow', ['status' => 1]));
        $this->assertTrue($visibility->isEntityServedPublic('workflow', ['status' => true]));
        $this->assertTrue($visibility->isEntityServedPublic('workflow', ['status' => 'published']));
    }

    #[Test]
    public function nonNodeEntityUnrecognizedStatusValuesAreNotPublic(): void
    {
        $visibility = new WorkflowVisibility();

        $this->assertFalse($visibility->isEntityServedPublic('workflow', ['status' => 0]));
        $this->assertFalse($visibility->isEntityServedPublic('workflow', ['status' => false]));
        $this->assertFalse($visibility->isEntityServedPublic('workflow', ['status' => 'garbage']));
    }

    #[Test]
    public function served_entity_visibility_uses_cast_aware_status(): void
    {
        $entity = new class (['type' => 'article', 'status' => 1]) extends ContentEntityBase {
            /** @var array<string, string> */
            protected array $casts = ['status' => 'bool'];

            public function __construct(array $values = [])
            {
                parent::__construct($values, 'node', [
                    'id' => 'nid',
                    'uuid' => 'uuid',
                    'label' => 'title',
                    'bundle' => 'type',
                ]);
            }
        };

        $visibility = new WorkflowVisibility();

        $this->assertTrue($visibility->isEntityServedPublicForEntity($entity));
    }
}
