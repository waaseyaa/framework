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
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AdminSurface\Host\GenericAdminSurfaceHost;
use Waaseyaa\Api\Schema\SchemaPresenter;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityRepository;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;

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
    protected function tearDown(): void
    {
        EntityReadRuntime::installFieldRegistry(null);
    }

    private function bundledHost(AccessResult $createAccess): GenericAdminSurfaceHost
    {
        $registry = new FieldDefinitionRegistry();
        EntityReadRuntime::installFieldRegistry($registry);
        $storage = new InMemoryEntityStorage('article');
        $entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            fn() => $storage,
            fn() => new InMemoryEntityRepository($storage),
            fieldRegistry: $registry,
        );
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
        ));
        $registry->registerBundleFields('article', 'page', [
            new FieldDefinition(
                name: 'page_body',
                type: 'text',
                targetEntityTypeId: 'article',
                targetBundle: 'page',
                read: FieldReadLevel::Public,
            ),
        ]);

        $accessHandler = $this->createStub(EntityAccessHandler::class);
        $accessHandler->method('checkCreateAccess')->willReturn($createAccess);
        $accessHandler->method('checkFieldAccess')->willReturn(AccessResult::neutral('ok'));
        $accessHandler->method('filterFields')->willReturnCallback(
            static fn($entity, array $fields) => $fields,
        );

        $host = new GenericAdminSurfaceHost(
            $entityTypeManager,
            $accessHandler,
            new SchemaPresenter($registry),
        );
        $account = $this->createStub(AuthorizationPrincipalInterface::class);
        $account->method('id')->willReturn(1);
        $account->method('hasPermission')->willReturn(true);
        $account->method('getRoles')->willReturn(['administrator']);
        $request = Request::create('/');
        $request->attributes->set('_account', $account);
        $host->resolveSession($request);

        return $host;
    }

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

        $accessHandler = $this->createStub(EntityAccessHandler::class);
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

        $account = $this->createStub(AuthorizationPrincipalInterface::class);
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
            'mutation_token' => $created->data['mutation_token'] ?? null,
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

    #[Test]
    public function bundledCreateRejectsMissingEmptyAndUnknownBundleValues(): void
    {
        $host = $this->bundledHost(AccessResult::allowed('ok'));

        foreach ([null, '', 'unknown'] as $bundle) {
            $attributes = ['title' => 'New'];
            if ($bundle !== null) {
                $attributes['type'] = $bundle;
            }

            $result = $host->action('article', 'create', ['attributes' => $attributes]);

            self::assertFalse($result->ok);
            self::assertSame(422, $result->error['status']);
            self::assertStringContainsString('bundle', strtolower((string) $result->error['detail']));
        }
    }

    #[Test]
    public function bundledCreateForwardsTheSelectedBundleKeyAndBundleFields(): void
    {
        $result = $this->bundledHost(AccessResult::allowed('ok'))->action('article', 'create', [
            'attributes' => [
                'type' => 'page',
                'title' => 'New page',
                'page_body' => 'Page content.',
            ],
        ]);

        self::assertTrue($result->ok);
        self::assertSame('page', $result->data['attributes']['type']);
        self::assertSame('Page content.', $result->data['attributes']['page_body']);
    }

    #[Test]
    public function bundledCreateKeepsExistingBundleAwareAuthorization(): void
    {
        $result = $this->bundledHost(AccessResult::forbidden('no page create access'))->action('article', 'create', [
            'attributes' => ['type' => 'page', 'title' => 'Denied page'],
        ]);

        self::assertFalse($result->ok);
        self::assertSame(403, $result->error['status']);
    }
}
