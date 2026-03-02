<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(ResourceSerializer::class)]
final class ResourceSerializerFieldAccessTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private ResourceSerializer $serializer;

    protected function setUp(): void
    {
        $this->entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
        ));
        $this->serializer = new ResourceSerializer($this->entityTypeManager);
    }

    private function createAccount(): AccountInterface
    {
        return $this->createMock(AccountInterface::class);
    }

    /**
     * Creates a field policy that forbids viewing specific fields.
     */
    private function createViewDenyPolicy(string $entityTypeId, array $deniedFields): AccessPolicyInterface&FieldAccessPolicyInterface
    {
        return new class ($entityTypeId, $deniedFields) implements AccessPolicyInterface, FieldAccessPolicyInterface {
            public function __construct(
                private readonly string $entityTypeId,
                private readonly array $deniedFields,
            ) {}

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === $this->entityTypeId;
            }

            public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
            {
                if ($operation === 'view' && in_array($fieldName, $this->deniedFields, true)) {
                    return AccessResult::forbidden("No view access to {$fieldName}");
                }
                return AccessResult::neutral();
            }
        };
    }

    #[Test]
    public function serializeWithoutAccessHandlerReturnsAllFields(): void
    {
        $entity = new TestEntity([
            'id' => 1, 'uuid' => 'uuid-1', 'title' => 'Test',
            'type' => 'blog', 'body' => 'Content', 'secret' => 'classified',
        ]);

        $resource = $this->serializer->serialize($entity);

        $this->assertArrayHasKey('body', $resource->attributes);
        $this->assertArrayHasKey('secret', $resource->attributes);
    }

    #[Test]
    public function serializeOmitsViewDeniedFields(): void
    {
        $policy = $this->createViewDenyPolicy('article', ['secret']);
        $accessHandler = new EntityAccessHandler([$policy]);
        $account = $this->createAccount();

        $entity = new TestEntity([
            'id' => 1, 'uuid' => 'uuid-1', 'title' => 'Test',
            'type' => 'blog', 'body' => 'Content', 'secret' => 'classified',
        ]);

        $resource = $this->serializer->serialize($entity, $accessHandler, $account);

        $this->assertArrayHasKey('body', $resource->attributes);
        $this->assertArrayNotHasKey('secret', $resource->attributes);
        $this->assertArrayHasKey('title', $resource->attributes);
    }

    #[Test]
    public function serializeOmitsMultipleViewDeniedFields(): void
    {
        $policy = $this->createViewDenyPolicy('article', ['secret', 'internal_notes']);
        $accessHandler = new EntityAccessHandler([$policy]);
        $account = $this->createAccount();

        $entity = new TestEntity([
            'id' => 1, 'uuid' => 'uuid-1', 'title' => 'Test', 'type' => 'blog',
            'body' => 'Content', 'secret' => 'classified', 'internal_notes' => 'private',
        ]);

        $resource = $this->serializer->serialize($entity, $accessHandler, $account);

        $this->assertArrayHasKey('body', $resource->attributes);
        $this->assertArrayNotHasKey('secret', $resource->attributes);
        $this->assertArrayNotHasKey('internal_notes', $resource->attributes);
    }

    #[Test]
    public function serializeCollectionFiltersFieldsPerEntity(): void
    {
        $policy = $this->createViewDenyPolicy('article', ['secret']);
        $accessHandler = new EntityAccessHandler([$policy]);
        $account = $this->createAccount();

        $entities = [
            new TestEntity(['id' => 1, 'uuid' => 'uuid-1', 'title' => 'A', 'secret' => 's1']),
            new TestEntity(['id' => 2, 'uuid' => 'uuid-2', 'title' => 'B', 'secret' => 's2']),
        ];

        $resources = $this->serializer->serializeCollection($entities, $accessHandler, $account);

        $this->assertCount(2, $resources);
        $this->assertArrayNotHasKey('secret', $resources[0]->attributes);
        $this->assertArrayNotHasKey('secret', $resources[1]->attributes);
    }
}
