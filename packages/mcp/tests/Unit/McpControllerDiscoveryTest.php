<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Mcp\McpController;
use Waaseyaa\Mcp\Tests\Unit\Fixtures\TestMcpAccount;
use Waaseyaa\Mcp\Tests\Unit\Fixtures\TestNodeUpdatePolicy;

#[CoversClass(McpController::class)]
final class McpControllerDiscoveryTest extends TestCase
{
    #[Test]
    public function aiDiscoverRequiresNonEmptyQuery(): void
    {
        $controller = $this->createController();
        $response = $controller->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 24,
            'method' => 'tools/call',
            'params' => [
                'name' => 'ai_discover',
                'arguments' => ['query' => '  '],
            ],
        ]);

        $this->assertSame(-32602, $response['error']['code']);
        $this->assertStringContainsString('non-empty "query"', $response['error']['message']);
    }

    #[Test]
    public function aiDiscoverReturnsDeterministicRecommendationContract(): void
    {
        $controller = $this->createEditorialController(
            permissions: ['edit any article content'],
            roles: ['contributor'],
            workflowState: 'published',
            status: 1,
        );
        $response = $controller->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 25,
            'method' => 'tools/call',
            'params' => [
                'name' => 'ai_discover',
                'arguments' => [
                    'query' => 'Editorial',
                    'type' => 'node',
                    'limit' => 5,
                    'anchor_type' => 'node',
                    'anchor_id' => 1,
                ],
            ],
        ]);

        $payload = $this->decodeToolPayload($response);
        $this->assertSame('v1.0', $payload['meta']['contract_version']);
        $this->assertSame('stable', $payload['meta']['contract_stability']);
        $this->assertSame('ai_discover', $payload['meta']['tool']);
        $this->assertSame(1, $payload['meta']['count']);
        $this->assertCount(1, $payload['data']['recommendations']);
        $this->assertSame('published_only', $payload['data']['recommendations'][0]['explanation']['visibility_contract']);
        $this->assertSame('node', $payload['data']['graph_context']['source']['type']);
        $this->assertSame('1', $payload['data']['graph_context']['source']['id']);
    }

    #[Test]
    public function aiDiscoverRejectsNonPublicAnchorEntity(): void
    {
        $controller = $this->createEditorialController(
            permissions: ['edit any article content'],
            roles: ['contributor'],
            workflowState: 'draft',
            status: 0,
        );
        $response = $controller->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 26,
            'method' => 'tools/call',
            'params' => [
                'name' => 'ai_discover',
                'arguments' => [
                    'query' => 'editorial',
                    'type' => 'node',
                    'limit' => 5,
                    'anchor_type' => 'node',
                    'anchor_id' => 1,
                ],
            ],
        ]);

        $this->assertSame(-32000, $response['error']['code']);
        $this->assertStringContainsString('not public', $response['error']['message']);
    }

    #[Test]
    public function searchEntitiesAndLegacyAliasShareStableContractMetadata(): void
    {
        $controller = $this->createEditorialController(
            permissions: ['edit any article content'],
            roles: ['contributor'],
            workflowState: 'published',
            status: 1,
        );

        $canonical = $controller->handleRpc([
            'jsonrpc' => '2.0',
            'id' => 27,
            'method' => 'tools/call',
            'params' => [
                'name' => 'search_entities',
                'arguments' => ['query' => 'Editorial', 'type' => 'node', 'limit' => 5],
            ],
        ]);
        $canonicalPayload = $this->decodeToolPayload($canonical);
        $this->assertSame('v1.0', $canonicalPayload['meta']['contract_version']);
        $this->assertSame('stable', $canonicalPayload['meta']['contract_stability']);
        $this->assertSame('search_entities', $canonicalPayload['meta']['tool']);
        $this->assertSame('search_entities', $canonicalPayload['meta']['tool_invoked']);

    }

    private function createController(): McpController
    {
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn([]);
        $manager->method('getDefinition')->willThrowException(new \RuntimeException('not used'));

        $serializer = new ResourceSerializer($manager);
        $storage = $this->createMock(EmbeddingStorageInterface::class);

        return new McpController(
            entityTypeManager: $manager,
            serializer: $serializer,
            accessHandler: new EntityAccessHandler([]),
            account: new TestMcpAccount(),
            embeddingStorage: $storage,
            embeddingProvider: null,
        );
    }

    /**
     * @param list<string> $permissions
     * @param list<string> $roles
     */
    private function createEditorialController(
        array $permissions,
        array $roles,
        string $workflowState,
        int $status,
    ): McpController {
        $nodeDefinition = new EntityType(
            id: 'node',
            label: 'Node',
            class: \stdClass::class,
            keys: ['id' => 'id', 'label' => 'title', 'bundle' => 'type'],
            fieldDefinitions: [],
        );

        $nodeStorage = new InMemoryEntityStorage('node');
        $entity = $nodeStorage->create([
            'type' => 'article',
            'title' => 'Editorial Node',
            'status' => $status,
            'workflow_state' => $workflowState,
        ]);
        $nodeStorage->save($entity);

        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('hasDefinition')->willReturnCallback(static fn(string $entityTypeId): bool => $entityTypeId === 'node');
        $manager->method('getStorage')->willReturnCallback(static fn(string $entityTypeId) => $entityTypeId === 'node'
            ? $nodeStorage
            : throw new \RuntimeException('Unknown storage'));
        $manager->method('getDefinition')->willReturnCallback(static fn(string $entityTypeId) => $entityTypeId === 'node'
            ? $nodeDefinition
            : throw new \RuntimeException('Unknown definition'));
        $manager->method('getDefinitions')->willReturn(['node' => $nodeDefinition]);

        $serializer = new ResourceSerializer($manager);
        $embeddingStorage = $this->createMock(EmbeddingStorageInterface::class);
        $account = new TestMcpAccount(
            userId: 5,
            permissions: $permissions,
            roles: $roles,
            authenticated: true,
        );
        $access = new EntityAccessHandler([new TestNodeUpdatePolicy()]);

        return new McpController(
            entityTypeManager: $manager,
            serializer: $serializer,
            accessHandler: $access,
            account: $account,
            embeddingStorage: $embeddingStorage,
            embeddingProvider: null,
        );
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function decodeToolPayload(array $response): array
    {
        $this->assertArrayHasKey('result', $response);
        $this->assertArrayHasKey('content', $response['result']);
        $this->assertArrayHasKey(0, $response['result']['content']);
        $this->assertArrayHasKey('text', $response['result']['content'][0]);

        return json_decode((string) $response['result']['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
    }
}
