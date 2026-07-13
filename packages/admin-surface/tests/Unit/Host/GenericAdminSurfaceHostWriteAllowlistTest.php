<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit\Host;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AdminSurface\Host\GenericAdminSurfaceHost;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityRepository;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Field\FieldDefinition;

/**
 * CW-v1 option-1 design §5 (PR-4), findings #1/#2: `GenericAdminSurfaceHost`
 * create/update fully delegate to {@see \Waaseyaa\Api\JsonApiController}'s
 * `store()`/`update()` ({@see GenericAdminSurfaceHost::handleCreate()},
 * `handleUpdate()`), so the write-side field allowlist
 * ({@see \Waaseyaa\Entity\Write\EntityWritePayloadGuard}) applies here for
 * free — no separate admin-surface change was needed. This pins that parity
 * with a real `EntityTypeManager` (not a mocked one, since the guard calls
 * `resolveFieldDefinitions()`/`getDefinition()`).
 */
#[CoversClass(GenericAdminSurfaceHost::class)]
final class GenericAdminSurfaceHostWriteAllowlistTest extends TestCase
{
    private function host(): GenericAdminSurfaceHost
    {
        $storage = new InMemoryEntityStorage('article');
        $entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            fn() => $storage,
            fn() => new InMemoryEntityRepository($storage),
        );
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
            _fieldDefinitions: ['body' => new FieldDefinition(name: 'body', type: 'text')],
        ));

        $accessHandler = $this->createMock(EntityAccessHandler::class);
        $accessHandler->method('check')->willReturn(AccessResult::allowed('ok'));
        $accessHandler->method('checkCreateAccess')->willReturn(AccessResult::allowed('ok'));
        $accessHandler->method('checkFieldAccess')->willReturn(AccessResult::neutral('ok'));
        // ResourceSerializer::serialize() calls filterFields() to build the
        // attribute set; an unstubbed mock returns null, silently emptying
        // every response's attributes.
        $accessHandler->method('filterFields')->willReturnCallback(
            static fn($entity, array $fields) => $fields,
        );

        $host = new GenericAdminSurfaceHost($entityTypeManager, $accessHandler);

        $account = $this->createStub(AccountInterface::class);
        $account->method('id')->willReturn(1);
        $account->method('hasPermission')->willReturn(true);
        $account->method('getRoles')->willReturn(['administrator']);
        $request = Request::create('/');
        $request->attributes->set('_account', $account);
        $host->resolveSession($request);

        return $host;
    }

    #[Test]
    public function createRejectsAnUnwritableAttribute(): void
    {
        $result = $this->host()->action('article', 'create', [
            'attributes' => ['title' => 'New', 'published_revision_id' => 99],
        ]);

        $this->assertFalse($result->ok);
        $this->assertNotNull($result->error);
        $this->assertSame(422, $result->error['status']);
        $this->assertStringContainsString('published_revision_id', (string) $result->error['detail']);
    }

    #[Test]
    public function updateRejectsAnUnwritableAttribute(): void
    {
        $host = $this->host();
        $created = $host->action('article', 'create', ['attributes' => ['title' => 'New']]);
        $this->assertTrue($created->ok);
        $id = $created->data['id'];

        $result = $host->action('article', 'update', [
            'id' => $id,
            'attributes' => ['revision_id' => 99],
        ]);

        $this->assertFalse($result->ok);
        $this->assertNotNull($result->error);
        $this->assertSame(422, $result->error['status']);
        $this->assertStringContainsString('revision_id', (string) $result->error['detail']);
    }

    #[Test]
    public function createAcceptsDeclaredFields(): void
    {
        $result = $this->host()->action('article', 'create', [
            'attributes' => ['title' => 'New', 'body' => 'Content.'],
        ]);

        $this->assertTrue($result->ok);
        $this->assertSame('New', $result->data['attributes']['title']);
    }
}
