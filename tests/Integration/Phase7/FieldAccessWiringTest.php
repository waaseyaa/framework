<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase7;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Api\Controller\SchemaController;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Schema\SchemaPresenter;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;

/**
 * Integration test: field-access wiring through controllers.
 *
 * Verifies that when EntityAccessHandler and AccountInterface are injected
 * into controllers (as public/index.php now does), field-level access
 * checks are active in both JSON:API responses and schema generation.
 */
#[CoversNothing]
final class FieldAccessWiringTest extends TestCase
{
    private InMemoryEntityStorage $storage;
    private EntityTypeManager $entityTypeManager;
    private EntityType $entityType;
    private AccountInterface $account;
    private EntityAccessHandler $accessHandler;

    protected function setUp(): void
    {
        $this->storage = new InMemoryEntityStorage('article');
        $this->entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            fn() => $this->storage,
        );

        $this->entityType = new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
        );
        $this->entityTypeManager->registerEntityType($this->entityType);

        $this->account = $this->createMock(AccountInterface::class);

        // Policy: forbid viewing 'secret', forbid editing 'status'.
        $policy = new class implements AccessPolicyInterface, FieldAccessPolicyInterface {
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
                    return AccessResult::forbidden('hidden');
                }
                if ($fieldName === 'status' && $operation === 'edit') {
                    return AccessResult::forbidden('restricted');
                }
                return AccessResult::neutral();
            }
        };

        $this->accessHandler = new EntityAccessHandler([$policy]);
    }

    #[Test]
    public function schemaControllerAppliesFieldAccess(): void
    {
        $controller = new SchemaController(
            $this->entityTypeManager,
            new SchemaPresenter(),
            $this->accessHandler,
            $this->account,
        );

        $doc = $controller->show('article');
        $schema = $doc->toArray()['meta']['schema'];

        // System properties (id, uuid, title) are present — no policy restricts them.
        $this->assertArrayHasKey('id', $schema['properties']);
        $this->assertArrayHasKey('title', $schema['properties']);
    }

    #[Test]
    public function jsonApiControllerAndSchemaControllerShareHandler(): void
    {
        $serializer = new ResourceSerializer($this->entityTypeManager);

        $jsonApi = new JsonApiController(
            $this->entityTypeManager,
            $serializer,
            $this->accessHandler,
            $this->account,
        );

        $schema = new SchemaController(
            $this->entityTypeManager,
            new SchemaPresenter(),
            $this->accessHandler,
            $this->account,
        );

        // Create entity with all fields.
        $entity = $this->storage->create([
            'title' => 'Test',
            'secret' => 'classified',
            'status' => 'draft',
            'body' => 'content',
        ]);
        $this->storage->save($entity);

        // JSON:API GET omits secret.
        $apiDoc = $jsonApi->show('article', $entity->id());
        $attrs = $apiDoc->toArray()['data']['attributes'];
        $this->assertArrayNotHasKey('secret', $attrs);
        $this->assertArrayHasKey('body', $attrs);

        // JSON:API PATCH rejects status edit.
        $patchDoc = $jsonApi->update('article', $entity->id(), [
            'data' => [
                'type' => 'article',
                'id' => $entity->uuid(),
                'attributes' => ['status' => 'published'],
            ],
        ]);
        $this->assertSame(403, $patchDoc->statusCode);

        // Schema endpoint works with same handler.
        $schemaDoc = $schema->show('article');
        $this->assertSame(200, $schemaDoc->statusCode);
    }

    #[Test]
    public function entityPolicyWithoutFieldPolicyAllowsAllFields(): void
    {
        // Entity-level policy that allows access, but implements no FieldAccessPolicyInterface.
        // This mirrors the real scenario: entity access granted, no field restrictions.
        $entityPolicy = new class implements AccessPolicyInterface {
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
                return true;
            }
        };

        $handler = new EntityAccessHandler([$entityPolicy]);
        $serializer = new ResourceSerializer($this->entityTypeManager);

        $jsonApi = new JsonApiController(
            $this->entityTypeManager,
            $serializer,
            $handler,
            $this->account,
        );

        $entity = $this->storage->create([
            'title' => 'Open',
            'secret' => 'visible',
            'status' => 'draft',
        ]);
        $this->storage->save($entity);

        // All fields present — entity allowed, no field policy = neutral = non-forbidden.
        $doc = $jsonApi->show('article', $entity->id());
        $attrs = $doc->toArray()['data']['attributes'];
        $this->assertArrayHasKey('secret', $attrs);
        $this->assertArrayHasKey('status', $attrs);

        // PATCH succeeds — entity allowed, no field restrictions.
        $patchDoc = $jsonApi->update('article', $entity->id(), [
            'data' => [
                'type' => 'article',
                'id' => $entity->uuid(),
                'attributes' => ['status' => 'published'],
            ],
        ]);
        $this->assertSame(200, $patchDoc->statusCode);
    }
}
