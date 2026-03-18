<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase15;

require_once __DIR__ . '/../../../packages/relationship/src/RelationshipTraversalService.php';
require_once __DIR__ . '/../../../packages/relationship/src/Relationship.php';
require_once __DIR__ . '/../../../packages/relationship/src/RelationshipSchemaManager.php';

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Vector\SearchController;
use Waaseyaa\AI\Vector\SqliteEmbeddingStorage;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Mcp\McpController;
use Waaseyaa\Relationship\Relationship;
use Waaseyaa\Relationship\RelationshipSchemaManager;
use Waaseyaa\Relationship\RelationshipTraversalService;

#[CoversNothing]
final class AccessVisibilityConsistencyIntegrationTest extends TestCase
{
    #[Test]
    public function reviewStateIsHiddenAcrossSearchMcpAndRelationshipBrowseEvenWhenStatusIsTruthy(): void
    {
        $database = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();

        $manager = new EntityTypeManager(
            $dispatcher,
            function (EntityType $definition) use ($dispatcher, $database): SqlEntityStorage {
                $schema = new SqlSchemaHandler($definition, $database);
                $schema->ensureTable();
                return new SqlEntityStorage($definition, $database, $dispatcher);
            },
        );

        $manager->registerEntityType(new EntityType(
            id: 'node',
            label: 'Node',
            class: TestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
            fieldDefinitions: [
                'title' => ['type' => 'string'],
                'body' => ['type' => 'text'],
                'status' => ['type' => 'string'],
                'workflow_state' => ['type' => 'string'],
            ],
        ));
        $manager->registerEntityType(new EntityType(
            id: 'relationship',
            label: 'Relationship',
            class: Relationship::class,
            keys: ['id' => 'rid', 'uuid' => 'uuid', 'label' => 'relationship_type', 'bundle' => 'relationship_type'],
            fieldDefinitions: [
                'relationship_type' => ['type' => 'string'],
                'from_entity_type' => ['type' => 'string'],
                'from_entity_id' => ['type' => 'string'],
                'to_entity_type' => ['type' => 'string'],
                'to_entity_id' => ['type' => 'string'],
                'status' => ['type' => 'integer'],
            ],
        ));
        $manager->getStorage('relationship');
        (new RelationshipSchemaManager($database))->ensure();

        $nodeStorage = $manager->getStorage('node');
        $anchor = $nodeStorage->create([
            'title' => 'Published Anchor',
            'body' => 'anchor context',
            'type' => 'teaching',
            'status' => 1,
            'workflow_state' => 'published',
        ]);
        $nodeStorage->save($anchor);

        $review = $nodeStorage->create([
            'title' => 'Confidential Review Draft',
            'body' => 'sensitive content',
            'type' => 'teaching',
            'status' => 'published',
            'workflow_state' => 'review',
        ]);
        $nodeStorage->save($review);

        $relationshipStorage = $manager->getStorage('relationship');
        $relationship = $relationshipStorage->create([
            'relationship_type' => 'related',
            'from_entity_type' => 'node',
            'from_entity_id' => (string) $anchor->id(),
            'to_entity_type' => 'node',
            'to_entity_id' => (string) $review->id(),
            'status' => 1,
        ]);
        $relationshipStorage->save($relationship);

        $accessHandler = new EntityAccessHandler([
            new AllowAllNodePolicy(),
            new AllowAllRelationshipPolicy(),
        ]);
        $account = new PublicAuditAccount();
        $serializer = new ResourceSerializer($manager);
        $embeddingStorage = new SqliteEmbeddingStorage($database->getConnection()->getNativeConnection());

        $search = new SearchController(
            entityTypeManager: $manager,
            serializer: $serializer,
            embeddingStorage: $embeddingStorage,
            embeddingProvider: null,
            accessHandler: $accessHandler,
            account: $account,
        );
        $searchPayload = $search->search('Confidential', 'node', 10)->toArray();
        $this->assertSame('keyword', $searchPayload['meta']['mode']);
        $this->assertCount(0, $searchPayload['data']);

        $mcp = new McpController(
            entityTypeManager: $manager,
            serializer: $serializer,
            accessHandler: $accessHandler,
            account: $account,
            embeddingStorage: $embeddingStorage,
            embeddingProvider: null,
        );
        $rpc = $mcp->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 90,
            'method' => 'tools/call',
            'params' => [
                'name' => 'ai_discover',
                'arguments' => [
                    'query' => 'Confidential',
                    'type' => 'node',
                    'anchor_type' => 'node',
                    'anchor_id' => (string) $anchor->id(),
                ],
            ],
        ]);
        $payload = json_decode((string) $rpc['result']['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $payload['meta']['count']);

        $traversal = new RelationshipTraversalService($manager, $database);
        $browse = $traversal->browse('node', (string) $anchor->id(), ['status' => 'published']);
        $this->assertSame([], $browse['outbound']);
        $this->assertSame([], $browse['inbound']);
    }
}

final class PublicAuditAccount implements AccountInterface
{
    public function id(): int|string
    {
        return 0;
    }
    public function hasPermission(string $permission): bool
    {
        return false;
    }
    public function getRoles(): array
    {
        return ['anonymous'];
    }
    public function isAuthenticated(): bool
    {
        return false;
    }
}

final class AllowAllNodePolicy implements AccessPolicyInterface
{
    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'node';
    }
    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        return AccessResult::allowed('audit');
    }
    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::allowed('audit');
    }
}

final class AllowAllRelationshipPolicy implements AccessPolicyInterface
{
    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'relationship';
    }
    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        return AccessResult::allowed('audit');
    }
    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::allowed('audit');
    }
}
