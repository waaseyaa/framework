<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit\Host;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AdminSurface\Action\SurfaceActionHandlerInterface;
use Waaseyaa\AdminSurface\Catalog\CatalogBuilder;
use Waaseyaa\AdminSurface\Host\AdminSurfaceResultData;
use Waaseyaa\AdminSurface\Host\AdminSurfaceSessionData;
use Waaseyaa\AdminSurface\Host\AdminSurfaceUiPayload;
use Waaseyaa\AdminSurface\Host\GenericAdminSurfaceHost;
use Waaseyaa\Entity\ConfigEntityBase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Tests\Helper\TestEntityType;
use Waaseyaa\AdminSurface\Query\SurfaceFilterOperator;
use Waaseyaa\AdminSurface\Query\SurfaceQuery;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Testing\StorageBackedStubRepository;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;

#[CoversClass(GenericAdminSurfaceHost::class)]
final class GenericAdminSurfaceHostTest extends TestCase
{
    #[Test]
    public function config_entity_lists_use_bounded_hydrated_access_and_keep_rows_read_only(): void
    {
        $configClass = get_class(new class(['type' => 'page', 'name' => 'Page']) extends ConfigEntityBase {
            public function __construct(array $values = [])
            {
                parent::__construct($values, 'test_config', ['id' => 'type', 'label' => 'name']);
            }
        });
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn('test_config');
        $entity->method('id')->willReturn('page');
        $entity->method('uuid')->willReturn('');
        $entity->method('toArray')->willReturn(['type' => 'page', 'name' => 'Page']);

        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects(self::once())->method('findBy')->with([])->willReturn([$entity]);
        $repository->expects(self::never())->method('getQuery');

        $definition = new EntityType(
            id: 'test_config',
            label: 'Test config',
            class: $configClass,
            keys: ['id' => 'type', 'label' => 'name'],
            _fieldDefinitions: ['name' => ['type' => 'string']],
        );
        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getDefinition')->willReturn($definition);
        $etm->method('resolveFieldDefinitions')->willReturn([
            'name' => new FieldDefinition(name: 'name', type: 'string'),
        ]);
        $etm->method('getRepository')->willReturn($repository);

        $result = $this->permissiveHost($etm)->list('test_config');

        self::assertTrue($result->ok);
        self::assertSame(1, $result->data['total']);
        self::assertSame('page', $result->data['entities'][0]['id']);
        self::assertSame(['view' => true, 'edit' => false, 'delete' => false], $result->data['entities'][0]['capabilities']);
    }

    #[Test]
    public function configured_read_only_types_reject_every_crud_action(): void
    {
        $etm = $this->createStub(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);
        $host = new GenericAdminSurfaceHost($etm, readOnlyTypes: ['event']);

        foreach (['create', 'update', 'delete'] as $action) {
            $result = $host->action('event', $action, ['id' => '1', 'attributes' => ['title' => 'changed']]);

            self::assertFalse($result->ok, "{$action} must be rejected for a read-only type.");
            self::assertSame(403, $result->error['status']);
        }
    }

    #[Test]
    public function list_pushes_filters_sort_and_pagination_into_the_entity_query(): void
    {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn('article');
        $entity->method('id')->willReturn(9);
        $entity->method('uuid')->willReturn('');
        $entity->method('toArray')->willReturn(['id' => 9, 'status' => 'published', 'title' => 'Visible']);
        $entity->method('get')->willReturnCallback(static fn(string $field): mixed => match ($field) {
            'id' => 9,
            'status' => 'published',
            'title' => 'Visible',
            default => null,
        });

        $scopeQuery = $this->createMock(EntityQueryInterface::class);
        $scopeQuery->expects(self::once())->method('setAccount')->willReturnSelf();
        $scopeQuery->expects(self::once())->method('execute')->willReturn([9, 10, 11]);

        $pageConditions = [];
        $pageQuery = $this->createMock(EntityQueryInterface::class);
        $pageQuery->expects(self::once())->method('setAccount')->willReturnSelf();
        $pageQuery->expects(self::once())->method('condition')->willReturnCallback(
            function (string $field, mixed $value, string $operator) use (&$pageConditions, $pageQuery): EntityQueryInterface {
                $pageConditions[] = [$field, $value, $operator];

                return $pageQuery;
            },
        );
        $pageQuery->expects(self::once())->method('sort')->with('title', 'DESC')->willReturnSelf();
        $pageQuery->expects(self::once())->method('range')->with(20, 10)->willReturnSelf();
        $pageQuery->expects(self::once())->method('execute')->willReturn([9]);

        $totalConditions = [];
        $totalQuery = $this->createMock(EntityQueryInterface::class);
        $totalQuery->expects(self::once())->method('setAccount')->willReturnSelf();
        $totalQuery->expects(self::once())->method('condition')->willReturnCallback(
            function (string $field, mixed $value, string $operator) use (&$totalConditions, $totalQuery): EntityQueryInterface {
                $totalConditions[] = [$field, $value, $operator];

                return $totalQuery;
            },
        );
        $totalQuery->expects(self::once())->method('count')->willReturnSelf();
        $totalQuery->expects(self::once())->method('execute')->willReturn([3]);

        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects(self::exactly(3))->method('getQuery')->willReturnOnConsecutiveCalls($scopeQuery, $pageQuery, $totalQuery);
        $repository->expects(self::exactly(2))->method('findMany')->willReturnCallback(
            static fn(array $ids): array => $ids === [9, 10, 11] ? [$entity, $entity, $entity] : [$entity],
        );
        $repository->expects(self::never())->method('findBy');

        $definition = new EntityType(
            id: 'article',
            label: 'Article',
            class: \stdClass::class,
            keys: ['id' => 'id'],
            _fieldDefinitions: [
                'status' => ['type' => 'string'],
                'title' => ['type' => 'string'],
            ],
        );
        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getDefinition')->willReturn($definition);
        $etm->method('resolveFieldDefinitions')->willReturn([
            'status' => new FieldDefinition(name: 'status', type: 'string'),
            'title' => new FieldDefinition(name: 'title', type: 'string'),
        ]);
        $etm->method('getRepository')->willReturn($repository);

        $host = $this->permissiveHost($etm, deniedOperations: ['update']);
        $result = $host->list('article', new SurfaceQuery(
            filters: [['field' => 'title', 'operator' => SurfaceFilterOperator::STARTS_WITH, 'value' => 'Vis']],
            sortField: 'title',
            sortDirection: 'DESC',
            offset: 20,
            limit: 10,
        ));

        self::assertTrue($result->ok);
        self::assertSame(3, $result->data['total']);
        self::assertSame([9], array_map(static fn(array $row): int => (int) $row['id'], $result->data['entities']));
        self::assertSame(
            ['view' => true, 'edit' => false, 'delete' => true],
            $result->data['entities'][0]['capabilities'],
        );
        self::assertSame([
            ['title', 'Vis', 'STARTS_WITH'],
        ], $pageConditions);
        self::assertSame([
            ['title', 'Vis', 'STARTS_WITH'],
        ], $totalConditions);
    }

    /**
     * A host wired with a permissive access handler and a resolved admin session,
     * so list/get exercise filter/sort/serialize logic with access granted. The
     * host fails closed without both, which these data-shaping tests do not
     * exercise (dedicated fail-closed tests cover that path).
     */
    /** @param list<string> $deniedOperations */
    private function permissiveHost(EntityTypeManagerInterface $etm, array $deniedOperations = []): GenericAdminSurfaceHost
    {
        $accessHandler = $this->createMock(EntityAccessHandler::class);
        $accessHandler->method('check')->willReturnCallback(
            static fn(EntityInterface $entity, string $operation): AccessResult => in_array($operation, $deniedOperations, true)
                ? AccessResult::forbidden('private policy reason that must not be serialized')
                : AccessResult::allowed('ok'),
        );
        $accessHandler->method('filterFields')->willReturnCallback(
            static fn(EntityInterface $entity, array $fields): array => $fields,
        );
        // R13 WP1: applyFilter()/the sort comparator now call checkFieldAccess()
        // per entity; Neutral (not Forbidden) matches this helper's "permissive"
        // contract, letting these data-shaping tests read every field's value.
        $accessHandler->method('checkFieldAccess')->willReturn(AccessResult::neutral('ok'));

        $host = new GenericAdminSurfaceHost($etm, $accessHandler);

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
    public function list_fails_closed_without_access_handler(): void
    {
        $storage = $this->createMock(EntityStorageInterface::class);

        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getStorage')->willReturn($storage);
        $etm->method('getRepository')->willReturn(new StorageBackedStubRepository($storage));

        // No access handler and no resolved session — must expose nothing.
        $host = new GenericAdminSurfaceHost($etm);
        $result = $host->list('event');

        $this->assertTrue($result->ok);
        $this->assertSame([], $result->data['entities']);
        $this->assertSame(0, $result->data['total']);
    }

    #[Test]
    public function get_fails_closed_without_access_handler(): void
    {
        $entity = $this->createStub(EntityInterface::class);

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('load')->willReturn($entity);

        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getStorage')->willReturn($storage);
        $etm->method('getRepository')->willReturn(new StorageBackedStubRepository($storage));

        // No access handler and no resolved session — must deny.
        $host = new GenericAdminSurfaceHost($etm);
        $result = $host->get('event', '1');

        $this->assertFalse($result->ok);
        $this->assertSame(403, $result->error['status']);
    }

    #[Test]
    public function resolve_session_returns_null_for_unauthenticated_request(): void
    {
        $host = new GenericAdminSurfaceHost($this->createMock(EntityTypeManagerInterface::class));
        $request = Request::create('/admin/surface/session');

        $this->assertNull($host->resolveSession($request));
    }

    #[Test]
    public function resolve_session_returns_null_for_non_admin(): void
    {
        $account = $this->createStub(AuthorizationPrincipalInterface::class);
        $account->method('id')->willReturn(1);
        $account->method('hasPermission')->willReturn(false);
        $account->method('getRoles')->willReturn(['authenticated']);

        $host = new GenericAdminSurfaceHost($this->createMock(EntityTypeManagerInterface::class));
        $request = Request::create('/admin/surface/session');
        $request->attributes->set('_account', $account);

        $this->assertNull($host->resolveSession($request));
    }

    #[Test]
    public function resolve_session_returns_data_for_admin(): void
    {
        $account = $this->createStub(AuthorizationPrincipalInterface::class);
        $account->method('id')->willReturn(42);
        $account->method('hasPermission')->willReturn(true);
        $account->method('getRoles')->willReturn(['administrator']);

        $host = new GenericAdminSurfaceHost(
            $this->createMock(EntityTypeManagerInterface::class),
            tenantId: 'myapp',
            tenantName: 'My App',
            features: ['mcp' => true],
        );
        $request = Request::create('/admin/surface/session');
        $request->attributes->set('_account', $account);

        $session = $host->resolveSession($request);

        $this->assertNotNull($session);
        $this->assertSame('42', $session->accountId);
        $this->assertSame('myapp', $session->tenantId);
        $this->assertSame('My App', $session->tenantName);
        $this->assertContains('administrator', $session->roles);
        $this->assertSame(['mcp' => true], $session->features);
        $this->assertNull($session->ui);
    }

    #[Test]
    public function resolve_session_includes_ui_from_buildAdminUi(): void
    {
        $account = $this->createStub(AuthorizationPrincipalInterface::class);
        $account->method('id')->willReturn(7);
        $account->method('hasPermission')->willReturn(true);
        $account->method('getRoles')->willReturn(['administrator']);

        $host = new class($this->createMock(EntityTypeManagerInterface::class)) extends GenericAdminSurfaceHost {
            protected function buildAdminUi(AccountInterface $account): ?AdminSurfaceUiPayload
            {
                return AdminSurfaceUiPayload::fromArrays(
                    headerLinks: [['label' => 'Docs', 'href' => 'https://docs.example']],
                );
            }
        };

        $request = Request::create('/admin/_surface/session');
        $request->attributes->set('_account', $account);

        $session = $host->resolveSession($request);

        $this->assertNotNull($session);
        $this->assertNotNull($session->ui);
        $this->assertSame(
            [['label' => 'Docs', 'href' => 'https://docs.example']],
            $session->ui->headerLinks,
        );
    }

    #[Test]
    public function resolve_session_uses_custom_permission(): void
    {
        $account = $this->createStub(AuthorizationPrincipalInterface::class);
        $account->method('id')->willReturn(1);
        $account->method('hasPermission')->willReturnCallback(
            fn(string $perm) => $perm === 'manage site',
        );
        $account->method('getRoles')->willReturn(['editor']);

        $host = new GenericAdminSurfaceHost(
            $this->createMock(EntityTypeManagerInterface::class),
            adminPermission: 'manage site',
        );
        $request = Request::create('/admin/surface/session');
        $request->attributes->set('_account', $account);

        $this->assertNotNull($host->resolveSession($request));
    }

    #[Test]
    public function build_catalog_returns_entity_definitions(): void
    {
        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('getDefinitions')->willReturn([
            new EntityType(
                id: 'event',
                label: 'Event',
                class: \stdClass::class,
                keys: ['id' => 'eid'],
                group: 'events',
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
    public function build_catalog_exposes_authoritative_reference_fields_and_fails_closed_for_unsafe_labels(): void
    {
        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('getDefinitions')->willReturn([
            new EntityType(
                id: 'node',
                label: 'Content',
                class: \stdClass::class,
                keys: ['id' => 'nid', 'label' => 'title'],
            ),
            new EntityType(
                id: 'media',
                label: 'Media',
                class: \stdClass::class,
                keys: ['id' => 'mid', 'label' => 'name'],
            ),
            new EntityType(
                id: 'unlabelled',
                label: 'Unlabelled',
                class: \stdClass::class,
                keys: ['id' => 'id'],
            ),
            new EntityType(
                id: 'malformed',
                label: 'Malformed',
                class: \stdClass::class,
                keys: ['id' => 'id', 'label' => 'not-a-field'],
            ),
            new EntityType(
                id: 'restricted',
                label: 'Restricted',
                class: \stdClass::class,
                keys: ['id' => 'id', 'label' => 'secret_label'],
                _fieldDefinitions: [
                    'secret_label' => new FieldDefinition(
                        name: 'secret_label',
                        type: 'string',
                        settings: ['internal' => true],
                    ),
                ],
            ),
        ]);

        $host = new GenericAdminSurfaceHost($etm);
        $catalog = $host->buildCatalog(new AdminSurfaceSessionData(
            accountId: '1',
            accountName: 'Admin',
            roles: ['administrator'],
            policies: [],
        ))->build();
        $byId = array_column($catalog, null, 'id');

        self::assertSame([
            'labelField' => 'title',
            'search' => ['field' => 'title', 'operator' => 'STARTS_WITH'],
            'sort' => ['field' => 'title', 'direction' => 'ASC'],
        ], $byId['node']['reference']);
        self::assertSame([
            'labelField' => 'name',
            'search' => ['field' => 'name', 'operator' => 'STARTS_WITH'],
            'sort' => ['field' => 'name', 'direction' => 'ASC'],
        ], $byId['media']['reference']);
        self::assertArrayNotHasKey('reference', $byId['unlabelled']);
        self::assertArrayNotHasKey('reference', $byId['malformed']);
        self::assertArrayNotHasKey('reference', $byId['restricted']);
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

        $etm = $this->createMock(EntityTypeManagerInterface::class);
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
    public function explicit_create_bundle_schema_is_create_gated_but_id_scoped_edit_schema_is_not(): void
    {
        $registry = new FieldDefinitionRegistry();
        $registry->registerBundleFields('article', 'restricted', [
            new FieldDefinition(
                name: 'restricted_body',
                type: 'text',
                targetEntityTypeId: 'article',
                targetBundle: 'restricted',
            ),
        ]);
        $definition = new EntityType(
            id: 'article',
            label: 'Article',
            class: BundleSchemaTestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
        );
        $existing = new BundleSchemaTestEntity([
            'id' => 1,
            'uuid' => 'existing-uuid',
            'title' => 'Existing restricted article',
            'type' => 'restricted',
        ]);
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('find')->with('1')->willReturn($existing);

        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getDefinition')->willReturn($definition);
        $etm->method('getRepository')->willReturn($repository);
        $etm->method('resolveFieldDefinitions')->willReturnCallback(
            static fn(string $type, ?string $bundle = null): array => $bundle === 'restricted'
                ? ['restricted_body' => new FieldDefinition(name: 'restricted_body', type: 'text')]
                : [],
        );

        $account = $this->createStub(AuthorizationPrincipalInterface::class);
        $account->method('id')->willReturn(1);
        $account->method('hasPermission')->willReturn(true);
        $account->method('getRoles')->willReturn(['administrator']);
        $accessHandler = $this->createMock(EntityAccessHandler::class);
        $accessHandler->expects(self::once())
            ->method('checkCreateAccess')
            ->with('article', 'restricted', $account)
            ->willReturn(AccessResult::forbidden('private bundle detail'));
        $accessHandler->method('checkFieldAccess')->willReturn(AccessResult::neutral());

        $host = new GenericAdminSurfaceHost(
            $etm,
            $accessHandler,
            new \Waaseyaa\Api\Schema\SchemaPresenter($registry),
        );
        $request = Request::create('/admin/_surface/session');
        $request->attributes->set('_account', $account);
        self::assertNotNull($host->resolveSession($request));

        $createSchema = $host->action('article', 'schema', ['bundle' => 'restricted']);
        self::assertFalse($createSchema->ok);
        self::assertSame(403, $createSchema->error['status']);
        self::assertSame('Access denied', $createSchema->error['title']);
        self::assertSame('You do not have permission to create this entity.', $createSchema->error['detail']);
        self::assertStringNotContainsString('restricted', json_encode($createSchema->toArray(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('private bundle detail', json_encode($createSchema->toArray(), JSON_THROW_ON_ERROR));

        $editSchema = $host->action('article', 'schema', ['id' => '1']);
        self::assertTrue($editSchema->ok);
        self::assertArrayHasKey('restricted_body', $editSchema->data['properties']);
    }

    #[Test]
    public function build_catalog_marks_custom_read_only_types(): void
    {
        $etm = $this->createMock(EntityTypeManagerInterface::class);
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
        $etm = $this->createMock(EntityTypeManagerInterface::class);
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
        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(false);

        $host = new GenericAdminSurfaceHost($etm);
        $result = $host->list('nonexistent');

        $this->assertFalse($result->ok);
    }

    #[Test]
    public function get_returns_error_for_unknown_type(): void
    {
        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(false);

        $host = new GenericAdminSurfaceHost($etm);
        $result = $host->get('nonexistent', '123');

        $this->assertFalse($result->ok);
    }

    #[Test]
    public function action_returns_error_for_unknown_action(): void
    {
        $storage = $this->createMock(EntityStorageInterface::class);

        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getStorage')->willReturn($storage);
        $etm->method('getRepository')->willReturn(new StorageBackedStubRepository($storage));

        $host = new GenericAdminSurfaceHost($etm);
        $result = $host->action('event', 'nonexistent');

        $this->assertFalse($result->ok);
    }

    #[Test]
    public function get_returns_403_when_access_denied(): void
    {
        $entity = $this->createStub(EntityInterface::class);
        $entity->method('toArray')->willReturn(['id' => '1']);
        $entity->method('get')->willReturnCallback(
            fn(string $field) => match ($field) { 'id' => '1', default => null },
        );

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('load')->willReturn($entity);

        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getStorage')->willReturn($storage);
        $etm->method('getRepository')->willReturn(new StorageBackedStubRepository($storage));

        $accessResult = AccessResult::neutral('Denied.');
        $accessHandler = $this->createMock(EntityAccessHandler::class);
        $accessHandler->method('check')->willReturn($accessResult);

        $host = new GenericAdminSurfaceHost($etm, $accessHandler);

        // Simulate resolveSession to set currentAccount
        $account = $this->createStub(AuthorizationPrincipalInterface::class);
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
    public function delete_resolves_by_uuid_when_id_is_non_numeric(): void
    {
        // The admin SPA sends the JSON:API resource id, which is the UUID for
        // int-keyed content entities. handleDelete must resolve it via the UUID
        // fallback (like get()) and actually delete — instead of returning a
        // misleading 404 and leaving the row in storage (D7 regression guard).
        $uuid = '807e4373-0f98-44fe-9fb5-111d5bd3a5ef';

        $entity = $this->createMock(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn('story');
        $entity->method('id')->willReturn(1);
        $entity->method('uuid')->willReturn($uuid);

        // Non-numeric id => find() is skipped; resolution goes through the
        // bounded uuid query + find() (C-22 WP3: loadByKey() has no repository
        // equivalent).
        $query = $this->createMock(\Waaseyaa\Entity\Storage\EntityQueryInterface::class);
        $query->method('accessCheck')->willReturnSelf();
        $query->method('condition')->with('uuid', $uuid)->willReturnSelf();
        $query->method('range')->willReturnSelf();
        $query->method('execute')->willReturn(['1']);

        $repository = $this->createMock(\Waaseyaa\Entity\Repository\EntityRepositoryInterface::class);
        $repository->method('getQuery')->willReturn($query);
        $repository->method('find')->with('1')->willReturn($entity);
        $repository->expects($this->once())->method('delete')->with($entity);

        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getRepository')->willReturn($repository);

        $accessHandler = $this->createMock(EntityAccessHandler::class);
        $accessHandler->method('check')->willReturn(AccessResult::allowed('ok'));

        $host = new GenericAdminSurfaceHost($etm, $accessHandler);

        $account = $this->createStub(AuthorizationPrincipalInterface::class);
        $account->method('id')->willReturn(1);
        $account->method('hasPermission')->willReturn(true);
        $account->method('getRoles')->willReturn(['administrator']);
        $request = Request::create('/admin/surface/session');
        $request->attributes->set('_account', $account);
        $host->resolveSession($request);

        $result = $host->action('story', 'delete', ['id' => $uuid]);

        $this->assertTrue($result->ok, 'Delete by UUID should succeed, not 404.');
        $this->assertSame(['deleted' => true], $result->data);
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
        $allowed->method('get')->willReturnCallback(
            fn(string $field) => match ($field) {
                'eid' => 1,
                'title' => 'Visible',
                default => null,
            },
        );

        $denied = $this->createMock(EntityInterface::class);
        $denied->method('getEntityTypeId')->willReturn('event');
        $denied->method('toArray')->willReturn(['eid' => 2, 'title' => 'Hidden']);
        $denied->method('get')->willReturnCallback(
            fn(string $field) => match ($field) {
                'eid' => 2,
                'title' => 'Hidden',
                default => null,
            },
        );

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('loadMultiple')->willReturn([$allowed, $denied]);

        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getDefinition')->willReturn($eventType);
        $etm->method('getStorage')->willReturn($storage);
        $etm->method('getRepository')->willReturn(new StorageBackedStubRepository($storage));

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
        $account = $this->createStub(AuthorizationPrincipalInterface::class);
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

        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getDefinition')->willReturn($articleType);
        // R13 WP1: list() now validates filter/sort fields against
        // resolveFieldDefinitions() + entity keys before running the query.
        $etm->method('resolveFieldDefinitions')->willReturn([
            'status' => new FieldDefinition(name: 'status', type: 'string'),
        ]);
        $etm->method('getStorage')->willReturn($storage);
        $etm->method('getRepository')->willReturn(new StorageBackedStubRepository($storage));

        $host = $this->permissiveHost($etm);

        $query = new SurfaceQuery(
            filters: [['field' => 'status', 'operator' => SurfaceFilterOperator::EQUALS, 'value' => 'published']],
        );
        $result = $host->list('article', $query);

        $this->assertTrue($result->ok);
        $this->assertCount(1, $result->data['entities']);
        $this->assertSame('Published Post', $result->data['entities'][0]['attributes']['title']);
    }

    #[Test]
    public function list_gt_filter_compares_non_numeric_strings_lexicographically_not_as_zero(): void
    {
        $high = $this->createMock(EntityInterface::class);
        $high->method('getEntityTypeId')->willReturn('row');
        $high->method('uuid')->willReturn('');
        $high->method('id')->willReturn(1);
        $high->method('toArray')->willReturn(['id' => 1, 'code' => 'zzz']);
        $high->method('get')->willReturnCallback(
            fn(string $field) => match ($field) { 'code' => 'zzz', default => null },
        );

        $low = $this->createMock(EntityInterface::class);
        $low->method('getEntityTypeId')->willReturn('row');
        $low->method('uuid')->willReturn('');
        $low->method('id')->willReturn(2);
        $low->method('toArray')->willReturn(['id' => 2, 'code' => 'aaa']);
        $low->method('get')->willReturnCallback(
            fn(string $field) => match ($field) { 'code' => 'aaa', default => null },
        );

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('loadMultiple')->willReturn([$high, $low]);

        $type = new EntityType(
            id: 'row',
            label: 'Row',
            class: \stdClass::class,
            keys: ['id' => 'id'],
        );

        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getDefinition')->willReturn($type);
        // R13 WP1: list() now validates filter/sort fields against
        // resolveFieldDefinitions() + entity keys before running the query.
        $etm->method('resolveFieldDefinitions')->willReturn([
            'code' => new FieldDefinition(name: 'code', type: 'string'),
        ]);
        $etm->method('getStorage')->willReturn($storage);
        $etm->method('getRepository')->willReturn(new StorageBackedStubRepository($storage));

        $host = $this->permissiveHost($etm);

        // Lexicographically 'zzz' > 'mmm'; float cast would make both 0.0 and match nothing.
        $query = new SurfaceQuery(
            filters: [['field' => 'code', 'operator' => SurfaceFilterOperator::GT, 'value' => 'mmm']],
        );
        $result = $host->list('row', $query);

        $this->assertTrue($result->ok);
        $this->assertCount(1, $result->data['entities']);
        $this->assertSame('zzz', $result->data['entities'][0]['attributes']['code']);
    }

    #[Test]
    public function list_gt_filter_compares_numeric_strings_as_numbers(): void
    {
        $ten = $this->createMock(EntityInterface::class);
        $ten->method('getEntityTypeId')->willReturn('row');
        $ten->method('uuid')->willReturn('');
        $ten->method('id')->willReturn(1);
        $ten->method('toArray')->willReturn(['id' => 1, 'n' => '10']);
        $ten->method('get')->willReturnCallback(
            fn(string $field) => match ($field) { 'n' => '10', default => null },
        );

        $two = $this->createMock(EntityInterface::class);
        $two->method('getEntityTypeId')->willReturn('row');
        $two->method('uuid')->willReturn('');
        $two->method('id')->willReturn(2);
        $two->method('toArray')->willReturn(['id' => 2, 'n' => '2']);
        $two->method('get')->willReturnCallback(
            fn(string $field) => match ($field) { 'n' => '2', default => null },
        );

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('loadMultiple')->willReturn([$ten, $two]);

        $type = new EntityType(
            id: 'row',
            label: 'Row',
            class: \stdClass::class,
            keys: ['id' => 'id'],
        );

        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getDefinition')->willReturn($type);
        // R13 WP1: list() now validates filter/sort fields against
        // resolveFieldDefinitions() + entity keys before running the query.
        $etm->method('resolveFieldDefinitions')->willReturn([
            'n' => new FieldDefinition(name: 'n', type: 'string'),
        ]);
        $etm->method('getStorage')->willReturn($storage);
        $etm->method('getRepository')->willReturn(new StorageBackedStubRepository($storage));

        $host = $this->permissiveHost($etm);

        $query = new SurfaceQuery(
            filters: [['field' => 'n', 'operator' => SurfaceFilterOperator::GT, 'value' => '5']],
        );
        $result = $host->list('row', $query);

        $this->assertTrue($result->ok);
        $this->assertCount(1, $result->data['entities']);
        $this->assertSame('10', $result->data['entities'][0]['attributes']['n']);
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

        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getDefinition')->willReturn($contactType);
        // R13 WP1: list() now validates filter/sort fields against
        // resolveFieldDefinitions() + entity keys before running the query.
        $etm->method('resolveFieldDefinitions')->willReturn([
            'name' => new FieldDefinition(name: 'name', type: 'string'),
            'stage' => new FieldDefinition(name: 'stage', type: 'string'),
        ]);
        $etm->method('getStorage')->willReturn($storage);
        $etm->method('getRepository')->willReturn(new StorageBackedStubRepository($storage));

        $host = $this->permissiveHost($etm);

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
        $older->method('toArray')->willReturn(['id' => 1, 'type' => 'article_bundle', 'title' => 'Older', 'created_at' => '2024-01-01']);
        $older->method('get')->willReturnCallback(
            fn(string $field) => match ($field) {
                'created_at' => new \DateTimeImmutable('2024-01-01T00:00:00Z'),
                'type' => 'article_bundle',
                'title' => 'Older',
                default => null,
            },
        );

        $newer = $this->createMock(EntityInterface::class);
        $newer->method('getEntityTypeId')->willReturn('article');
        $newer->method('uuid')->willReturn('');
        $newer->method('id')->willReturn(2);
        $newer->method('toArray')->willReturn(['id' => 2, 'type' => 'other_bundle', 'title' => 'Newer', 'created_at' => '2024-06-01']);
        $newer->method('get')->willReturnCallback(
            fn(string $field) => match ($field) {
                'created_at' => new \DateTimeImmutable('2024-06-01T00:00:00Z'),
                'type' => 'other_bundle',
                'title' => 'Newer',
                default => null,
            },
        );

        $scopeQuery = $this->createMock(EntityQueryInterface::class);
        $scopeQuery->expects(self::once())->method('setAccount')->willReturnSelf();
        $scopeQuery->expects(self::once())->method('execute')->willReturn([1, 2]);

        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects(self::once())->method('getQuery')->willReturn($scopeQuery);
        $repository->expects(self::once())->method('findMany')->with([1, 2])->willReturn([$older, $newer]);

        $articleType = new EntityType(
            id: 'article',
            label: 'Article',
            class: \stdClass::class,
            keys: ['id' => 'id', 'bundle' => 'type'],
        );

        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getDefinition')->willReturn($articleType);
        // R13 WP1: list() now validates filter/sort fields against
        // resolveFieldDefinitions() + entity keys before running the query.
        $etm->method('resolveFieldDefinitions')->willReturnCallback(
            static fn(string $type, ?string $bundle = null): array => in_array($bundle, ['article_bundle', 'other_bundle'], true)
                ? ['created_at' => new FieldDefinition(name: 'created_at', type: 'datetime', targetEntityTypeId: 'article', targetBundle: 'article_bundle')]
                : [],
        );
        $etm->method('getRepository')->willReturn($repository);

        $host = $this->permissiveHost($etm);

        $query = new SurfaceQuery(
            filters: [['field' => 'type', 'operator' => SurfaceFilterOperator::IN, 'value' => ['article_bundle', 'other_bundle']]],
            sortField: 'created_at',
            sortDirection: 'DESC',
            limit: 1,
            trustedBundleScope: ['article_bundle', 'other_bundle'],
        );
        $result = $host->list('article', $query);

        $this->assertTrue($result->ok);
        $this->assertSame(2, $result->data['total']);
        $this->assertCount(1, $result->data['entities']);
        $this->assertSame('Newer', $result->data['entities'][0]['attributes']['title']);
    }

    #[Test]
    public function caller_bundle_filter_cannot_claim_trusted_bundle_field_scope(): void
    {
        $type = new EntityType(
            id: 'article',
            label: 'Article',
            class: \stdClass::class,
            keys: ['id' => 'id', 'bundle' => 'type'],
        );
        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getDefinition')->willReturn($type);
        $etm->method('resolveFieldDefinitions')->willReturnCallback(
            static fn(string $entityType, ?string $bundle = null): array => $bundle === 'private'
                ? ['bundle_secret' => new FieldDefinition(name: 'bundle_secret', type: 'string', targetEntityTypeId: 'article', targetBundle: 'private')]
                : [],
        );
        $etm->expects(self::never())->method('getRepository');

        $result = (new GenericAdminSurfaceHost($etm))->list('article', new SurfaceQuery(
            filters: [['field' => 'type', 'operator' => SurfaceFilterOperator::EQUALS, 'value' => 'private']],
            sortField: 'bundle_secret',
        ));

        self::assertFalse($result->ok);
        self::assertSame(400, $result->error['status']);
        self::assertSame('Invalid sort field', $result->error['title']);
    }

    #[Test]
    public function trusted_bundle_fields_must_be_common_and_non_internal_across_the_complete_scope(): void
    {
        $type = new EntityType(
            id: 'article',
            label: 'Article',
            class: \stdClass::class,
            keys: ['id' => 'id', 'bundle' => 'type'],
        );
        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getDefinition')->willReturn($type);
        $etm->method('resolveFieldDefinitions')->willReturnCallback(
            static function (string $entityType, ?string $bundle = null): array {
                return match ($bundle) {
                    'a', 'c' => ['shared' => new FieldDefinition(name: 'shared', type: 'string', targetEntityTypeId: 'article', targetBundle: $bundle)],
                    'internal' => ['shared' => new FieldDefinition(name: 'shared', type: 'string', settings: ['internal' => true], targetEntityTypeId: 'article', targetBundle: $bundle)],
                    default => [],
                };
            },
        );
        $etm->expects(self::never())->method('getRepository');
        $host = new GenericAdminSurfaceHost($etm);

        $missingMiddle = $host->list('article', new SurfaceQuery(
            sortField: 'shared',
            trustedBundleScope: ['a', 'missing', 'c'],
        ));
        $internalVariant = $host->list('article', new SurfaceQuery(
            sortField: 'shared',
            trustedBundleScope: ['a', 'internal'],
        ));

        self::assertFalse($missingMiddle->ok);
        self::assertSame('Invalid sort field', $missingMiddle->error['title']);
        self::assertFalse($internalVariant->ok);
        self::assertSame('Invalid sort field', $internalVariant->error['title']);
    }

    #[Test]
    public function list_without_filters_returns_all(): void
    {
        $entity1 = $this->createMock(EntityInterface::class);
        $entity1->method('getEntityTypeId')->willReturn('event');
        $entity1->method('uuid')->willReturn('');
        $entity1->method('id')->willReturn(1);
        $entity1->method('toArray')->willReturn(['eid' => 1, 'title' => 'First']);
        $entity1->method('get')->willReturnCallback(
            fn(string $field) => match ($field) {
                'eid' => 1,
                'title' => 'First',
                default => null,
            },
        );

        $entity2 = $this->createMock(EntityInterface::class);
        $entity2->method('getEntityTypeId')->willReturn('event');
        $entity2->method('uuid')->willReturn('');
        $entity2->method('id')->willReturn(2);
        $entity2->method('toArray')->willReturn(['eid' => 2, 'title' => 'Second']);
        $entity2->method('get')->willReturnCallback(
            fn(string $field) => match ($field) {
                'eid' => 2,
                'title' => 'Second',
                default => null,
            },
        );

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('loadMultiple')->willReturn([$entity1, $entity2]);

        $eventType = new EntityType(
            id: 'event',
            label: 'Event',
            class: \stdClass::class,
            keys: ['id' => 'eid'],
        );

        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getDefinition')->willReturn($eventType);
        $etm->method('getStorage')->willReturn($storage);
        $etm->method('getRepository')->willReturn(new StorageBackedStubRepository($storage));

        $host = $this->permissiveHost($etm);

        // Call with no query (backward compat)
        $result = $host->list('event');

        $this->assertTrue($result->ok);
        $this->assertCount(2, $result->data['entities']);
        $this->assertSame(2, $result->data['total']);
    }

    #[Test]
    public function action_dispatches_to_custom_handler(): void
    {
        $expectedResult = AdminSurfaceResultData::success(['custom' => true]);

        $handler = new class($expectedResult) implements SurfaceActionHandlerInterface {
            public function __construct(private readonly AdminSurfaceResultData $result) {}

            public function handle(string $type, array $payload): AdminSurfaceResultData
            {
                return $this->result;
            }
        };

        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);

        // Use a test subclass to set the protected $actions property
        $host = new class($etm, $handler) extends GenericAdminSurfaceHost {
            public function __construct(EntityTypeManagerInterface $etm, SurfaceActionHandlerInterface $handler)
            {
                parent::__construct($etm);
                $this->actions['transition-stage'] = $handler;
            }
        };

        $result = $host->action('lead', 'transition-stage', ['stage' => 'qualified']);

        $this->assertTrue($result->ok);
        $this->assertSame(['custom' => true], $result->data);
    }

    #[Test]
    public function action_returns_400_for_unknown_custom_action(): void
    {
        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);

        $host = new GenericAdminSurfaceHost($etm);
        $result = $host->action('event', 'nonexistent-action');

        $this->assertFalse($result->ok);
        $this->assertSame(400, $result->error['status']);
        $this->assertStringContainsString('nonexistent-action', $result->error['detail']);
    }

    #[Test]
    public function builtin_actions_still_work(): void
    {
        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getDefinitions')->willReturn([
            TestEntityType::stub(
                id: 'event',
                class: \stdClass::class,
                keys: ['id' => 'eid'],
                label: 'Event',
                fieldDefinitions: [
                    'title' => ['type' => 'string', 'label' => 'Title'],
                ],
            ),
        ]);
        $etm->method('getDefinition')->willReturn(
            TestEntityType::stub(
                id: 'event',
                class: \stdClass::class,
                keys: ['id' => 'eid'],
                label: 'Event',
                fieldDefinitions: [
                    'title' => ['type' => 'string', 'label' => 'Title'],
                ],
            ),
        );

        // Register a custom action to confirm it doesn't interfere with built-ins
        $handler = new class() implements SurfaceActionHandlerInterface {
            public function handle(string $type, array $payload): AdminSurfaceResultData
            {
                return AdminSurfaceResultData::success(['should_not_see' => true]);
            }
        };

        $host = new class($etm, $handler) extends GenericAdminSurfaceHost {
            public function __construct(EntityTypeManagerInterface $etm, SurfaceActionHandlerInterface $handler)
            {
                parent::__construct($etm);
                $this->actions['my-custom'] = $handler;
            }
        };

        // 'schema' is a built-in action — should NOT be intercepted by custom actions
        $result = $host->action('event', 'schema');

        $this->assertTrue($result->ok);
        // Schema result should contain field definitions, not the custom handler's output
        $this->assertArrayNotHasKey('should_not_see', $result->data);
    }
}

final class BundleSchemaTestEntity extends ContentEntityBase
{
    public function __construct(array $values = [])
    {
        parent::__construct(
            $values,
            'article',
            ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
        );
    }
}
