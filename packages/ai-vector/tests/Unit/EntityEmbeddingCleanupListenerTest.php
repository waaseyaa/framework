<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\AI\Vector\EntityEmbeddingCleanupListener;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Event\EntityEvent;

#[CoversClass(EntityEmbeddingCleanupListener::class)]
final class EntityEmbeddingCleanupListenerTest extends TestCase
{
    private function manager(?EntityInterface $entity): \Waaseyaa\Entity\EntityTypeManagerInterface
    {
        $repository = $this->createStub(\Waaseyaa\Entity\Repository\EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($entity);
        $manager = $this->createStub(\Waaseyaa\Entity\EntityTypeManagerInterface::class);
        $manager->method('getRepository')->willReturn($repository);

        return $manager;
    }

    #[Test]
    public function missing_manager_refuses_without_deleting_and_recreated_entity_is_preserved(): void
    {
        $storage = $this->createMock(EmbeddingStorageInterface::class);
        $storage->expects(self::never())->method('delete');
        $logger = $this->createMock(\Waaseyaa\Foundation\Log\LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(self::stringContains('AIV-EXECUTION-005'));
        new EntityEmbeddingCleanupListener($storage, logger: $logger, executionGuard: new \Waaseyaa\AI\Vector\Testing\InMemoryEmbeddingExecutionGuard())->onPostDelete(new EntityEvent(new CleanupTestEntity(42, 'node')));
        new EntityEmbeddingCleanupListener($storage, executionGuard: new \Waaseyaa\AI\Vector\Testing\InMemoryEmbeddingExecutionGuard(), entityTypeManager: $this->manager(new CleanupTestEntity(42, 'node')))->onPostDelete(new EntityEvent(new CleanupTestEntity(42, 'node')));
    }

    #[Test]
    public function deletesEmbeddingOnPostDeleteWhenEntityIdExists(): void
    {
        $storage = $this->createMock(EmbeddingStorageInterface::class);
        $storage->expects($this->once())
            ->method('delete')
            ->with('node', '42');

        $listener = new EntityEmbeddingCleanupListener($storage, executionGuard: new \Waaseyaa\AI\Vector\Testing\InMemoryEmbeddingExecutionGuard(), entityTypeManager: $this->manager(null));
        $listener->onPostDelete(new EntityEvent(new CleanupTestEntity(42, 'node')));
    }

    #[Test]
    public function skipsDeleteWhenEntityIdIsMissing(): void
    {
        $storage = $this->createMock(EmbeddingStorageInterface::class);
        $storage->expects($this->never())->method('delete');

        $listener = new EntityEmbeddingCleanupListener($storage, executionGuard: new \Waaseyaa\AI\Vector\Testing\InMemoryEmbeddingExecutionGuard());
        $listener->onPostDelete(new EntityEvent(new CleanupTestEntity(null, 'node')));
    }

    #[Test]
    public function a_failed_removal_after_the_committed_delete_is_logged_not_thrown(): void
    {
        $storage = $this->createStub(EmbeddingStorageInterface::class);
        $storage->method('delete')->willThrowException(new \RuntimeException('storage offline'));
        $errors = [];
        $logger = new class ($errors) implements \Waaseyaa\Foundation\Log\LoggerInterface {
            use \Waaseyaa\Foundation\Log\LoggerTrait;

            /** @param list<string> $errors */
            public function __construct(private array &$errors) {}

            public function log(\Waaseyaa\Foundation\Log\LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                if ($level === \Waaseyaa\Foundation\Log\LogLevel::ERROR) {
                    $this->errors[] = (string) $message;
                }
            }
        };

        new EntityEmbeddingCleanupListener($storage, $logger, executionGuard: new \Waaseyaa\AI\Vector\Testing\InMemoryEmbeddingExecutionGuard(), entityTypeManager: $this->manager(null))->onPostDelete(new EntityEvent(new CleanupTestEntity(42, 'node')));

        self::assertCount(1, $errors);
        self::assertStringContainsString('storage offline', $errors[0]);
    }
}

final readonly class CleanupTestEntity implements EntityInterface
{
    public function __construct(
        private int|string|null $id,
        private string $entityTypeId,
    ) {}

    public function id(): int|string|null
    {
        return $this->id;
    }
    public function uuid(): string
    {
        return 'uuid';
    }
    public function label(): string
    {
        return 'Label';
    }
    public function getEntityTypeId(): string
    {
        return $this->entityTypeId;
    }
    public function bundle(): string
    {
        return 'default';
    }
    public function isNew(): bool
    {
        return false;
    }
    public function get(string $name): mixed
    {
        return null;
    }
    public function set(string $name, mixed $value): static
    {
        throw new \LogicException('Readonly');
    }
    public function toArray(): array
    {
        return [];
    }
    public function language(): string
    {
        return 'en';
    }
}
