<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase7;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityRepository;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Node\Node;
use Waaseyaa\Node\NodeAccessPolicy;
use Waaseyaa\Node\NodeAuthorizationSnapshotReader;
use Waaseyaa\Tests\Support\AuthorizationPrincipalFactory;
use Waaseyaa\User\AnonymousUser;

/**
 * JSON:API operations with access control integration tests.
 *
 * Exercises: waaseyaa/api (JsonApiController) with waaseyaa/access (EntityAccessHandler),
 * waaseyaa/node (NodeAccessPolicy), and waaseyaa/user (User, AnonymousUser).
 */
#[CoversNothing]
final class JsonApiAccessIntegrationTest extends TestCase
{
    private InMemoryEntityStorage $storage;
    private EntityTypeManager $entityTypeManager;
    private EntityAccessHandler $accessHandler;
    private AccountFieldReadScope $fieldReadScope;

    protected function setUp(): void
    {
        EntityReadRuntime::installGuard(null);
        EntityReadRuntime::installFieldRegistry(null);
        $this->storage = new NodeInMemoryStorage('node');

        $this->entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            fn() => $this->storage,
            fn() => new InMemoryEntityRepository($this->storage),
        );

        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'node',
            label: 'Node',
            class: Node::class,
            keys: [
                'id' => 'nid',
                'uuid' => 'uuid',
                'label' => 'title',
                'bundle' => 'type',
            ],
            // CW-v1 option-1 PR-4 (findings #1/#2): EntityWritePayloadGuard
            // requires a payload key to be a declared field (or a writable
            // entity key). Mirrors Node's real #[Field]-declared shape for
            // the two non-key fields this suite writes via attributes.
            _fieldDefinitions: [
                'type' => new FieldDefinition(name: 'type', type: 'string', read: \Waaseyaa\Entity\FieldReadLevel::Public),
                'status' => new FieldDefinition(name: 'status', type: 'boolean'),
                'uid' => new FieldDefinition(name: 'uid', type: 'entity_reference'),
            ],
        ));

        $this->accessHandler = new EntityAccessHandler([
            new NodeAccessPolicy(),
        ]);
        $this->fieldReadScope = new AccountFieldReadScope();
        EntityReadRuntime::installGuard(new FieldReadGuard(
            $this->fieldReadScope,
            $this->accessHandler->checkProtectedFieldRead(...),
        ));
    }

    protected function tearDown(): void
    {
        EntityReadRuntime::installGuard(null);
        EntityReadRuntime::installFieldRegistry(null);
    }

    private function buildController(AccountInterface $account): JsonApiController
    {
        $serializer = new ResourceSerializer($this->entityTypeManager);

        return new JsonApiController(
            $this->entityTypeManager,
            $serializer,
            $this->accessHandler,
            $account,
        );
    }

    private function buildControllerWithoutAccessCheck(): JsonApiController
    {
        $serializer = new ResourceSerializer($this->entityTypeManager);

        return new JsonApiController($this->entityTypeManager, $serializer);
    }

    private function readAs(AuthorizationPrincipalInterface $principal, callable $callback): mixed
    {
        return $this->fieldReadScope->run($principal, $callback);
    }

    private function seedPublishedNode(string $title, int $authorUid = 1, string $type = 'article'): Node
    {
        $node = new Node([
            'title' => $title,
            'type' => $type,
            'uid' => $authorUid,
            'status' => 1,
        ]);
        $this->storage->save($node);

        return $node;
    }

    private function seedUnpublishedNode(string $title, int $authorUid = 1, string $type = 'article'): Node
    {
        $node = new Node([
            'title' => $title,
            'type' => $type,
            'uid' => $authorUid,
            'status' => 0,
        ]);
        $this->storage->save($node);

        return $node;
    }

    #[Test]
    public function authenticatedUserWithAccessContentCanViewPublishedNode(): void
    {
        $node = $this->seedPublishedNode('Public Article');
        $user = AuthorizationPrincipalFactory::fromValues([
            'uid' => 10,
            'name' => 'reader',
            'permissions' => ['access content'],
            'roles' => ['authenticated'],
        ]);

        $controller = $this->buildController($user);
        $doc = $controller->show('node', $node->id());

        $this->assertSame(200, $doc->statusCode);
        $array = $doc->toArray();
        $this->assertSame('Public Article', $array['data']['attributes']['title']);
    }

    #[Test]
    public function anonymousUserCannotViewNodes(): void
    {
        $this->seedPublishedNode('Published Node');
        $anonymous = new AnonymousUser();

        $controller = $this->buildController($anonymous);
        $doc = $controller->show('node', 1);

        // FR-003 (#1649): a view-denied single read answers with the canonical
        // not-found shape — never a 403 — so it cannot act as an existence oracle.
        $this->assertSame(404, $doc->statusCode);
        $array = $doc->toArray();
        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('404', $array['errors'][0]['status']);
        $this->assertSame('Not Found', $array['errors'][0]['title']);
        $this->assertSame('ENTITY_NOT_FOUND', $array['errors'][0]['code']);
    }

    #[Test]
    public function userWithCreatePermissionCanStoreNewNode(): void
    {
        // CW-v1 WP-0: this account has no NodeAccessPolicy::PUBLISH_PERMISSION,
        // so it may no longer supply an explicit `status` attribute (that is
        // now edit-forbidden without the publish permission — see
        // createWithoutStatusDefaultsUnpublishedForAccountsThatMayNotPublish
        // below for the create-without-status floor this test used to cover
        // incidentally). Omit `status` here; this test's own point is that a
        // plain create-permission account can still create at all.
        $user = AuthorizationPrincipalFactory::fromValues([
            'uid' => 5,
            'name' => 'author',
            'permissions' => ['access content', 'create article content'],
            'roles' => ['authenticated'],
        ]);

        $controller = $this->buildController($user);
        $doc = $controller->store('node', [
            'data' => [
                'type' => 'node',
                'attributes' => [
                    'title' => 'New Article',
                    'type' => 'article',
                    'uid' => 5,
                ],
            ],
        ]);

        $this->assertSame(201, $doc->statusCode);
        $array = $doc->toArray();
        $this->assertSame('New Article', $array['data']['attributes']['title']);
    }

    #[Test]
    public function createWithoutStatusDefaultsUnpublishedForAccountsThatMayNotPublish(): void
    {
        // CW-v1 WP-0 (docs/specs/content-workflow.md): Node defaults `status`
        // to published (1) in its constructor when the caller omits it. An
        // account that may create but not edit `status`/`workflow_state` must
        // not get born-published content out of that constructor default.
        $author = AuthorizationPrincipalFactory::fromValues([
            'uid' => 5,
            'name' => 'author',
            'permissions' => ['access content', 'create article content'],
            'roles' => ['authenticated'],
        ]);

        $controller = $this->buildController($author);
        $doc = $controller->store('node', [
            'data' => [
                'type' => 'node',
                'attributes' => [
                    'title' => 'Draft by author',
                    'type' => 'article',
                    'uid' => 5,
                    // No 'status' attribute supplied.
                ],
            ],
        ]);

        $this->assertSame(201, $doc->statusCode);
        $uuid = $doc->toArray()['data']['id'];
        $stored = $this->storage->load($this->findNodeIdByUuid($uuid));
        $this->assertFalse(new NodeAuthorizationSnapshotReader()->read($stored)->published);
    }

    #[Test]
    public function createWithoutStatusDefaultsToDraftEvenForPublishers(): void
    {
        $publisher = AuthorizationPrincipalFactory::fromValues([
            'uid' => 6,
            'name' => 'editor',
            'permissions' => ['access content', 'create article content', NodeAccessPolicy::PUBLISH_PERMISSION],
            'roles' => ['authenticated'],
        ]);

        $controller = $this->buildController($publisher);
        $doc = $controller->store('node', [
            'data' => [
                'type' => 'node',
                'attributes' => [
                    'title' => 'Draft by editor',
                    'type' => 'article',
                    'uid' => 6,
                    // No 'status' attribute supplied.
                ],
            ],
        ]);

        $this->assertSame(201, $doc->statusCode);
        $uuid = $doc->toArray()['data']['id'];
        $stored = $this->storage->load($this->findNodeIdByUuid($uuid));
        $this->assertFalse(new NodeAuthorizationSnapshotReader()->read($stored)->published);
    }

    #[Test]
    public function userWithoutCreatePermissionGets403OnStore(): void
    {
        $user = AuthorizationPrincipalFactory::fromValues([
            'uid' => 5,
            'name' => 'reader',
            'permissions' => ['access content'],
            'roles' => ['authenticated'],
        ]);

        $controller = $this->buildController($user);
        $doc = $controller->store('node', [
            'data' => [
                'type' => 'node',
                'attributes' => [
                    'title' => 'Unauthorized Creation',
                    'type' => 'article',
                ],
            ],
        ]);

        $this->assertSame(403, $doc->statusCode);
    }

    #[Test]
    public function userWithEditOwnPermissionCanUpdateOwnNode(): void
    {
        $node = $this->seedPublishedNode('Own Article', 5, 'article');
        $user = AuthorizationPrincipalFactory::fromValues([
            'uid' => 5,
            'name' => 'author',
            'permissions' => ['access content', 'edit own article content'],
            'roles' => ['authenticated'],
        ]);

        $controller = $this->buildController($user);
        $doc = $controller->update('node', $node->id(), [
            'data' => [
                'type' => 'node',
                'id' => $node->uuid(),
                'attributes' => [
                    'title' => 'Updated Own Article',
                ],
            ],
        ]);

        $this->assertSame(200, $doc->statusCode);
        $array = $doc->toArray();
        $this->assertSame('Updated Own Article', $array['data']['attributes']['title']);
    }

    #[Test]
    public function editAnyCanUpdateUidlessDraftByNumericIdAndUuidWithoutViewAccess(): void
    {
        // Gap 1 (#2078): mutation identity resolution must not inherit the
        // query layer's view filter. This draft has no uid, so it cannot be
        // "own unpublished" for any principal. The editor deliberately has
        // edit-any but no unpublished-view grant.
        $node = new Node([
            'title' => 'Uid-less draft',
            'type' => 'article',
            'status' => 0,
        ]);
        $this->storage->save($node);

        $editor = AuthorizationPrincipalFactory::fromValues([
            'uid' => 5,
            'name' => 'editor',
            'permissions' => ['access content', 'edit any article content'],
            'roles' => ['authenticated'],
        ]);
        $controller = $this->buildController($editor);

        $numeric = $controller->update('node', $node->id(), [
            'data' => [
                'type' => 'node',
                'id' => $node->uuid(),
                'attributes' => ['title' => 'Updated by numeric ID'],
            ],
        ]);
        $this->assertSame(200, $numeric->statusCode);

        $uuid = $controller->update('node', $node->uuid(), [
            'data' => [
                'type' => 'node',
                'id' => $node->uuid(),
                'attributes' => ['title' => 'Updated by UUID'],
            ],
        ]);
        $this->assertSame(200, $uuid->statusCode);
        $this->assertSame('Updated by UUID', $this->storage->load($node->id())?->label());
        $this->assertSame([false], $this->storage->lastQuery?->accessChecks);
        $this->assertNull($this->storage->lastQuery?->boundAccount);
    }

    #[Test]
    public function uidlessDraftStillRequiresViewAccessForNumericAndUuidReads(): void
    {
        $node = new Node([
            'title' => 'Hidden uid-less draft',
            'type' => 'article',
            'status' => 0,
        ]);
        $this->storage->save($node);

        $editor = AuthorizationPrincipalFactory::fromValues([
            'uid' => 5,
            'name' => 'editor',
            'permissions' => ['access content', 'edit any article content'],
            'roles' => ['authenticated'],
        ]);
        $controller = $this->buildController($editor);

        $this->assertSame(404, $controller->show('node', $node->id())->statusCode);
        $this->assertSame(404, $controller->show('node', $node->uuid())->statusCode);
    }

    #[Test]
    public function authorCannotReassignAuthorshipOfOwnNodeViaUpdate(): void
    {
        // An author may edit their own node's content, but must not mutate the
        // system field `uid` (authorship) on an existing node — that requires
        // `administer nodes`. Before NodeAccessPolicy gained field-level access,
        // this PATCH succeeded (mass-assignment).
        $node = $this->seedPublishedNode('Own Article', 5, 'article');
        $user = AuthorizationPrincipalFactory::fromValues([
            'uid' => 5,
            'name' => 'author',
            'permissions' => ['access content', 'edit own article content'],
            'roles' => ['authenticated'],
        ]);

        $controller = $this->buildController($user);
        $doc = $controller->update('node', $node->id(), [
            'data' => [
                'type' => 'node',
                'id' => $node->uuid(),
                'attributes' => [
                    'uid' => 99,
                ],
            ],
        ]);

        $this->assertSame(403, $doc->statusCode);
    }

    #[Test]
    public function authorCannotChangeBundleOfOwnNodeViaUpdate(): void
    {
        // `type` is the readOnly bundle; a non-admin must not change it on an
        // existing node (it can move the node to a bundle with different field
        // access / visibility rules).
        $node = $this->seedPublishedNode('Own Article', 5, 'article');
        $user = AuthorizationPrincipalFactory::fromValues([
            'uid' => 5,
            'name' => 'author',
            'permissions' => ['access content', 'edit own article content'],
            'roles' => ['authenticated'],
        ]);

        $controller = $this->buildController($user);
        $doc = $controller->update('node', $node->id(), [
            'data' => [
                'type' => 'node',
                'id' => $node->uuid(),
                'attributes' => [
                    'type' => 'page',
                ],
            ],
        ]);

        $this->assertSame(422, $doc->statusCode);
    }

    #[Test]
    public function userCannotUpdateOtherUsersNodes(): void
    {
        $node = $this->seedPublishedNode('Other Author Article', 99, 'article');
        $user = AuthorizationPrincipalFactory::fromValues([
            'uid' => 5,
            'name' => 'limited_user',
            'permissions' => ['access content', 'edit own article content'],
            'roles' => ['authenticated'],
        ]);

        $controller = $this->buildController($user);
        $doc = $controller->update('node', $node->id(), [
            'data' => [
                'type' => 'node',
                'id' => $node->uuid(),
                'attributes' => [
                    'title' => 'Should Fail',
                ],
            ],
        ]);

        $this->assertSame(403, $doc->statusCode);
    }

    #[Test]
    public function adminCanPerformAllOperations(): void
    {
        $admin = AuthorizationPrincipalFactory::fromValues([
            'uid' => 1,
            'name' => 'admin',
            'permissions' => ['administer nodes'],
            'roles' => ['administrator'],
        ]);

        $controller = $this->buildController($admin);

        // Store.
        $storeDoc = $this->readAs($admin, fn() => $controller->store('node', [
            'data' => [
                'type' => 'node',
                'attributes' => [
                    'title' => 'Admin Article',
                    'type' => 'article',
                    'uid' => 1,
                    'status' => 1,
                ],
            ],
        ]));
        $this->assertSame(201, $storeDoc->statusCode);
        $storeArray = $storeDoc->toArray();
        $uuid = $storeArray['data']['id'];
        $nodeId = $this->findNodeIdByUuid($uuid);

        // Show.
        $showDoc = $this->readAs($admin, fn() => $controller->show('node', $nodeId));
        $this->assertSame(200, $showDoc->statusCode);

        // Update.
        $updateDoc = $this->readAs($admin, fn() => $controller->update('node', $nodeId, [
            'data' => [
                'type' => 'node',
                'id' => $uuid,
                'attributes' => [
                    'title' => 'Admin Updated',
                ],
            ],
        ]));
        $this->assertSame(200, $updateDoc->statusCode);

        // Delete.
        $deleteDoc = $controller->destroy('node', $nodeId);
        $this->assertSame(204, $deleteDoc->statusCode);
    }

    #[Test]
    public function adminCanViewUnpublishedNodes(): void
    {
        $node = $this->seedUnpublishedNode('Draft Article', 99);
        $admin = AuthorizationPrincipalFactory::fromValues([
            'uid' => 1,
            'name' => 'admin',
            'permissions' => ['administer nodes'],
            'roles' => ['administrator'],
        ]);

        $controller = $this->buildController($admin);
        $doc = $this->readAs($admin, fn() => $controller->show('node', $node->id()));

        $this->assertSame(200, $doc->statusCode);
    }

    #[Test]
    public function regularUserCannotViewUnpublishedNodes(): void
    {
        $node = $this->seedUnpublishedNode('Draft Article', 99);
        $user = AuthorizationPrincipalFactory::fromValues([
            'uid' => 10,
            'name' => 'reader',
            'permissions' => ['access content'],
            'roles' => ['authenticated'],
        ]);

        $controller = $this->buildController($user);
        $doc = $controller->show('node', $node->id());

        // FR-003 (#1649): denied view reads return the not-found shape.
        $this->assertSame(404, $doc->statusCode);
    }

    #[Test]
    public function authorCanViewOwnUnpublishedNode(): void
    {
        $node = $this->seedUnpublishedNode('My Draft', 5);
        $author = AuthorizationPrincipalFactory::fromValues([
            'uid' => 5,
            'name' => 'author',
            'permissions' => ['access content', 'view own unpublished content'],
            'roles' => ['authenticated'],
        ]);

        $controller = $this->buildController($author);
        $doc = $controller->show('node', $node->id());

        $this->assertSame(200, $doc->statusCode);
    }

    #[Test]
    public function indexFiltersOutInaccessibleEntities(): void
    {
        // Create published and unpublished nodes.
        $this->seedPublishedNode('Published One', 1);
        $this->seedPublishedNode('Published Two', 1);
        $this->seedUnpublishedNode('Draft One', 99);
        $this->seedUnpublishedNode('Draft Two', 99);

        $reader = AuthorizationPrincipalFactory::fromValues([
            'uid' => 10,
            'name' => 'reader',
            'permissions' => ['access content'],
            'roles' => ['authenticated'],
        ]);

        $controller = $this->buildController($reader);
        $doc = $controller->index('node');

        $this->assertSame(200, $doc->statusCode);
        $array = $doc->toArray();

        // Reader can only see published nodes (access filtered).
        $this->assertCount(2, $array['data']);

        $titles = array_map(fn($r) => $r['attributes']['title'], $array['data']);
        $this->assertContains('Published One', $titles);
        $this->assertContains('Published Two', $titles);
    }

    #[Test]
    public function indexReturnsEmptyDataNotForbiddenForNoAccessible(): void
    {
        // Create only unpublished nodes.
        $this->seedUnpublishedNode('Draft One', 99);
        $this->seedUnpublishedNode('Draft Two', 99);

        $reader = AuthorizationPrincipalFactory::fromValues([
            'uid' => 10,
            'name' => 'reader',
            'permissions' => ['access content'],
            'roles' => ['authenticated'],
        ]);

        $controller = $this->buildController($reader);
        $doc = $controller->index('node');

        // Should return 200 with empty data, not 403.
        $this->assertSame(200, $doc->statusCode);
        $array = $doc->toArray();
        $this->assertCount(0, $array['data']);
    }

    #[Test]
    public function userWithDeleteOwnPermissionCanDeleteOwnNode(): void
    {
        $node = $this->seedPublishedNode('My Article', 5, 'article');
        $user = AuthorizationPrincipalFactory::fromValues([
            'uid' => 5,
            'name' => 'author',
            'permissions' => ['access content', 'delete own article content'],
            'roles' => ['authenticated'],
        ]);

        $controller = $this->buildController($user);
        $doc = $controller->destroy('node', $node->id());

        $this->assertSame(204, $doc->statusCode);
    }

    #[Test]
    public function userCannotDeleteOtherUsersNodes(): void
    {
        $node = $this->seedPublishedNode('Other Article', 99, 'article');
        $user = AuthorizationPrincipalFactory::fromValues([
            'uid' => 5,
            'name' => 'limited_user',
            'permissions' => ['access content', 'delete own article content'],
            'roles' => ['authenticated'],
        ]);

        $controller = $this->buildController($user);
        $doc = $controller->destroy('node', $node->id());

        $this->assertSame(403, $doc->statusCode);
    }

    #[Test]
    public function controllerWithoutAccessCheckAllowsAllOperations(): void
    {
        $controller = $this->buildControllerWithoutAccessCheck();

        // Store.
        $storeDoc = $controller->store('node', [
            'data' => [
                'type' => 'node',
                'attributes' => [
                    'title' => 'No Access Check',
                    'type' => 'article',
                    'uid' => 0,
                    'status' => 0,
                ],
            ],
        ]);
        $this->assertSame(201, $storeDoc->statusCode);

        // Show (even unpublished, even without permissions).
        $showDoc = $controller->show('node', 1);
        $this->assertSame(200, $showDoc->statusCode);
    }

    private function findNodeIdByUuid(string $uuid): int|string
    {
        $entities = $this->storage->loadMultiple();
        foreach ($entities as $entity) {
            if ($entity->uuid() === $uuid || (string) $entity->id() === $uuid) {
                return $entity->id();
            }
        }
        throw new \RuntimeException("Node with UUID {$uuid} not found.");
    }
}

/**
 * In-memory storage that creates Node entities (instead of TestEntity).
 */
class NodeInMemoryStorage extends InMemoryEntityStorage
{
    /** @var array<int|string, Node> */
    private array $nodes = [];
    private int $nextId = 1;
    public ?ViewFilteredNodeQuery $lastQuery = null;

    public function create(array $values = []): Node
    {
        return new Node($values);
    }

    public function load(int|string $id): ?Node
    {
        return $this->nodes[$id] ?? null;
    }

    public function loadMultiple(array $ids = []): array
    {
        if ($ids === []) {
            return $this->nodes;
        }

        $result = [];
        foreach ($ids as $id) {
            if (isset($this->nodes[$id])) {
                $result[$id] = $this->nodes[$id];
            }
        }

        return $result;
    }

    public function getQuery(): EntityQueryInterface
    {
        return $this->lastQuery = new ViewFilteredNodeQuery(array_keys($this->nodes), $this->nodes);
    }

    public function save(\Waaseyaa\Entity\EntityInterface $entity): int
    {
        $isNew = $entity->isNew();

        if ($isNew) {
            $id = $this->nextId++;
            $entity->set('nid', $id);
            $entity->enforceIsNew(false);
        }

        $this->nodes[$entity->id()] = $entity;

        return $isNew ? 1 : 2;
    }

    public function delete(array $entities): void
    {
        foreach ($entities as $entity) {
            unset($this->nodes[$entity->id()]);
        }
    }

    public function getEntityTypeId(): string
    {
        return 'node';
    }
}

/**
 * Query double that models the production query-layer view filter: binding an
 * account hides this fixture's unpublished, uid-less node, while the explicit
 * system-context bypass returns its identity for caller-specific authorization.
 */
final class ViewFilteredNodeQuery extends \Waaseyaa\Api\Tests\Fixtures\InMemoryEntityQuery
{
    /** @var list<bool> */
    public array $accessChecks = [];
    public ?AccountInterface $boundAccount = null;
    private bool $viewFiltered = false;
    private bool $uuidCondition = false;

    public function condition(string $field, mixed $value, string $operator = '='): static
    {
        $this->uuidCondition = $field === 'uuid';

        return parent::condition($field, $value, $operator);
    }

    public function accessCheck(bool $check = true): static
    {
        $this->accessChecks[] = $check;
        $this->viewFiltered = $check;

        return $this;
    }

    public function setAccount(?AccountInterface $account): static
    {
        $this->boundAccount = $account;
        $this->viewFiltered = true;

        return $this;
    }

    public function execute(): array
    {
        return $this->viewFiltered && $this->uuidCondition ? [] : parent::execute();
    }
}
