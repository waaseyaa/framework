<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase6;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Schema\SchemaPresenter;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Integration test: full round-trip of field-level access control.
 *
 * Registers a field access policy, then verifies:
 * 1. JSON:API GET omits view-denied fields.
 * 2. Schema marks edit-denied fields as restricted.
 * 3. JSON:API PATCH rejects edit-denied field submission.
 * 4. JSON:API POST rejects edit-denied field submission.
 */
#[CoversNothing]
final class FieldAccessIntegrationTest extends TestCase
{
    private InMemoryEntityStorage $storage;
    private EntityTypeManager $entityTypeManager;
    private EntityAccessHandler $accessHandler;
    private AccountInterface $account;
    private JsonApiController $controller;
    private SchemaPresenter $schemaPresenter;
    private EntityType $entityType;

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

        // Policy: forbid viewing 'internal_notes', forbid editing 'status'.
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
                if ($fieldName === 'internal_notes' && $operation === 'view') {
                    return AccessResult::forbidden('Internal notes are hidden');
                }
                if ($fieldName === 'status' && $operation === 'edit') {
                    return AccessResult::forbidden('Only admins can change status');
                }
                return AccessResult::neutral();
            }
        };

        $this->accessHandler = new EntityAccessHandler([$policy]);
        $serializer = new ResourceSerializer($this->entityTypeManager);
        $this->schemaPresenter = new SchemaPresenter();

        $this->controller = new JsonApiController(
            $this->entityTypeManager,
            $serializer,
            $this->accessHandler,
            $this->account,
        );
    }

    private function createAndSaveEntity(array $values): TestEntity
    {
        $entity = $this->storage->create($values);
        $this->storage->save($entity);
        return $entity;
    }

    // ---------------------------------------------------------------
    // Round-trip: JSON:API GET omits view-denied fields
    // ---------------------------------------------------------------

    #[Test]
    public function jsonApiGetOmitsViewDeniedFields(): void
    {
        $entity = $this->createAndSaveEntity([
            'title' => 'My Article',
            'body' => 'Public content',
            'internal_notes' => 'For editors only',
            'status' => 'draft',
        ]);

        $doc = $this->controller->show('article', $entity->id());
        $array = $doc->toArray();

        // View-denied field is omitted.
        $this->assertArrayNotHasKey('internal_notes', $array['data']['attributes']);
        // Other fields present.
        $this->assertSame('Public content', $array['data']['attributes']['body']);
        $this->assertSame('draft', $array['data']['attributes']['status']);
    }

    // ---------------------------------------------------------------
    // Round-trip: Schema marks edit-denied fields as restricted
    // ---------------------------------------------------------------

    #[Test]
    public function schemaMarksEditDeniedAsRestricted(): void
    {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn('article');

        $fieldDefs = [
            'body' => ['type' => 'text', 'label' => 'Body'],
            'internal_notes' => ['type' => 'text', 'label' => 'Internal Notes'],
            'status' => ['type' => 'string', 'label' => 'Status'],
        ];

        $schema = $this->schemaPresenter->present(
            $this->entityType,
            $fieldDefs,
            $entity,
            $this->accessHandler,
            $this->account,
        );

        // View-denied field removed.
        $this->assertArrayNotHasKey('internal_notes', $schema['properties']);

        // Edit-denied field marked as restricted.
        $this->assertTrue($schema['properties']['status']['readOnly']);
        $this->assertTrue($schema['properties']['status']['x-access-restricted']);

        // Fully accessible field unchanged.
        $this->assertArrayNotHasKey('readOnly', $schema['properties']['body']);
    }

    // ---------------------------------------------------------------
    // Round-trip: PATCH rejects edit-denied field
    // ---------------------------------------------------------------

    #[Test]
    public function patchRejectsEditDeniedField(): void
    {
        $entity = $this->createAndSaveEntity([
            'title' => 'My Article', 'status' => 'draft',
        ]);

        $doc = $this->controller->update('article', $entity->id(), [
            'data' => [
                'type' => 'article',
                'id' => $entity->uuid(),
                'attributes' => ['status' => 'published'],
            ],
        ]);

        $this->assertSame(403, $doc->statusCode);
        $this->assertStringContainsString('status', $doc->toArray()['errors'][0]['detail']);
    }

    // ---------------------------------------------------------------
    // Round-trip: POST rejects edit-denied field
    // ---------------------------------------------------------------

    #[Test]
    public function postRejectsEditDeniedField(): void
    {
        $doc = $this->controller->store('article', [
            'data' => [
                'type' => 'article',
                'attributes' => ['title' => 'New', 'status' => 'published'],
            ],
        ]);

        $this->assertSame(403, $doc->statusCode);
        $this->assertStringContainsString('status', $doc->toArray()['errors'][0]['detail']);
    }

    // ---------------------------------------------------------------
    // Backward compat: no policies = no change
    // ---------------------------------------------------------------

    #[Test]
    public function noFieldPoliciesAllowsAllFieldsByDefault(): void
    {
        // Entity-level allow policy, but no FieldAccessPolicyInterface.
        $entityOnlyPolicy = new class () implements AccessPolicyInterface {
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

        $handlerNoFieldPolicies = new EntityAccessHandler([$entityOnlyPolicy]);
        $serializer = new ResourceSerializer($this->entityTypeManager);
        $controller = new JsonApiController(
            $this->entityTypeManager,
            $serializer,
            $handlerNoFieldPolicies,
            $this->account,
        );

        $entity = $this->createAndSaveEntity([
            'title' => 'Article',
            'body' => 'Content',
            'internal_notes' => 'Private',
            'status' => 'draft',
        ]);

        // GET: all fields present.
        $doc = $controller->show('article', $entity->id());
        $attrs = $doc->toArray()['data']['attributes'];
        $this->assertArrayHasKey('body', $attrs);
        $this->assertArrayHasKey('internal_notes', $attrs);
        $this->assertArrayHasKey('status', $attrs);

        // PATCH: all fields editable.
        $doc = $controller->update('article', $entity->id(), [
            'data' => [
                'type' => 'article',
                'id' => $entity->uuid(),
                'attributes' => ['status' => 'published'],
            ],
        ]);
        $this->assertSame(200, $doc->statusCode);
    }
}
