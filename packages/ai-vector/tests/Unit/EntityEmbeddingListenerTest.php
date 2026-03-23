<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Vector\EmbeddingProviderInterface;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\AI\Vector\EntityEmbeddingListener;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Queue\Message\GenericMessage;
use Waaseyaa\Queue\QueueInterface;

#[CoversClass(EntityEmbeddingListener::class)]
final class EntityEmbeddingListenerTest extends TestCase
{
    #[Test]
    public function dispatchesEmbeddingMessageOnPostSave(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (object $message): bool {
                if (!$message instanceof GenericMessage) {
                    return false;
                }

                return $message->type === 'ai_vector.embed_entity'
                    && ($message->payload['entity_type'] ?? null) === 'node'
                    && ($message->payload['entity_id'] ?? null) === '42'
                    && ($message->payload['langcode'] ?? null) === 'en';
            }));

        $listener = new EntityEmbeddingListener($queue);
        $listener->onPostSave(new EntityEvent(new TestEmbeddingEntity(
            id: 42,
            entityTypeId: 'node',
            values: ['status' => 1, 'workflow_state' => 'published', 'title' => 'Published node'],
        )));
    }

    #[Test]
    public function skipsDispatchWhenEntityIdIsMissing(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->expects($this->never())->method('dispatch');

        $listener = new EntityEmbeddingListener($queue);
        $listener->onPostSave(new EntityEvent(new TestEmbeddingEntity(
            id: null,
            entityTypeId: 'node',
            values: ['status' => 1, 'workflow_state' => 'published'],
        )));
    }

    #[Test]
    public function doesNotDispatchForUnpublishedNodeState(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->expects($this->never())->method('dispatch');

        $listener = new EntityEmbeddingListener($queue);
        $listener->onPostSave(new EntityEvent(new TestEmbeddingEntity(
            id: 42,
            entityTypeId: 'node',
            values: ['status' => 0, 'workflow_state' => 'draft'],
        )));
    }

    #[Test]
    public function removesEmbeddingForUnpublishedNodeWhenStorageAvailable(): void
    {
        $storage = $this->createMock(EmbeddingStorageInterface::class);
        $storage->expects($this->once())
            ->method('delete')
            ->with('node', '42');

        $listener = new EntityEmbeddingListener(
            queue: null,
            storage: $storage,
            embeddingProvider: null,
        );
        $listener->onPostSave(new EntityEvent(new TestEmbeddingEntity(
            id: 42,
            entityTypeId: 'node',
            values: ['status' => 0, 'workflow_state' => 'archived'],
        )));
    }

    #[Test]
    public function storesEmbeddingForPublishedNodeWhenProviderAndStorageAvailable(): void
    {
        $provider = $this->createMock(EmbeddingProviderInterface::class);
        $provider->expects($this->once())
            ->method('embed')
            ->with($this->stringContains('Vector Title'))
            ->willReturn([0.1, 0.2, 0.3]);

        $storage = $this->createMock(EmbeddingStorageInterface::class);
        $storage->expects($this->once())
            ->method('store')
            ->with('node', '42', [0.1, 0.2, 0.3]);

        $listener = new EntityEmbeddingListener(
            queue: null,
            storage: $storage,
            embeddingProvider: $provider,
        );
        $listener->onPostSave(new EntityEvent(new TestEmbeddingEntity(
            id: 42,
            entityTypeId: 'node',
            values: [
                'status' => 1,
                'workflow_state' => 'published',
                'title' => 'Vector Title',
                'body' => 'Vector Body',
            ],
        )));
    }
}

final readonly class TestEmbeddingEntity implements EntityInterface
{
    public function __construct(
        private int|string|null $id,
        private string $entityTypeId,
        private array $values = [],
    ) {}

    public function id(): int|string|null { return $this->id; }
    public function uuid(): string { return 'uuid'; }
    public function label(): string { return 'Label'; }
    public function getEntityTypeId(): string { return $this->entityTypeId; }
    public function bundle(): string { return 'default'; }
    public function isNew(): bool { return false; }
    public function get(string $name): mixed { return $this->values[$name] ?? null; }
    public function set(string $name, mixed $value): static { throw new \LogicException('Readonly'); }
    public function toArray(): array { return $this->values; }
    public function language(): string { return 'en'; }
}
