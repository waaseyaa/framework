<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase11;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Schema\EntityJsonSchemaGenerator;
use Waaseyaa\AI\Schema\Mcp\McpToolDefinition;
use Waaseyaa\AI\Schema\Mcp\McpToolExecutor;
use Waaseyaa\AI\Schema\Mcp\McpToolGenerator;
use Waaseyaa\AI\Schema\SchemaRegistry;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Mcp\Auth\BearerTokenAuth;
use Waaseyaa\Mcp\Bridge\ToolExecutorInterface;
use Waaseyaa\Mcp\Bridge\ToolRegistryInterface;
use Waaseyaa\Mcp\McpEndpoint;
use Waaseyaa\Mcp\McpServerCard;
use Waaseyaa\Mcp\McpServiceProvider;
use Waaseyaa\Routing\WaaseyaaRouter;

final class McpEndpointSmokeTest extends TestCase
{
    #[Test]
    public function mcpToolDiscoveryAndExecution(): void
    {
        // --- Setup: mock entity type manager with 'node' type ---
        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $storage = $this->createMock(EntityStorageInterface::class);
        $query = $this->createMock(EntityQueryInterface::class);
        $definition = $this->createMock(EntityTypeInterface::class);

        $definition->method('getLabel')->willReturn('Node');
        $definition->method('getKeys')->willReturn([
            'id' => 'nid', 'uuid' => 'uuid', 'label' => 'title',
        ]);

        $entityTypeManager->method('getDefinitions')->willReturn(['node' => $definition]);
        $entityTypeManager->method('getDefinition')->willReturn($definition);
        $entityTypeManager->method('hasDefinition')
            ->willReturnCallback(fn(string $id) => $id === 'node');
        $entityTypeManager->method('getStorage')->willReturn($storage);

        $storage->method('getQuery')->willReturn($query);

        // --- Build the MCP stack ---
        $schemaGenerator = new EntityJsonSchemaGenerator($entityTypeManager);
        $toolGenerator = new McpToolGenerator($entityTypeManager);
        $schemaRegistry = new SchemaRegistry($schemaGenerator, $toolGenerator);
        $toolExecutor = new McpToolExecutor($entityTypeManager);

        // Thin adapters to bridge final upstream classes to MCP interfaces.
        $registry = new class ($schemaRegistry) implements ToolRegistryInterface {
            public function __construct(private readonly SchemaRegistry $inner) {}
            public function getTools(): array
            {
                return $this->inner->getTools();
            }
            public function getTool(string $name): ?McpToolDefinition
            {
                return $this->inner->getTool($name);
            }
        };

        $executor = new class ($toolExecutor) implements ToolExecutorInterface {
            public function __construct(private readonly McpToolExecutor $inner) {}
            public function execute(string $toolName, array $arguments): array
            {
                return $this->inner->execute($toolName, $arguments);
            }
        };

        $account = $this->createMock(AccountInterface::class);
        $account->method('id')->willReturn(1);
        $account->method('hasPermission')->willReturn(true);

        $auth = new BearerTokenAuth(['test-token-abc' => $account]);

        $endpoint = new McpEndpoint(
            auth: $auth,
            registry: $registry,
            executor: $executor,
        );

        // --- Step 1: Server card ---
        $card = new McpServerCard();
        $cardData = $card->toArray();
        $this->assertSame('Waaseyaa', $cardData['name']);
        $this->assertTrue($cardData['capabilities']['tools']);

        // --- Step 2: Route registration ---
        $router = new WaaseyaaRouter();
        (new McpServiceProvider())->routes($router);

        $routes = $router->getRouteCollection();
        $this->assertNotNull($routes->get('mcp.endpoint'));
        $this->assertNotNull($routes->get('mcp.server_card'));

        // --- Step 3: Auth failure ---
        $response = $endpoint->handle(
            method: 'POST',
            body: '{"jsonrpc":"2.0","id":1,"method":"tools/list"}',
            authorizationHeader: 'Bearer wrong-token',
        );
        $this->assertSame(401, $response->statusCode);

        // --- Step 4: Tool discovery ---
        $response = $endpoint->handle(
            method: 'POST',
            body: \json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
            ]),
            authorizationHeader: 'Bearer test-token-abc',
        );

        $this->assertSame(200, $response->statusCode);
        $decoded = \json_decode($response->body, true);
        $tools = $decoded['result']['tools'];

        // 5 CRUD tools for 'node' entity type.
        $this->assertCount(5, $tools);
        $toolNames = \array_column($tools, 'name');
        $this->assertContains('create_node', $toolNames);
        $this->assertContains('read_node', $toolNames);
        $this->assertContains('update_node', $toolNames);
        $this->assertContains('delete_node', $toolNames);
        $this->assertContains('query_node', $toolNames);

        // Each tool has the required MCP fields.
        foreach ($tools as $tool) {
            $this->assertArrayHasKey('name', $tool);
            $this->assertArrayHasKey('description', $tool);
            $this->assertArrayHasKey('inputSchema', $tool);
            $this->assertSame('object', $tool['inputSchema']['type']);
        }

        // --- Step 5: Tool execution (read_node) ---
        $entity = $this->createMock(ContentEntityBase::class);
        $entity->method('id')->willReturn(42);
        $entity->method('toArray')->willReturn(['nid' => 42, 'title' => 'Hello']);

        $storage->method('load')->with(42)->willReturn($entity);

        $response = $endpoint->handle(
            method: 'POST',
            body: \json_encode([
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'read_node',
                    'arguments' => ['id' => 42],
                ],
            ]),
            authorizationHeader: 'Bearer test-token-abc',
        );

        $this->assertSame(200, $response->statusCode);
        $decoded = \json_decode($response->body, true);
        $this->assertSame(2, $decoded['id']);
        $this->assertArrayHasKey('result', $decoded);

        $content = $decoded['result']['content'][0];
        $this->assertSame('text', $content['type']);
        $resultData = \json_decode($content['text'], true);
        $this->assertSame('read', $resultData['operation']);
        $this->assertSame(42, $resultData['id']);

        // --- Step 6: Initialize handshake ---
        $response = $endpoint->handle(
            method: 'POST',
            body: \json_encode([
                'jsonrpc' => '2.0',
                'id' => 3,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-03-26',
                    'clientInfo' => ['name' => 'test', 'version' => '1.0'],
                    'capabilities' => [],
                ],
            ]),
            authorizationHeader: 'Bearer test-token-abc',
        );

        $decoded = \json_decode($response->body, true);
        $this->assertSame('2025-03-26', $decoded['result']['protocolVersion']);
        $this->assertSame('Waaseyaa', $decoded['result']['serverInfo']['name']);
    }
}
