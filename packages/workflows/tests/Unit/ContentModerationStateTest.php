<?php

declare(strict_types=1);

namespace Aurora\Workflows\Tests\Unit;

use Aurora\Workflows\ContentModerationState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContentModerationState::class)]
final class ContentModerationStateTest extends TestCase
{
    public function testConstructorSetsProperties(): void
    {
        $state = new ContentModerationState(
            entityTypeId: 'node',
            entityId: 42,
            workflowId: 'editorial',
            stateId: 'draft',
        );

        $this->assertSame('node', $state->entityTypeId);
        $this->assertSame(42, $state->entityId);
        $this->assertSame('editorial', $state->workflowId);
        $this->assertSame('draft', $state->stateId);
    }

    public function testWithStringEntityId(): void
    {
        $state = new ContentModerationState(
            entityTypeId: 'node',
            entityId: 'abc-123',
            workflowId: 'editorial',
            stateId: 'published',
        );

        $this->assertSame('abc-123', $state->entityId);
    }

    public function testIsReadonly(): void
    {
        $state = new ContentModerationState(
            entityTypeId: 'node',
            entityId: 1,
            workflowId: 'editorial',
            stateId: 'draft',
        );

        $reflection = new \ReflectionClass($state);
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testIsFinal(): void
    {
        $reflection = new \ReflectionClass(ContentModerationState::class);
        $this->assertTrue($reflection->isFinal());
    }
}
