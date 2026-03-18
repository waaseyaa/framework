<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase15;

require_once __DIR__ . '/../../../packages/relationship/src/RelationshipTraversalService.php';
require_once __DIR__ . '/../../../packages/relationship/src/RelationshipDiscoveryService.php';
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
use Waaseyaa\AI\Vector\SemanticIndexWarmer;
use Waaseyaa\AI\Vector\SqliteEmbeddingStorage;
use Waaseyaa\AI\Vector\Testing\FakeEmbeddingProvider;
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
use Waaseyaa\Relationship\RelationshipDiscoveryService;
use Waaseyaa\Relationship\RelationshipSchemaManager;
use Waaseyaa\Relationship\RelationshipTraversalService;
use Waaseyaa\Tests\Support\WorkflowFixturePack;

#[CoversNothing]
final class SemanticWarmBaselineIntegrationTest extends TestCase
{
    private const string BASELINE_ARTIFACT = __DIR__ . '/../../Baselines/performance_regression_v1.1.json';

    private DBALDatabase $database;
    private EntityTypeManager $entityTypeManager;
    private ResourceSerializer $serializer;
    private EntityAccessHandler $accessHandler;
    private AccountInterface $account;
    private SqliteEmbeddingStorage $embeddingStorage;

    /** @var array<string, int|string> */
    private array $nodeIdsByFixtureKey = [];

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();

        $this->entityTypeManager = new EntityTypeManager(
            $dispatcher,
            function (EntityType $definition) use ($dispatcher): SqlEntityStorage {
                $schema = new SqlSchemaHandler($definition, $this->database);
                $schema->ensureTable();
                return new SqlEntityStorage($definition, $this->database, $dispatcher);
            },
        );

        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'node',
            label: 'Node',
            class: TestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
            fieldDefinitions: [
                'title' => ['type' => 'string'],
                'body' => ['type' => 'text'],
                'status' => ['type' => 'boolean'],
                'workflow_state' => ['type' => 'string'],
            ],
        ));
        $this->entityTypeManager->registerEntityType(new EntityType(
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
                'status' => ['type' => 'boolean'],
                'start_date' => ['type' => 'integer'],
                'end_date' => ['type' => 'integer'],
            ],
        ));

        (new RelationshipSchemaManager($this->database))->ensure();

        $this->serializer = new ResourceSerializer($this->entityTypeManager);
        $this->accessHandler = new EntityAccessHandler([
            new BaselineNodeViewPolicy(),
            new BaselineRelationshipViewPolicy(),
        ]);
        $this->account = new BaselineAnonymousAccount();
        $this->embeddingStorage = new SqliteEmbeddingStorage($this->database->getConnection()->getNativeConnection());

        $this->seedFixtureCorpus();
    }

    #[Test]
    public function warmFlowAndReadPathBaselineRemainStable(): void
    {
        $provider = new FakeEmbeddingProvider(64);
        $warmer = new SemanticIndexWarmer(
            entityTypeManager: $this->entityTypeManager,
            embeddingStorage: $this->embeddingStorage,
            embeddingProvider: $provider,
        );

        $warmStarted = hrtime(true);
        $warmReport = $warmer->warm(['node']);
        $warmDurationMs = $this->durationMs($warmStarted);

        $searchController = new SearchController(
            entityTypeManager: $this->entityTypeManager,
            serializer: $this->serializer,
            embeddingStorage: $this->embeddingStorage,
            embeddingProvider: $provider,
            accessHandler: $this->accessHandler,
            account: $this->account,
        );

        $semanticSearchStarted = hrtime(true);
        $semanticSearch = $searchController->search('water', 'node', 10)->toArray();
        $semanticSearchDurationMs = $this->durationMs($semanticSearchStarted);

        $keywordSearch = (new SearchController(
            entityTypeManager: $this->entityTypeManager,
            serializer: $this->serializer,
            embeddingStorage: $this->embeddingStorage,
            embeddingProvider: null,
            accessHandler: $this->accessHandler,
            account: $this->account,
        ))->search('water', 'node', 10)->toArray();

        $mcp = new McpController(
            entityTypeManager: $this->entityTypeManager,
            serializer: $this->serializer,
            accessHandler: $this->accessHandler,
            account: $this->account,
            embeddingStorage: $this->embeddingStorage,
            embeddingProvider: $provider,
        );

        $anchorId = (string) $this->nodeIdsByFixtureKey['anchor_water'];
        $traversal = new RelationshipTraversalService($this->entityTypeManager, $this->database);
        $discovery = new RelationshipDiscoveryService($traversal);

        $ssrNavigationStarted = hrtime(true);
        $ssrNavigation = $traversal->browse('node', $anchorId, [
            'status' => 'published',
            'limit' => 25,
        ]);
        $ssrNavigationDurationMs = $this->durationMs($ssrNavigationStarted);

        $discoveryStarted = hrtime(true);
        $topicHub = $discovery->topicHub('node', $anchorId, [
            'status' => 'published',
            'limit' => 20,
        ]);
        $discoveryDurationMs = $this->durationMs($discoveryStarted);

        $mcpStarted = hrtime(true);
        $mcpResult = $mcp->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 400,
            'method' => 'tools/call',
            'params' => [
                'name' => 'ai_discover',
                'arguments' => [
                    'query' => 'water',
                    'type' => 'node',
                    'limit' => 10,
                    'anchor_type' => 'node',
                    'anchor_id' => $anchorId,
                ],
            ],
        ]);
        $mcpDurationMs = $this->durationMs($mcpStarted);

        $mcpPayload = json_decode((string) $mcpResult['result']['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);

        $snapshot = [
            'warm' => [
                'status' => $warmReport['status'],
                'processed_total' => $warmReport['processed_total'],
                'stored_total' => $warmReport['stored_total'],
                'removed_total' => $warmReport['removed_total'],
                'by_type' => $warmReport['by_type'],
            ],
            'semantic_search' => [
                'mode' => $semanticSearch['meta']['mode'] ?? 'unknown',
                'count' => count($semanticSearch['data'] ?? []),
                'titles' => $this->resourceTitles($semanticSearch['data'] ?? []),
                'contract_version' => $semanticSearch['meta']['contract_version'] ?? null,
            ],
            'keyword_search' => [
                'mode' => $keywordSearch['meta']['mode'] ?? 'unknown',
                'count' => count($keywordSearch['data'] ?? []),
                'titles' => $this->resourceTitles($keywordSearch['data'] ?? []),
            ],
            'ssr_navigation' => [
                'outbound' => (int) ($ssrNavigation['counts']['outbound'] ?? 0),
                'inbound' => (int) ($ssrNavigation['counts']['inbound'] ?? 0),
                'total' => (int) ($ssrNavigation['counts']['total'] ?? 0),
                'first_outbound_relationship_type' => (string) ($ssrNavigation['outbound'][0]['relationship_type'] ?? ''),
            ],
            'discovery_hub' => [
                'total' => (int) ($topicHub['page']['total'] ?? 0),
                'count' => (int) ($topicHub['page']['count'] ?? 0),
                'first_item_relationship_type' => (string) ($topicHub['items'][0]['relationship_type'] ?? ''),
            ],
            'mcp_ai_discover' => [
                'count' => count($mcpPayload['data']['recommendations'] ?? []),
                'titles' => $this->recommendationTitles($mcpPayload['data']['recommendations'] ?? []),
                'mode' => $mcpPayload['meta']['mode'] ?? null,
                'contract_version' => $mcpPayload['meta']['contract_version'] ?? null,
                'graph_total' => $mcpPayload['data']['graph_context']['counts']['total'] ?? null,
            ],
        ];

        $snapshotHash = sha1((string) json_encode($snapshot, JSON_THROW_ON_ERROR));
        $baseline = $this->loadBaselineContract();
        if ((string) getenv('WAASEYAA_UPDATE_PERF_BASELINE') === '1') {
            $baseline['snapshot_hash'] = $snapshotHash;
            $this->writeBaselineContract($baseline);
        }

        $this->assertSame(
            $baseline['snapshot_hash'],
            $snapshotHash,
            sprintf('performance baseline drift detected (expected %s, got %s)', $baseline['snapshot_hash'], $snapshotHash),
        );

        $durations = [
            'warm' => $warmDurationMs,
            'semantic_search' => $semanticSearchDurationMs,
            'ssr_navigation' => $ssrNavigationDurationMs,
            'discovery_hub' => $discoveryDurationMs,
            'mcp_ai_discover' => $mcpDurationMs,
        ];

        foreach ($durations as $surface => $durationMs) {
            $threshold = (float) ($baseline['thresholds_ms'][$surface] ?? 0.0);
            $this->assertGreaterThan(
                0.0,
                $threshold,
                sprintf('missing latency threshold for surface "%s"', $surface),
            );
            $this->assertLessThanOrEqual(
                $threshold,
                $durationMs,
                sprintf('%s latency budget drifted: %.3fms > %.3fms', $surface, $durationMs, $threshold),
            );
        }
    }

    private function seedFixtureCorpus(): void
    {
        $nodeStorage = $this->entityTypeManager->getStorage('node');
        foreach (WorkflowFixturePack::discoveryNodes() as $key => $values) {
            $entity = $nodeStorage->create($values);
            $nodeStorage->save($entity);
            $this->nodeIdsByFixtureKey[$key] = $entity->id();
        }

        $relationshipStorage = $this->entityTypeManager->getStorage('relationship');
        (new RelationshipSchemaManager($this->database))->ensure();
        foreach (WorkflowFixturePack::discoveryRelationships() as $fixture) {
            $relationship = $relationshipStorage->create([
                'relationship_type' => $fixture['relationship_type'],
                'from_entity_type' => 'node',
                'from_entity_id' => (string) $this->nodeIdsByFixtureKey[$fixture['from']],
                'to_entity_type' => 'node',
                'to_entity_id' => (string) $this->nodeIdsByFixtureKey[$fixture['to']],
                'status' => $fixture['status'],
                'start_date' => $fixture['start_date'],
                'end_date' => $fixture['end_date'],
            ]);
            $relationshipStorage->save($relationship);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $resources
     * @return list<string>
     */
    private function resourceTitles(array $resources): array
    {
        $titles = [];
        foreach ($resources as $resource) {
            $attributes = is_array($resource['attributes'] ?? null) ? $resource['attributes'] : [];
            if (is_string($attributes['title'] ?? null)) {
                $titles[] = $attributes['title'];
            }
        }

        return $titles;
    }

    /**
     * @param array<int, array<string, mixed>> $recommendations
     * @return list<string>
     */
    private function recommendationTitles(array $recommendations): array
    {
        $titles = [];
        foreach ($recommendations as $row) {
            $entity = is_array($row['entity'] ?? null) ? $row['entity'] : [];
            $attributes = is_array($entity['attributes'] ?? null) ? $entity['attributes'] : [];
            if (is_string($attributes['title'] ?? null)) {
                $titles[] = $attributes['title'];
            }
        }

        return $titles;
    }

    private function durationMs(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 3);
    }

    /**
     * @return array{
     *   contract_version: string,
     *   surface: string,
     *   snapshot_hash: string,
     *   thresholds_ms: array{
     *     warm: float|int,
     *     semantic_search: float|int,
     *     ssr_navigation: float|int,
     *     discovery_hub: float|int,
     *     mcp_ai_discover: float|int
     *   }
     * }
     */
    private function loadBaselineContract(): array
    {
        if (!is_file(self::BASELINE_ARTIFACT)) {
            $this->fail(sprintf('Missing baseline artifact: %s', self::BASELINE_ARTIFACT));
        }

        $raw = file_get_contents(self::BASELINE_ARTIFACT);
        $this->assertIsString($raw, 'Failed reading performance baseline artifact.');

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded, 'Invalid performance baseline artifact shape.');

        return $decoded;
    }

    /**
     * @param array<string, mixed> $baseline
     */
    private function writeBaselineContract(array $baseline): void
    {
        $dir = dirname(self::BASELINE_ARTIFACT);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->fail(sprintf('Failed to create baseline directory: %s', $dir));
        }

        $json = json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        file_put_contents(self::BASELINE_ARTIFACT, $json . PHP_EOL);
    }
}

final class BaselineAnonymousAccount implements AccountInterface
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

final class BaselineNodeViewPolicy implements AccessPolicyInterface
{
    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'node';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($operation !== 'view') {
            return AccessResult::neutral();
        }

        return (int) ($entity->toArray()['status'] ?? 0) === 1
            ? AccessResult::allowed('Published')
            : AccessResult::forbidden('Unpublished');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral();
    }
}

final class BaselineRelationshipViewPolicy implements AccessPolicyInterface
{
    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'relationship';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($operation !== 'view') {
            return AccessResult::neutral();
        }

        return (int) ($entity->toArray()['status'] ?? 0) === 1
            ? AccessResult::allowed('Published')
            : AccessResult::forbidden('Unpublished');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral();
    }
}
