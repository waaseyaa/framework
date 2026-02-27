<?php

declare(strict_types=1);

namespace Aurora\Workflows\Tests\Unit;

use Aurora\Workflows\WorkflowTransition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkflowTransition::class)]
final class WorkflowTransitionTest extends TestCase
{
    public function testConstructorSetsProperties(): void
    {
        $transition = new WorkflowTransition(
            id: 'publish',
            label: 'Publish',
            from: ['draft', 'review'],
            to: 'published',
            weight: 3,
        );

        $this->assertSame('publish', $transition->id);
        $this->assertSame('Publish', $transition->label);
        $this->assertSame(['draft', 'review'], $transition->from);
        $this->assertSame('published', $transition->to);
        $this->assertSame(3, $transition->weight);
    }

    public function testDefaultWeight(): void
    {
        $transition = new WorkflowTransition(
            id: 'archive',
            label: 'Archive',
            from: ['published'],
            to: 'archived',
        );

        $this->assertSame(0, $transition->weight);
    }

    public function testIsReadonly(): void
    {
        $transition = new WorkflowTransition(
            id: 'publish',
            label: 'Publish',
            from: ['draft'],
            to: 'published',
        );

        $reflection = new \ReflectionClass($transition);
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testIsFinal(): void
    {
        $reflection = new \ReflectionClass(WorkflowTransition::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function testMultipleFromStates(): void
    {
        $transition = new WorkflowTransition(
            id: 'publish',
            label: 'Publish',
            from: ['draft', 'review', 'revision'],
            to: 'published',
        );

        $this->assertCount(3, $transition->from);
        $this->assertContains('draft', $transition->from);
        $this->assertContains('review', $transition->from);
        $this->assertContains('revision', $transition->from);
    }
}
