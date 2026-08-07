<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Exception\PartialAccessContextException;
use Waaseyaa\Api\JsonApiResource;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;

/**
 * Negative-path coverage for the paired-nullable access-context invariant on
 * `ResourceSerializer::serialize()` and `ResourceSerializer::serializeCollection()`.
 *
 * Mission #824 WP05 surface B (closes #834). Both methods must throw
 * `PartialAccessContextException` when exactly one of `($accessHandler, $account)`
 * is `null`. Both-null and both-non-null remain valid.
 */
#[CoversClass(ResourceSerializer::class)]
final class ResourceSerializerPartialContextTest extends TestCase
{
    private ResourceSerializer $serializer;
    private TestEntity $entity;

    protected function setUp(): void
    {
        $entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
        ));

        $this->serializer = new ResourceSerializer($entityTypeManager);
        $this->entity = new TestEntity([
            'id' => 1,
            'uuid' => 'test-uuid',
            'title' => 'Test',
            'type' => 'blog',
        ]);
    }

    #[Test]
    public function serializeAcceptsBothNullContext(): void
    {
        $resource = $this->serializer->serialize($this->entity);

        self::assertInstanceOf(JsonApiResource::class, $resource);
    }

    #[Test]
    public function serializeAcceptsBothNonNullContext(): void
    {
        $resource = $this->serializer->serialize(
            $this->entity,
            new EntityAccessHandler([]),
            $this->createStub(AccountInterface::class),
        );

        self::assertInstanceOf(JsonApiResource::class, $resource);
    }

    #[Test]
    public function serializeRejectsHandlerWithoutAccount(): void
    {
        $this->expectException(PartialAccessContextException::class);
        $this->expectExceptionMessageMatches('/^\[PARTIAL_ACCESS_CONTEXT\]/');

        $this->serializer->serialize($this->entity, new EntityAccessHandler([]), null);
    }

    #[Test]
    public function serializeRejectsAccountWithoutHandler(): void
    {
        $this->expectException(PartialAccessContextException::class);
        $this->expectExceptionMessageMatches('/^\[PARTIAL_ACCESS_CONTEXT\]/');

        $this->serializer->serialize($this->entity, null, $this->createStub(AccountInterface::class));
    }

    #[Test]
    public function serializeCollectionRejectsHandlerWithoutAccount(): void
    {
        $this->expectException(PartialAccessContextException::class);

        $this->serializer->serializeCollection([$this->entity], new EntityAccessHandler([]), null);
    }

    #[Test]
    public function serializeCollectionRejectsAccountWithoutHandler(): void
    {
        $this->expectException(PartialAccessContextException::class);

        $this->serializer->serializeCollection([$this->entity], null, $this->createStub(AccountInterface::class));
    }
}
