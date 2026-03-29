<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit\Host;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AdminSurface\Catalog\CatalogBuilder;
use Waaseyaa\AdminSurface\Host\AdminSurfaceSessionData;
use Waaseyaa\AdminSurface\Host\GenericAdminSurfaceHost;
use Waaseyaa\Entity\ConfigEntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\AdminSurface\Query\SurfaceFilterOperator;
use Waaseyaa\AdminSurface\Query\SurfaceQuery;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

#[CoversClass(GenericAdminSurfaceHost::class)]
final class GenericAdminSurfaceHostTest extends TestCase
{
    #[Test]
    public function resolve_session_returns_null_for_unauthenticated_request(): void
    {
        $host = new GenericAdminSurfaceHost($this->createMock(EntityTypeManager::class));
        $request = Request::create('/admin/surface/session');

        $this->assertNull($host->resolveSession($request));
    }

    #[Test]
    public function resolve_session_returns_null_for_non_admin(): void
    {
        $account = $this->createStub(AccountInterface::class);
        $account->method('id')->willReturn(1);
        $account->method('hasPermission')->willReturn(false);
        $account->method('getRoles')->willReturn(['authenticated']);

        $host = new GenericAdminSurfaceHost($this->createMock(EntityTypeManager::class));
        $request = Request::create('/admin/surface/session');
        $request->attributes->set('_account', $account);

        $this->assertNull($host->resolveSession($request));
    }

    #[Test]
    public function resolve_session_returns_data_for_admin(): void
    {
        $account = $this->createStub(AccountInterface::class);
        $account->method('id')->willReturn(42);
        $account->method('hasPermission')->willReturn(true);
        $account->method('getRoles')->willReturn(['administrator']);

        $host = new GenericAdminSurfaceHost(
            $this->createMock(EntityTypeManager::class),
            tenantId: 'myapp',
            tenantName: 'My App',
        );
        $request = Request::create('/admin/surface/session');
        $request->attributes->set('_account', $account);

        $session = $host->resolveSession($request);

        $this->assertNotNull($session);
        $this->assertSame('42', $session->accountId);
        $this->assertSame('myapp', $session->tenantId);
        $this->assertSame('My App', $session->tenantName);
        $this->assertContains('administrator', $session->roles);
    }

    #[Test]
    public function resolve_session_uses_custom_permission(): void
    {
        $account = $this->createStub(AccountInterface::class);
        $account->method('id')->willReturn(1);
        $account->method('hasPermission')->willReturnCallback(
            fn(string $perm) => $perm === 'manage site',
        );
        $account->method('getRoles')->willReturn(['editor']);

        $host = new GenericAdminSurfaceHost(
            $this->createMock(EntityTypeManager::class),
            adminPermission: 'manage site',
        );
        $request = Request::create('/admin/surface/session');
        $request->attributes->set('_account', $account);

        $this->assertNotNull($host->resolveSession($request));
    }

    #[Test]
    public function build_catalog_returns_entity_definitions(): void
    {
        $etm = $this->createMock(EntityTypeManager::class);
        $etm->method('getDefinitions')->willReturn([
            new EntityType(
                id: 'event',
                label: 'Event',
                class: \stdClass::class,
                keys: ['id' => 'eid'],
                group: 'events',
                fieldDefinitions: [
                    'title' => ['type' => 'string', 'label' => 'Title'],
                ],
            ),
        ]);

        $host = new GenericAdminSurfaceHost($etm);
        $session = new AdminSurfaceSessionData(
            accountId: '1',
            accountName: 'Admin',
            roles: ['administrator'],
            policies: [],
        );

        $catalog = $host->buildCatalog($session);

        $this->assertInstanceOf(CatalogBuilder::class, $catalog);
        $built = $catalog->build();
        $this->assertCount(1, $built);
        $this->assertSame('event', $built[0]['id']);
        $this->assertSame('Event', $built[0]['label']);
        $this->assertSame('events', $built[0]['group']);
    }

    #[Test]
    public function build_catalog_marks_config_entities_read_only(): void
    {
        // Use a class that extends ConfigEntityBase
        $configClass = get_class(new class(['type' => 'test']) extends ConfigEntityBase {
            public function __construct(array $values = [])
            {
                parent::__construct($values, 'test_config', ['id' => 'type']);
            }
        });

        $etm = $this->createMock(EntityTypeManager::class);
        $etm->method('getDefinitions')->willReturn([
            new EntityType(
                id: 'test_config',
                label: 'Test Config',
                class: $configClass,
                keys: ['id' => 'type'],
            ),
        ]);

        $host = new GenericAdminSurfaceHost($etm);
        $session = new AdminSurfaceSessionData(
            accountId: '1',
            accountName: 'Admin',
            roles: ['administrator'],
            policies: [],
        );

        $built = $host->buildCatalog($session)->build();

        $this->assertFalse($built[0]['capabilities']['create']);
        $this->assertFalse($built[0]['capabilities']['update']);
        $this->assertFalse($built[0]['capabilities']['delete']);
        $this->assertTrue($built[0]['capabilities']['list']);
        $this->assertTrue($built[0]['capabilities']['get']);
    }

    #[Test]
    public function build_catalog_marks_custom_read_only_types(): void
    {
        $etm = $this->createMock(EntityTypeManager::class);
        $etm->method('getDefinitions')->willReturn([
            new EntityType(
                id: 'audit_log',
                label: 'Audit Log',
                class: \stdClass::class,
                keys: ['id' => 'alid'],
            ),
        ]);

        $host = new GenericAdminSurfaceHost($etm, readOnlyTypes: ['audit_log']);
        $session = new AdminSurfaceSessionData(
            accountId: '1',
            accountName: 'Admin',
            roles: ['administrator'],
            policies: [],
        );

        $built = $host->buildCatalog($session)->build();

        $this->assertFalse($built[0]['capabilities']['create']);
        $this->assertFalse($built[0]['capabilities']['delete']);
    }

    #[Test]
    public function build_catalog_adds_delete_action_for_content_entities(): void
    {
        $etm = $this->createMock(EntityTypeManager::class);
        $etm->method('getDefinitions')->willReturn([
            new EntityType(
                id: 'event',
                label: 'Event',
                class: \stdClass::class,
                keys: ['id' => 'eid'],
            ),
        ]);

        $host = new GenericAdminSurfaceHost($etm);
        $session = new AdminSurfaceSessionData(
            accountId: '1',
            accountName: 'Admin',
            roles: ['administrator'],
            policies: [],
        );

        $built = $host->buildCatalog($session)->build();

        $this->assertTrue($built[0]['capabilities']['delete']);
        $this->assertCount(1, $built[0]['actions']);
        $this->assertSame('delete', $built[0]['actions'][0]['id']);
        $this->assertTrue($built[0]['actions'][0]['dangerous']);
    }

    #[Test]
    public function list_returns_error_for_unknown_type(): void
    {
        $etm = $this->createMock(EntityTypeManager::class);
        $etm->method('hasDefinition')->willReturn(false);

        $host = new GenericAdminSurfaceHost($etm);
        $result = $host->list('nonexistent');

        $this->assertFalse($result->ok);
    }

    #[Test]
    public function get_returns_error_for_unknown_type(): void
    {
        $etm = $this->createMock(EntityTypeManager::class);
        $etm->method('hasDefinition')->willReturn(false);

        $host = new GenericAdminSurfaceHost($etm);
        $result = $host->get('nonexistent', '123');

        $this->assertFalse($result->ok);
    }

    #[Test]
    public function action_returns_error_for_unknown_action(): void
    {
        $storage = $this->createMock(EntityStorageInterface::class);

        $etm = $this->createMock(EntityTypeManager::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getStorage')->willReturn($storage);

        $host = new GenericAdminSurfaceHost($etm);
        $result = $host->action('event', 'nonexistent');

        $this->assertFalse($result->ok);
    }

    #[Test]
    public function get_returns_403_when_access_denied(): void
    {
        $entity = $this->createStub(EntityInterface::class);
        $entity->method('toArray')->willReturn(['id' => '1']);

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('load')->willReturn($entity);

        $etm = $this->createMock(EntityTypeManager::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getStorage')->willReturn($storage);

        $accessResult = AccessResult::neutral('Denied.');
        $accessHandler = $this->createMock(EntityAccessHandler::class);
        $accessHandler->method('check')->willReturn($accessResult);

        $host = new GenericAdminSurfaceHost($etm, $accessHandler);

        // Simulate resolveSession to set currentAccount
        $account = $this->createStub(AccountInterface::class);
        $account->method('id')->willReturn(1);
        $account->method('hasPermission')->willReturn(true);
        $account->method('getRoles')->willReturn(['authenticated']);
        $request = Request::create('/admin/surface/session');
        $request->attributes->set('_account', $account);
        $host->resolveSession($request);

        $result = $host->get('event', '1');

        $this->assertFalse($result->ok);
        $this->assertSame(403, $result->error['status']);
    }

    #[Test]
    public function list_filters_entities_by_access(): void
    {
        $eventType = new EntityType(
            id: 'event',
            label: 'Event',
            class: \stdClass::class,
            keys: ['id' => 'eid'],
            group: 'events',
        );

        $allowed = $this->createMock(EntityInterface::class);
        $allowed->method('getEntityTypeId')->willReturn('event');
        $allowed->method('uuid')->willReturn('');
        $allowed->method('id')->willReturn(1);
        $allowed->method('toArray')->willReturn(['eid' => 1, 'title' => 'Visible']);

        $denied = $this->createMock(EntityInterface::class);
        $denied->method('getEntityTypeId')->willReturn('event');
        $denied->method('toArray')->willReturn(['eid' => 2, 'title' => 'Hidden']);

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('loadMultiple')->willReturn([$allowed, $denied]);

        $etm = $this->createMock(EntityTypeManager::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getDefinition')->willReturn($eventType);
        $etm->method('getStorage')->willReturn($storage);

        $accessHandler = $this->createMock(EntityAccessHandler::class);
        $accessHandler->method('check')->willReturnCallback(
            fn($entity) => $entity === $allowed
                ? AccessResult::allowed('OK')
                : AccessResult::neutral('Denied'),
        );
        $accessHandler->method('filterFields')->willReturnCallback(
            static fn(EntityInterface $entity, array $fields): array => $fields,
        );

        $host = new GenericAdminSurfaceHost($etm, $accessHandler);

        // Simulate resolveSession
        $account = $this->createStub(AccountInterface::class);
        $account->method('id')->willReturn(1);
        $account->method('hasPermission')->willReturn(true);
        $account->method('getRoles')->willReturn(['authenticated']);
        $request = Request::create('/');
        $request->attributes->set('_account', $account);
        $host->resolveSession($request);

        $result = $host->list('event');

        $this->assertTrue($result->ok);
        $this->assertIsArray($result->data);
        $this->assertArrayHasKey('entities', $result->data);
        $this->assertCount(1, $result->data['entities']);
        $this->assertSame('Visible', $result->data['entities'][0]['attributes']['title'] ?? null);
        $this->assertSame(1, $result->data['total']);
        $this->assertSame(0, $result->data['offset']);
        $this->assertSame(50, $result->data['limit']);
    }

    #[Test]
    public function list_applies_equals_filter(): void
    {
        $published = $this->createMock(EntityInterface::class);
        $published->method('getEntityTypeId')->willReturn('article');
        $published->method('uuid')->willReturn('');
        $published->method('id')->willReturn(1);
        $published->method('toArray')->willReturn(['id' => 1, 'title' => 'Published Post', 'status' => 'published']);
        $published->method('get')->willReturnCallback(
            fn(string $field) => match ($field) {
                'status' => 'published',
                'title' => 'Published Post',
                default => null,
            },
        );

        $draft = $this->createMock(EntityInterface::class);
        $draft->method('getEntityTypeId')->willReturn('article');
        $draft->method('uuid')->willReturn('');
        $draft->method('id')->willReturn(2);
        $draft->method('toArray')->willReturn(['id' => 2, 'title' => 'Draft Post', 'status' => 'draft']);
        $draft->method('get')->willReturnCallback(
            fn(string $field) => match ($field) {
                'status' => 'draft',
                'title' => 'Draft Post',
                default => null,
            },
        );

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('loadMultiple')->willReturn([$published, $draft]);

        $articleType = new EntityType(
            id: 'article',
            label: 'Article',
            class: \stdClass::class,
            keys: ['id' => 'id'],
        );

        $etm = $this->createMock(EntityTypeManager::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getDefinition')->willReturn($articleType);
        $etm->method('getStorage')->willReturn($storage);

        $host = new GenericAdminSurfaceHost($etm);

        $query = new SurfaceQuery(
            filters: [['field' => 'status', 'operator' => SurfaceFilterOperator::EQUALS, 'value' => 'published']],
        );
        $result = $host->list('article', $query);

        $this->assertTrue($result->ok);
        $this->assertCount(1, $result->data['entities']);
        $this->assertSame('Published Post', $result->data['entities'][0]['attributes']['title']);
    }

    #[Test]
    public function list_applies_in_filter(): void
    {
        $lead = $this->createMock(EntityInterface::class);
        $lead->method('getEntityTypeId')->willReturn('contact');
        $lead->method('uuid')->willReturn('');
        $lead->method('id')->willReturn(1);
        $lead->method('toArray')->willReturn(['id' => 1, 'name' => 'Alice', 'stage' => 'lead']);
        $lead->method('get')->willReturnCallback(
            fn(string $field) => match ($field) { 'stage' => 'lead', 'name' => 'Alice', default => null },
        );

        $qualified = $this->createMock(EntityInterface::class);
        $qualified->method('getEntityTypeId')->willReturn('contact');
        $qualified->method('uuid')->willReturn('');
        $qualified->method('id')->willReturn(2);
        $qualified->method('toArray')->willReturn(['id' => 2, 'name' => 'Bob', 'stage' => 'qualified']);
        $qualified->method('get')->willReturnCallback(
            fn(string $field) => match ($field) { 'stage' => 'qualified', 'name' => 'Bob', default => null },
        );

        $closed = $this->createMock(EntityInterface::class);
        $closed->method('getEntityTypeId')->willReturn('contact');
        $closed->method('uuid')->willReturn('');
        $closed->method('id')->willReturn(3);
        $closed->method('toArray')->willReturn(['id' => 3, 'name' => 'Carol', 'stage' => 'closed']);
        $closed->method('get')->willReturnCallback(
            fn(string $field) => match ($field) { 'stage' => 'closed', 'name' => 'Carol', default => null },
        );

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('loadMultiple')->willReturn([$lead, $qualified, $closed]);

        $contactType = new EntityType(
            id: 'contact',
            label: 'Contact',
            class: \stdClass::class,
            keys: ['id' => 'id'],
        );

        $etm = $this->createMock(EntityTypeManager::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getDefinition')->willReturn($contactType);
        $etm->method('getStorage')->willReturn($storage);

        $host = new GenericAdminSurfaceHost($etm);

        $query = new SurfaceQuery(
            filters: [['field' => 'stage', 'operator' => SurfaceFilterOperator::IN, 'value' => 'lead,qualified']],
        );
        $result = $host->list('contact', $query);

        $this->assertTrue($result->ok);
        $this->assertCount(2, $result->data['entities']);
    }

    #[Test]
    public function list_applies_sort_descending(): void
    {
        $older = $this->createMock(EntityInterface::class);
        $older->method('getEntityTypeId')->willReturn('article');
        $older->method('uuid')->willReturn('');
        $older->method('id')->willReturn(1);
        $older->method('toArray')->willReturn(['id' => 1, 'title' => 'Older', 'created_at' => '2024-01-01']);
        $older->method('get')->willReturnCallback(
            fn(string $field) => match ($field) {
                'created_at' => '2024-01-01',
                'title' => 'Older',
                default => null,
            },
        );

        $newer = $this->createMock(EntityInterface::class);
        $newer->method('getEntityTypeId')->willReturn('article');
        $newer->method('uuid')->willReturn('');
        $newer->method('id')->willReturn(2);
        $newer->method('toArray')->willReturn(['id' => 2, 'title' => 'Newer', 'created_at' => '2024-06-01']);
        $newer->method('get')->willReturnCallback(
            fn(string $field) => match ($field) {
                'created_at' => '2024-06-01',
                'title' => 'Newer',
                default => null,
            },
        );

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('loadMultiple')->willReturn([$older, $newer]);

        $articleType = new EntityType(
            id: 'article',
            label: 'Article',
            class: \stdClass::class,
            keys: ['id' => 'id'],
        );

        $etm = $this->createMock(EntityTypeManager::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getDefinition')->willReturn($articleType);
        $etm->method('getStorage')->willReturn($storage);

        $host = new GenericAdminSurfaceHost($etm);

        $query = new SurfaceQuery(
            sortField: 'created_at',
            sortDirection: 'DESC',
        );
        $result = $host->list('article', $query);

        $this->assertTrue($result->ok);
        $this->assertCount(2, $result->data['entities']);
        $this->assertSame('Newer', $result->data['entities'][0]['attributes']['title']);
        $this->assertSame('Older', $result->data['entities'][1]['attributes']['title']);
    }

    #[Test]
    public function list_without_filters_returns_all(): void
    {
        $entity1 = $this->createMock(EntityInterface::class);
        $entity1->method('getEntityTypeId')->willReturn('event');
        $entity1->method('uuid')->willReturn('');
        $entity1->method('id')->willReturn(1);
        $entity1->method('toArray')->willReturn(['eid' => 1, 'title' => 'First']);

        $entity2 = $this->createMock(EntityInterface::class);
        $entity2->method('getEntityTypeId')->willReturn('event');
        $entity2->method('uuid')->willReturn('');
        $entity2->method('id')->willReturn(2);
        $entity2->method('toArray')->willReturn(['eid' => 2, 'title' => 'Second']);

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('loadMultiple')->willReturn([$entity1, $entity2]);

        $eventType = new EntityType(
            id: 'event',
            label: 'Event',
            class: \stdClass::class,
            keys: ['id' => 'eid'],
        );

        $etm = $this->createMock(EntityTypeManager::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getDefinition')->willReturn($eventType);
        $etm->method('getStorage')->willReturn($storage);

        $host = new GenericAdminSurfaceHost($etm);

        // Call with no query (backward compat)
        $result = $host->list('event');

        $this->assertTrue($result->ok);
        $this->assertCount(2, $result->data['entities']);
        $this->assertSame(2, $result->data['total']);
    }
}
