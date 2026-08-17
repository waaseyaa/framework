<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit\Host;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AdminSurface\Host\GenericAdminSurfaceHost;
use Waaseyaa\Api\InternalFieldVisibilityPolicy;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityRepository;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;

#[CoversClass(GenericAdminSurfaceHost::class)]
#[CoversClass(ResourceSerializer::class)]
#[CoversClass(JsonApiController::class)]
final class InternalFieldVisibilityParityTest extends TestCase
{
    protected function tearDown(): void
    {
        EntityReadRuntime::installFieldRegistry(null);
    }

    #[Test]
    public function applicationDeclaredInternalFieldIsHiddenAcrossEveryReadAndQuerySurface(): void
    {
        $storage = new InMemoryEntityStorage('node');
        $visibility = new InternalFieldVisibilityPolicy(['node' => ['legacy_origin']]);
        $registry = new FieldDefinitionRegistry();
        $registry->registerCoreFields('node', [
            new FieldDefinition(name: 'title', type: 'string', targetEntityTypeId: 'node', read: FieldReadLevel::Public),
            new FieldDefinition(name: 'legacy_origin', type: 'string', targetEntityTypeId: 'node', read: FieldReadLevel::Public),
        ]);
        EntityReadRuntime::installFieldRegistry($registry);
        $manager = new EntityTypeManager(
            new EventDispatcher(),
            fn() => $storage,
            fn() => new InMemoryEntityRepository($storage),
            fieldRegistry: $registry,
        );
        $manager->registerEntityType(new EntityType(
            id: 'node',
            label: 'Content',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
            _fieldDefinitions: [
                'title' => new FieldDefinition(name: 'title', type: 'string', targetEntityTypeId: 'node'),
                // Mirrors the migrated SFN application field: it is declared,
                // but its visibility is application metadata rather than a
                // credential-name heuristic.
                'legacy_origin' => new FieldDefinition(
                    name: 'legacy_origin',
                    type: 'string',
                    targetEntityTypeId: 'node',
                    read: FieldReadLevel::Public,
                ),
            ],
        ));

        $entity = new TestEntity(
            values: [
                'uuid' => 'node-uuid-1',
                'title' => 'Public title',
                'legacy_origin' => 'wordpress',
            ],
            entityTypeId: 'node',
            fieldDefinitions: [
                'title' => new FieldDefinition(name: 'title', type: 'string', read: FieldReadLevel::Public),
                'legacy_origin' => new FieldDefinition(name: 'legacy_origin', type: 'string', read: FieldReadLevel::Public),
            ],
        );
        $storage->save($entity);

        $accessHandler = $this->createStub(EntityAccessHandler::class);
        $accessHandler->method('check')->willReturn(AccessResult::allowed('test'));
        $accessHandler->method('filterFields')->willReturnCallback(
            static fn($entity, array $fields): array => $fields,
        );
        $accessHandler->method('checkFieldAccess')->willReturn(AccessResult::neutral('test'));

        $host = new GenericAdminSurfaceHost($manager, $accessHandler, internalFieldVisibility: $visibility);
        $account = $this->createStub(AuthorizationPrincipalInterface::class);
        $account->method('id')->willReturn(1);
        $account->method('hasPermission')->willReturn(true);
        $account->method('getRoles')->willReturn(['administrator']);
        $request = Request::create('/');
        $request->attributes->set('_account', $account);
        $host->resolveSession($request);

        $schema = $host->action('node', 'schema');
        self::assertTrue($schema->ok);
        self::assertArrayNotHasKey('legacy_origin', $schema->data['properties'], 'form schema');

        $detail = $host->get('node', '1');
        self::assertTrue($detail->ok);
        self::assertArrayNotHasKey('legacy_origin', $detail->data['attributes'], 'Admin detail projection');

        $serializer = new ResourceSerializer($manager, internalFieldVisibility: $visibility);
        $resource = $serializer->serialize($entity);
        self::assertArrayNotHasKey('legacy_origin', $resource->attributes, 'direct JSON:API serialization');

        $controller = new JsonApiController($manager, $serializer, internalFieldVisibility: $visibility);
        $filter = $controller->index('node', ['filter' => ['legacy_origin' => 'wordpress']])->toArray();
        self::assertSame('400', $filter['errors'][0]['status'] ?? null, 'filter validation');

        $sort = $controller->index('node', ['sort' => 'legacy_origin'])->toArray();
        self::assertSame('400', $sort['errors'][0]['status'] ?? null, 'sort validation');
    }
}
