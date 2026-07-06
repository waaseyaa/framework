<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Workflows\WorkflowVisibility;

/**
 * @covers \Waaseyaa\Workflows\WorkflowVisibility
 */
#[CoversClass(WorkflowVisibility::class)]
final class WorkflowVisibilityTest extends TestCase
{
    #[Test]
    public function nodeVisibilityRespectsWorkflowStateFirst(): void
    {
        $visibility = new WorkflowVisibility();

        $this->assertTrue($visibility->isNodePublic([
            'workflow_state' => 'published',
            'status' => 0,
        ]));

        $this->assertFalse($visibility->isNodePublic([
            'workflow_state' => 'review',
            'status' => 1,
        ]));
    }

    #[Test]
    public function nodeVisibilityFallsBackToStatusWhenWorkflowStateMissing(): void
    {
        $visibility = new WorkflowVisibility();

        $this->assertTrue($visibility->isNodePublic(['status' => 1]));
        $this->assertFalse($visibility->isNodePublic(['status' => 0]));
        $this->assertTrue($visibility->isNodePublic(['status' => 'published']));
        $this->assertFalse($visibility->isNodePublic(['status' => 'draft']));
    }

    #[Test]
    public function nonNodeEntitiesUseStatusFlagSemantics(): void
    {
        $visibility = new WorkflowVisibility();

        $this->assertTrue($visibility->isEntityPublic('relationship', ['status' => 1]));
        $this->assertFalse($visibility->isEntityPublic('relationship', ['status' => 0]));
        $this->assertTrue($visibility->isEntityPublic('relationship', ['status' => 'yes']));

        // A non-node entity type without a `status` key at all must fail closed
        // (not-visibly-published), the same as a present-but-garbage status value.
        // Previously this returned true (fail-open); audit #1915 R16.
        $this->assertFalse($visibility->isEntityPublic('taxonomy_term', []));
    }

    #[Test]
    public function nonNodeEntityMissingStatusKeyFailsClosed(): void
    {
        $visibility = new WorkflowVisibility();

        $this->assertFalse($visibility->isEntityPublic('workflow', []));
        $this->assertFalse($visibility->isEntityPublic('workflow', ['other_field' => 'x']));
    }

    #[Test]
    public function nonNodeEntityRecognizedPublishedStatusValuesArePublic(): void
    {
        $visibility = new WorkflowVisibility();

        $this->assertTrue($visibility->isEntityPublic('workflow', ['status' => 1]));
        $this->assertTrue($visibility->isEntityPublic('workflow', ['status' => true]));
        $this->assertTrue($visibility->isEntityPublic('workflow', ['status' => 'published']));
    }

    #[Test]
    public function nonNodeEntityUnrecognizedStatusValuesAreNotPublic(): void
    {
        $visibility = new WorkflowVisibility();

        $this->assertFalse($visibility->isEntityPublic('workflow', ['status' => 0]));
        $this->assertFalse($visibility->isEntityPublic('workflow', ['status' => false]));
        $this->assertFalse($visibility->isEntityPublic('workflow', ['status' => 'garbage']));
    }

    #[Test]
    public function isNodePublicForEntityUsesCastAwareStatus(): void
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

        $this->assertTrue($visibility->isNodePublicForEntity($entity));
    }
}
