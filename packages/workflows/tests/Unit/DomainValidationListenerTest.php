<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\FieldableInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Workflows\DomainValidationListener;

#[CoversClass(DomainValidationListener::class)]
final class DomainValidationListenerTest extends TestCase
{
    public function testAllowsPublishedToArchivedTransition(): void
    {
        $existing = new TestWorkflowEntity([
            'nid' => 1,
            'type' => 'article',
            'title' => 'Existing title',
            'body' => 'Existing body',
            'workflow_state' => 'published',
            'status' => 1,
        ]);

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('load')->with(1)->willReturn($existing);

        $manager = $this->createEntityTypeManager($storage);
        $listener = new DomainValidationListener(
            entityTypeManager: $manager,
            workflowBundles: ['article'],
        );

        $entity = new TestWorkflowEntity([
            'nid' => 1,
            'type' => 'article',
            'title' => 'Archived title',
            'body' => 'Archived body',
            'workflow_state' => 'archived',
            'status' => 0,
        ]);

        $listener(new EntityEvent($entity));

        $this->assertSame('archived', $entity->get('workflow_state'));
        $this->assertSame(0, $entity->get('status'));
    }

    public function testRejectsInvalidTransitionToArchivedFromDraft(): void
    {
        $existing = new TestWorkflowEntity([
            'nid' => 1,
            'type' => 'article',
            'title' => 'Existing title',
            'body' => 'Existing body',
            'workflow_state' => 'draft',
            'status' => 0,
        ]);

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('load')->with(1)->willReturn($existing);

        $manager = $this->createEntityTypeManager($storage);
        $listener = new DomainValidationListener(
            entityTypeManager: $manager,
            workflowBundles: ['article'],
        );

        $entity = new TestWorkflowEntity([
            'nid' => 1,
            'type' => 'article',
            'title' => 'Edited title',
            'body' => 'Edited body',
            'workflow_state' => 'archived',
            'status' => 0,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid workflow transition draft -> archived');
        $listener(new EntityEvent($entity));
    }

    public function testRejectsUnknownWorkflowState(): void
    {
        $storage = $this->createMock(EntityStorageInterface::class);
        $manager = $this->createEntityTypeManager($storage);
        $listener = new DomainValidationListener(
            entityTypeManager: $manager,
            workflowBundles: ['article'],
        );

        $entity = new TestWorkflowEntity([
            'nid' => 1,
            'type' => 'article',
            'title' => 'Invalid state title',
            'body' => 'Invalid state body',
            'workflow_state' => 'inbox',
            'status' => 0,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid workflow_state "inbox"');
        $listener(new EntityEvent($entity));
    }

    private function createEntityTypeManager(EntityStorageInterface $storage): EntityTypeManagerInterface
    {
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('getStorage')->with('node')->willReturn($storage);
        $manager->method('hasDefinition')->willReturn(false);
        $manager->method('getDefinitions')->willReturn([]);
        $manager->method('getDefinition')->willThrowException(new \RuntimeException('Not needed in test'));

        return $manager;
    }
}

final class TestWorkflowEntity implements EntityInterface, FieldableInterface
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private array $values) {}

    public function id(): int|string|null
    {
        return $this->values['nid'] ?? null;
    }

    public function uuid(): string
    {
        return (string) ($this->values['uuid'] ?? '');
    }

    public function label(): string
    {
        return (string) ($this->values['title'] ?? '');
    }

    public function getEntityTypeId(): string
    {
        return 'node';
    }

    public function bundle(): string
    {
        return (string) ($this->values['type'] ?? '');
    }

    public function isNew(): bool
    {
        return $this->id() === null;
    }

    public function toArray(): array
    {
        return $this->values;
    }

    public function language(): string
    {
        return 'en';
    }

    public function hasField(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    public function get(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }

    public function set(string $name, mixed $value): static
    {
        $this->values[$name] = $value;

        return $this;
    }

    public function getFieldDefinitions(): array
    {
        return [];
    }
}
