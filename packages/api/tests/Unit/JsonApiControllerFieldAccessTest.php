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
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(JsonApiController::class)]
final class JsonApiControllerFieldAccessTest extends TestCase
{
    private InMemoryEntityStorage $storage;
    private EntityTypeManager $entityTypeManager;
    private EntityAccessHandler $accessHandler;
    private AccountInterface $account;
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
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
        ));

        $this->account = $this->createMock(AccountInterface::class);

        // Policy: forbid viewing 'secret', forbid editing 'status'.
        $policy = new class () implements AccessPolicyInterface, FieldAccessPolicyInterface {
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'article';
            }

            public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
            {
                if ($fieldName === 'secret' && $operation === 'view') {
                    return AccessResult::forbidden('No view access to secret');
                }
                if ($fieldName === 'status' && $operation === 'edit') {
                    return AccessResult::forbidden('No edit access to status');
                }
                return AccessResult::neutral();
            }
        };

        $this->accessHandler = new EntityAccessHandler([$policy]);
        $serializer = new ResourceSerializer($this->entityTypeManager);

        $this->controller = new JsonApiController(
            $this->entityTypeManager,
            $serializer,
            $this->accessHandler,
            $this->account,
        );
    }

    private function createAndSaveEntity(array $values = []): TestEntity
    {
        $entity = $this->storage->create($values);
        $this->storage->save($entity);
        return $entity;
    }

    // --- GET: view-denied fields omitted ---

    #[Test]
    public function showOmitsViewDeniedFields(): void
    {
        $entity = $this->createAndSaveEntity([
            'title' => 'Test', 'body' => 'Content', 'secret' => 'classified',
        ]);

        $doc = $this->controller->show('article', $entity->id());
        $array = $doc->toArray();

        $this->assertSame(200, $doc->statusCode);
        $this->assertArrayHasKey('body', $array['data']['attributes']);
        $this->assertArrayNotHasKey('secret', $array['data']['attributes']);
    }

    #[Test]
    public function indexOmitsViewDeniedFields(): void
    {
        $this->createAndSaveEntity(['title' => 'A', 'secret' => 's1']);
        $this->createAndSaveEntity(['title' => 'B', 'secret' => 's2']);

        $doc = $this->controller->index('article');
        $array = $doc->toArray();

        foreach ($array['data'] as $resource) {
            $this->assertArrayNotHasKey('secret', $resource['attributes']);
        }
    }

    // --- PATCH: edit-denied fields rejected ---

    #[Test]
    public function updateRejectsEditDeniedField(): void
    {
        $entity = $this->createAndSaveEntity([
            'title' => 'Test', 'status' => 'draft',
        ]);

        $doc = $this->controller->update('article', $entity->id(), [
            'data' => [
                'type' => 'article',
                'id' => $entity->uuid(),
                'attributes' => ['status' => 'published'],
            ],
        ]);

        $this->assertSame(403, $doc->statusCode);
        $array = $doc->toArray();
        $this->assertStringContainsString('status', $array['errors'][0]['detail']);
    }

    #[Test]
    public function updateAllowsNonRestrictedFields(): void
    {
        $entity = $this->createAndSaveEntity([
            'title' => 'Test', 'body' => 'Original',
        ]);

        $doc = $this->controller->update('article', $entity->id(), [
            'data' => [
                'type' => 'article',
                'id' => $entity->uuid(),
                'attributes' => ['body' => 'Updated'],
            ],
        ]);

        $this->assertSame(200, $doc->statusCode);
    }

    // --- POST: edit-denied fields rejected ---

    #[Test]
    public function storeRejectsEditDeniedField(): void
    {
        $doc = $this->controller->store('article', [
            'data' => [
                'type' => 'article',
                'attributes' => ['title' => 'New', 'status' => 'published'],
            ],
        ]);

        $this->assertSame(403, $doc->statusCode);
        $array = $doc->toArray();
        $this->assertStringContainsString('status', $array['errors'][0]['detail']);
    }

    #[Test]
    public function storeAllowsNonRestrictedFields(): void
    {
        $doc = $this->controller->store('article', [
            'data' => [
                'type' => 'article',
                'attributes' => ['title' => 'New Article', 'body' => 'Content'],
            ],
        ]);

        $this->assertSame(201, $doc->statusCode);
    }
}
