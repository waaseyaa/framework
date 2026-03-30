<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(JsonApiController::class)]
final class JsonApiControllerAccessControlTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private InMemoryEntityStorage $storage;
    private ResourceSerializer $serializer;

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
    }

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

            public function access(\Waaseyaa\Entity\EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
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

            public function access(\Waaseyaa\Entity\EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
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
