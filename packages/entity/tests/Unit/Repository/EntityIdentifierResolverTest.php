<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityIdentifierResolver;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Tests\Helper\TestEntityType;
use Waaseyaa\Entity\Tests\Unit\TestEntity;

#[CoversClass(EntityIdentifierResolver::class)]
final class EntityIdentifierResolverTest extends TestCase
{
    private const UUID = '3f2a1b4c-5d6e-4f80-9a1b-2c3d4e5f6a7b';

    #[Test]
    public function it_finds_a_non_uuid_identifier_by_primary_key_without_querying(): void
    {
        $entity = new TestEntity(['id' => '42']);
        $query = new RecordingEntityQuery();
        $resolver = new EntityIdentifierResolver(
            $this->entityTypeManager($this->repository($query, ['42' => $entity])),
        );

        self::assertSame($entity, $resolver->resolve('test_entity', '42'));
        self::assertSame(0, $query->executions);
    }

    #[Test]
    public function it_resolves_a_uuid_identifier_through_the_declared_uuid_key(): void
    {
        $entity = new TestEntity(['id' => '42']);
        $query = new RecordingEntityQuery(['42']);
        $resolver = new EntityIdentifierResolver(
            $this->entityTypeManager(
                $this->repository($query, ['42' => $entity]),
                keys: ['id' => 'id', 'uuid' => 'entity_uuid'],
            ),
        );

        self::assertSame($entity, $resolver->resolve('test_entity', self::UUID));
        self::assertSame([['entity_uuid', self::UUID, '=']], $query->conditions);
        self::assertSame([[0, 1]], $query->ranges);
    }

    #[Test]
    public function the_uuid_lookup_is_access_neutral(): void
    {
        $query = new RecordingEntityQuery(['42']);
        $resolver = new EntityIdentifierResolver(
            $this->entityTypeManager($this->repository($query, ['42' => new TestEntity(['id' => '42'])])),
        );

        (void) $resolver->resolve('test_entity', self::UUID);

        self::assertSame([false], $query->accessCheck, 'Resolution must opt out of the query access filter.');
        self::assertSame([], $query->accountBindings, 'Resolution must never bind an acting account.');
    }

    #[Test]
    public function it_returns_null_when_no_entity_carries_the_uuid(): void
    {
        $query = new RecordingEntityQuery([]);
        $resolver = new EntityIdentifierResolver(
            $this->entityTypeManager($this->repository($query, [])),
        );

        self::assertNull($resolver->resolve('test_entity', self::UUID));
    }

    #[Test]
    public function it_falls_back_to_the_primary_key_when_the_type_declares_no_uuid_key(): void
    {
        $entity = new TestEntity(['id' => self::UUID]);
        $query = new RecordingEntityQuery(['42']);
        $resolver = new EntityIdentifierResolver(
            $this->entityTypeManager(
                $this->repository($query, [self::UUID => $entity]),
                keys: ['id' => 'id'],
            ),
        );

        self::assertSame($entity, $resolver->resolve('test_entity', self::UUID));
        self::assertSame(0, $query->executions);
    }

    #[Test]
    public function it_returns_null_for_an_empty_identifier_without_touching_storage(): void
    {
        $query = new RecordingEntityQuery(['42']);
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects(self::never())->method('find');
        $repository->method('getQuery')->willReturn($query);

        $resolver = new EntityIdentifierResolver($this->entityTypeManager($repository));

        self::assertNull($resolver->resolve('test_entity', ''));
        self::assertSame(0, $query->executions);
    }

    /** @param array<string, EntityInterface> $entitiesById */
    private function repository(RecordingEntityQuery $query, array $entitiesById): EntityRepositoryInterface
    {
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('getQuery')->willReturn($query);
        $repository->method('find')->willReturnCallback(
            static fn (string $id): ?EntityInterface => $entitiesById[$id] ?? null,
        );

        return $repository;
    }

    /** @param array<string, string> $keys */
    private function entityTypeManager(
        EntityRepositoryInterface $repository,
        array $keys = ['id' => 'id', 'uuid' => 'uuid'],
    ): EntityTypeManagerInterface {
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getDefinition')->willReturn(TestEntityType::stub('test_entity', keys: $keys));
        $manager->method('getRepository')->willReturn($repository);

        return $manager;
    }
}
