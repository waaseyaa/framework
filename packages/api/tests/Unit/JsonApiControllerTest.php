<?php

declare(strict_types=1);

namespace Aurora\Api\Tests\Unit;

use Aurora\Access\AccessResult;
use Aurora\Access\AccountInterface;
use Aurora\Access\AccessPolicyInterface;
use Aurora\Access\EntityAccessHandler;
use Aurora\Api\JsonApiController;
use Aurora\Api\ResourceSerializer;
use Aurora\Api\Tests\Fixtures\InMemoryEntityStorage;
use Aurora\Api\Tests\Fixtures\TestEntity;
use Aurora\Entity\EntityType;
use Aurora\Entity\EntityTypeManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(JsonApiController::class)]
final class JsonApiControllerTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private InMemoryEntityStorage $storage;
    private ResourceSerializer $serializer;
    private JsonApiController $controller;

    protected function setUp(): void
    {
        $this->storage = new InMemoryEntityStorage('article');

        $this->entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            fn() => $this->storage,
        );

        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'label' => 'title',
                'bundle' => 'type',
            ],
        ));

        $this->serializer = new ResourceSerializer($this->entityTypeManager);
        $this->controller = new JsonApiController(
            $this->entityTypeManager,
            $this->serializer,
        );
    }

    // --- Index (GET collection) ---

    #[Test]
    public function indexReturnsEmptyCollection(): void
    {
        $doc = $this->controller->index('article');
        $array = $doc->toArray();

        $this->assertSame([], $array['data']);
        $this->assertStringStartsWith('/api/article', $array['links']['self']);
    }

    #[Test]
    public function indexReturnsAllEntities(): void
    {
        $this->createAndSaveEntity(['title' => 'First Article']);
        $this->createAndSaveEntity(['title' => 'Second Article']);

        $doc = $this->controller->index('article');
        $array = $doc->toArray();

        $this->assertCount(2, $array['data']);
        $this->assertSame('article', $array['data'][0]['type']);
        $this->assertSame('article', $array['data'][1]['type']);
    }

    #[Test]
    public function indexReturnsErrorForUnknownEntityType(): void
    {
        $doc = $this->controller->index('nonexistent');
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('404', $array['errors'][0]['status']);
        $this->assertStringContainsString('nonexistent', $array['errors'][0]['detail']);
    }

    // --- Show (GET single) ---

    #[Test]
    public function showReturnsSingleEntity(): void
    {
        $entity = $this->createAndSaveEntity(['title' => 'My Article', 'body' => 'Content.']);

        $doc = $this->controller->show('article', $entity->id());
        $array = $doc->toArray();

        $this->assertSame('article', $array['data']['type']);
        $this->assertSame($entity->uuid(), $array['data']['id']);
        $this->assertSame('My Article', $array['data']['attributes']['title']);
        $this->assertSame('Content.', $array['data']['attributes']['body']);
    }

    #[Test]
    public function showReturnsErrorForMissingEntity(): void
    {
        $doc = $this->controller->show('article', 9999);
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('404', $array['errors'][0]['status']);
        $this->assertStringContainsString('9999', $array['errors'][0]['detail']);
    }

    #[Test]
    public function showReturnsErrorForUnknownEntityType(): void
    {
        $doc = $this->controller->show('nonexistent', 1);
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('404', $array['errors'][0]['status']);
    }

    // --- Store (POST create) ---

    #[Test]
    public function storeCreatesNewEntity(): void
    {
        $data = [
            'data' => [
                'type' => 'article',
                'attributes' => [
                    'title' => 'New Article',
                    'body' => 'New content.',
                ],
            ],
        ];

        $doc = $this->controller->store('article', $data);
        $array = $doc->toArray();

        $this->assertSame('article', $array['data']['type']);
        $this->assertSame('New Article', $array['data']['attributes']['title']);
        $this->assertSame('New content.', $array['data']['attributes']['body']);
        $this->assertTrue($array['meta']['created']);
    }

    #[Test]
    public function storeReturnsErrorForMissingData(): void
    {
        $doc = $this->controller->store('article', []);
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('400', $array['errors'][0]['status']);
    }

    #[Test]
    public function storeReturnsErrorForMissingType(): void
    {
        $doc = $this->controller->store('article', ['data' => ['attributes' => []]]);
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('400', $array['errors'][0]['status']);
    }

    #[Test]
    public function storeReturnsErrorForTypeMismatch(): void
    {
        $data = [
            'data' => [
                'type' => 'wrong_type',
                'attributes' => ['title' => 'Test'],
            ],
        ];

        $doc = $this->controller->store('article', $data);
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('422', $array['errors'][0]['status']);
        $this->assertStringContainsString('wrong_type', $array['errors'][0]['detail']);
    }

    #[Test]
    public function storeReturnsErrorForUnknownEntityType(): void
    {
        $doc = $this->controller->store('nonexistent', ['data' => ['type' => 'nonexistent']]);
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('404', $array['errors'][0]['status']);
    }

    // --- Update (PATCH) ---

    #[Test]
    public function updateModifiesEntity(): void
    {
        $entity = $this->createAndSaveEntity(['title' => 'Original Title', 'body' => 'Original.']);

        $data = [
            'data' => [
                'type' => 'article',
                'id' => $entity->uuid(),
                'attributes' => [
                    'title' => 'Updated Title',
                ],
            ],
        ];

        $doc = $this->controller->update('article', $entity->id(), $data);
        $array = $doc->toArray();

        $this->assertSame('article', $array['data']['type']);
        $this->assertSame('Updated Title', $array['data']['attributes']['title']);
        // Body should remain unchanged.
        $this->assertSame('Original.', $array['data']['attributes']['body']);
    }

    #[Test]
    public function updateReturnsErrorForMissingEntity(): void
    {
        $data = [
            'data' => [
                'type' => 'article',
                'attributes' => ['title' => 'Test'],
            ],
        ];

        $doc = $this->controller->update('article', 9999, $data);
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('404', $array['errors'][0]['status']);
    }

    #[Test]
    public function updateReturnsErrorForMissingData(): void
    {
        $entity = $this->createAndSaveEntity(['title' => 'Test']);

        $doc = $this->controller->update('article', $entity->id(), []);
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('400', $array['errors'][0]['status']);
    }

    #[Test]
    public function updateReturnsErrorForTypeMismatch(): void
    {
        $entity = $this->createAndSaveEntity(['title' => 'Test']);

        $data = [
            'data' => [
                'type' => 'wrong_type',
                'attributes' => ['title' => 'Updated'],
            ],
        ];

        $doc = $this->controller->update('article', $entity->id(), $data);
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('422', $array['errors'][0]['status']);
    }

    #[Test]
    public function updateReturnsErrorForUnknownEntityType(): void
    {
        $data = ['data' => ['type' => 'nonexistent', 'attributes' => []]];

        $doc = $this->controller->update('nonexistent', 1, $data);
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('404', $array['errors'][0]['status']);
    }

    // --- Destroy (DELETE) ---

    #[Test]
    public function destroyRemovesEntity(): void
    {
        $entity = $this->createAndSaveEntity(['title' => 'Delete Me']);
        $id = $entity->id();

        $doc = $this->controller->destroy('article', $id);
        $array = $doc->toArray();

        $this->assertNull($array['data']);
        $this->assertTrue($array['meta']['deleted']);

        // Entity should be gone.
        $this->assertNull($this->storage->load($id));
    }

    #[Test]
    public function destroyReturnsErrorForMissingEntity(): void
    {
        $doc = $this->controller->destroy('article', 9999);
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('404', $array['errors'][0]['status']);
    }

    #[Test]
    public function destroyReturnsErrorForUnknownEntityType(): void
    {
        $doc = $this->controller->destroy('nonexistent', 1);
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('404', $array['errors'][0]['status']);
    }

    // --- Access Control ---

    #[Test]
    public function showReturnsForbiddenWhenAccessDenied(): void
    {
        $entity = $this->createAndSaveEntity(['title' => 'Secret']);

        $controller = $this->createControllerWithAccessDenied();

        $doc = $controller->show('article', $entity->id());
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('403', $array['errors'][0]['status']);
    }

    #[Test]
    public function indexFiltersOutInaccessibleEntities(): void
    {
        $this->createAndSaveEntity(['title' => 'Accessible']);
        $this->createAndSaveEntity(['title' => 'Secret']);

        $controller = $this->createControllerWithAccessDenied();

        $doc = $controller->index('article');
        $array = $doc->toArray();

        // All entities are filtered out because access is denied.
        $this->assertSame([], $array['data']);
    }

    #[Test]
    public function storeReturnsForbiddenWhenCreateAccessDenied(): void
    {
        $controller = $this->createControllerWithAccessDenied();

        $data = [
            'data' => [
                'type' => 'article',
                'attributes' => ['title' => 'New'],
            ],
        ];

        $doc = $controller->store('article', $data);
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('403', $array['errors'][0]['status']);
    }

    #[Test]
    public function updateReturnsForbiddenWhenAccessDenied(): void
    {
        $entity = $this->createAndSaveEntity(['title' => 'Test']);
        $controller = $this->createControllerWithAccessDenied();

        $data = [
            'data' => [
                'type' => 'article',
                'attributes' => ['title' => 'Updated'],
            ],
        ];

        $doc = $controller->update('article', $entity->id(), $data);
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('403', $array['errors'][0]['status']);
    }

    #[Test]
    public function destroyReturnsForbiddenWhenAccessDenied(): void
    {
        $entity = $this->createAndSaveEntity(['title' => 'Test']);
        $controller = $this->createControllerWithAccessDenied();

        $doc = $controller->destroy('article', $entity->id());
        $array = $doc->toArray();

        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('403', $array['errors'][0]['status']);
    }

    #[Test]
    public function operationsSucceedWhenAccessAllowed(): void
    {
        $controller = $this->createControllerWithAccessAllowed();

        // Store.
        $data = [
            'data' => [
                'type' => 'article',
                'attributes' => ['title' => 'Allowed Article'],
            ],
        ];

        $doc = $controller->store('article', $data);
        $array = $doc->toArray();
        $this->assertArrayNotHasKey('errors', $array);
        $this->assertSame('Allowed Article', $array['data']['attributes']['title']);
    }

    // --- JSON:API spec compliance ---

    #[Test]
    public function allResponsesIncludeJsonApiVersion(): void
    {
        // Index.
        $doc = $this->controller->index('article');
        $this->assertSame('1.1', $doc->toArray()['jsonapi']['version']);

        // Show error.
        $doc = $this->controller->show('article', 9999);
        $this->assertSame('1.1', $doc->toArray()['jsonapi']['version']);

        // Store.
        $doc = $this->controller->store('article', [
            'data' => ['type' => 'article', 'attributes' => ['title' => 'Test']],
        ]);
        $this->assertSame('1.1', $doc->toArray()['jsonapi']['version']);
    }

    // --- Helpers ---

    private function createAndSaveEntity(array $values): TestEntity
    {
        /** @var TestEntity $entity */
        $entity = $this->storage->create($values);
        $this->storage->save($entity);

        return $entity;
    }

    private function createControllerWithAccessDenied(): JsonApiController
    {
        $policy = new class implements AccessPolicyInterface {
            public function appliesTo(string $entityTypeId): bool
            {
                return true;
            }

            public function access(\Aurora\Entity\EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::forbidden('Access denied for testing.');
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::forbidden('Create access denied for testing.');
            }
        };

        $accessHandler = new EntityAccessHandler([$policy]);
        $account = $this->createMockAccount();

        return new JsonApiController(
            $this->entityTypeManager,
            $this->serializer,
            $accessHandler,
            $account,
        );
    }

    private function createControllerWithAccessAllowed(): JsonApiController
    {
        $policy = new class implements AccessPolicyInterface {
            public function appliesTo(string $entityTypeId): bool
            {
                return true;
            }

            public function access(\Aurora\Entity\EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }
        };

        $accessHandler = new EntityAccessHandler([$policy]);
        $account = $this->createMockAccount();

        return new JsonApiController(
            $this->entityTypeManager,
            $this->serializer,
            $accessHandler,
            $account,
        );
    }

    private function createMockAccount(): AccountInterface
    {
        return new class implements AccountInterface {
            public function id(): int|string
            {
                return 1;
            }

            public function hasPermission(string $permission): bool
            {
                return true;
            }

            public function getRoles(): array
            {
                return ['authenticated'];
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }
}
