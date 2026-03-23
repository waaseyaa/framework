<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit;

use Waaseyaa\Workflows\WorkflowState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkflowState::class)]
final class WorkflowStateTest extends TestCase
{
    public function testConstructorSetsProperties(): void
    {
        $state = new WorkflowState(id: 'draft', label: 'Draft', weight: 5);

        $this->assertSame('draft', $state->id);
        $this->assertSame('Draft', $state->label);
        $this->assertSame(5, $state->weight);
    }

    public function testDefaultWeight(): void
    {
        $state = new WorkflowState(id: 'published', label: 'Published');

        $this->assertSame(0, $state->weight);
    }

    public function testIsReadonly(): void
    {
        $state = new WorkflowState(id: 'draft', label: 'Draft');

        $reflection = new \ReflectionClass($state);
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testIsFinal(): void
    {
        $reflection = new \ReflectionClass(WorkflowState::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function testMetadataDefaults(): void
    {
        $state = new WorkflowState(id: 'draft', label: 'Draft');
        $this->assertSame([], $state->metadata);
    }

    public function testMetadataIsPreserved(): void
    {
        $state = new WorkflowState(
            id: 'published',
            label: 'Published',
            metadata: ['legacy_status' => 1],
        );
        $this->assertSame(['legacy_status' => 1], $state->metadata);
    }
}
