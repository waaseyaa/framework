<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase7;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Node\Node;
use Waaseyaa\Node\NodeAccessPolicy;
use Waaseyaa\User\AnonymousUser;
use Waaseyaa\User\User;

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

    protected function setUp(): void
    {
        $this->storage = new NodeInMemoryStorage('node');

        $this->entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            fn() => $this->storage,
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
        ));

        $this->accessHandler = new EntityAccessHandler([
            new NodeAccessPolicy(),
        ]);
    }

    private function buildController(User|AnonymousUser $account): JsonApiController
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
        $user = new User([
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

        $this->assertSame(403, $doc->statusCode);
        $array = $doc->toArray();
        $this->assertArrayHasKey('errors', $array);
        $this->assertSame('403', $array['errors'][0]['status']);
        $this->assertSame('Forbidden', $array['errors'][0]['title']);
    }

    #[Test]
    public function userWithCreatePermissionCanStoreNewNode(): void
    {
        $user = new User([
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
                    'status' => 1,
                    'bundle' => 'article',
                ],
            ],
        ]);

        $this->assertSame(201, $doc->statusCode);
        $array = $doc->toArray();
        $this->assertSame('New Article', $array['data']['attributes']['title']);
    }

    #[Test]
    public function userWithoutCreatePermissionGets403OnStore(): void
    {
        $user = new User([
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
                    'bundle' => 'article',
                ],
            ],
        ]);

        $this->assertSame(403, $doc->statusCode);
    }

    #[Test]
    public function userWithEditOwnPermissionCanUpdateOwnNode(): void
    {
        $node = $this->seedPublishedNode('Own Article', 5, 'article');
        $user = new User([
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
    public function userCannotUpdateOtherUsersNodes(): void
    {
        $node = $this->seedPublishedNode('Other Author Article', 99, 'article');
        $user = new User([
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
        $admin = new User([
            'uid' => 1,
            'name' => 'admin',
            'permissions' => ['administer nodes'],
            'roles' => ['administrator'],
        ]);

        $controller = $this->buildController($admin);

        // Store.
        $storeDoc = $controller->store('node', [
            'data' => [
                'type' => 'node',
                'attributes' => [
                    'title' => 'Admin Article',
                    'type' => 'article',
                    'uid' => 1,
                    'status' => 1,
                    'bundle' => 'article',
                ],
            ],
        ]);
        $this->assertSame(201, $storeDoc->statusCode);
        $storeArray = $storeDoc->toArray();
        $uuid = $storeArray['data']['id'];
        $nodeId = $this->findNodeIdByUuid($uuid);

        // Show.
        $showDoc = $controller->show('node', $nodeId);
        $this->assertSame(200, $showDoc->statusCode);

        // Update.
        $updateDoc = $controller->update('node', $nodeId, [
            'data' => [
                'type' => 'node',
                'id' => $uuid,
                'attributes' => [
                    'title' => 'Admin Updated',
                ],
            ],
        ]);
        $this->assertSame(200, $updateDoc->statusCode);

        // Delete.
        $deleteDoc = $controller->destroy('node', $nodeId);
        $this->assertSame(204, $deleteDoc->statusCode);
    }

    #[Test]
    public function adminCanViewUnpublishedNodes(): void
    {
        $node = $this->seedUnpublishedNode('Draft Article', 99);
        $admin = new User([
            'uid' => 1,
            'name' => 'admin',
            'permissions' => ['administer nodes'],
            'roles' => ['administrator'],
        ]);

        $controller = $this->buildController($admin);
        $doc = $controller->show('node', $node->id());

        $this->assertSame(200, $doc->statusCode);
    }

    #[Test]
    public function regularUserCannotViewUnpublishedNodes(): void
    {
        $node = $this->seedUnpublishedNode('Draft Article', 99);
        $user = new User([
            'uid' => 10,
            'name' => 'reader',
            'permissions' => ['access content'],
            'roles' => ['authenticated'],
        ]);

        $controller = $this->buildController($user);
        $doc = $controller->show('node', $node->id());

        $this->assertSame(403, $doc->statusCode);
    }

    #[Test]
    public function authorCanViewOwnUnpublishedNode(): void
    {
        $node = $this->seedUnpublishedNode('My Draft', 5);
        $author = new User([
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

        $reader = new User([
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

        $reader = new User([
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
        $user = new User([
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
        $user = new User([
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
            if ($entity->uuid() === $uuid) {
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

    public function getQuery(): \Waaseyaa\Entity\Storage\EntityQueryInterface
    {
        return new \Waaseyaa\Api\Tests\Fixtures\InMemoryEntityQuery(
            array_keys($this->nodes),
            $this->nodes,
        );
    }

    public function getEntityTypeId(): string
    {
        return 'node';
    }
}
