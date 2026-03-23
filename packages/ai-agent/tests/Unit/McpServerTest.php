<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit;

use Waaseyaa\AI\Agent\McpServer;
use Waaseyaa\AI\Agent\ToolRegistry;
use Waaseyaa\AI\Schema\Mcp\McpToolDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(McpServer::class)]
final class McpServerTest extends TestCase
{
    public function testListToolsReturnsToolsFromRegistry(): void
    {
        $registry = new ToolRegistry();
        $registry->register(
            new McpToolDefinition(name: 'create_node', description: 'Create a node', inputSchema: ['type' => 'object']),
            fn (array $args) => ['content' => [['type' => 'text', 'text' => 'created']]],
        );
        $registry->register(
            new McpToolDefinition(name: 'read_node', description: 'Read a node', inputSchema: ['type' => 'object']),
            fn (array $args) => ['content' => [['type' => 'text', 'text' => 'read']]],
        );

        $server = new McpServer($registry);
        $result = $server->listTools();

        self::assertArrayHasKey('tools', $result);
        self::assertCount(2, $result['tools']);

        $toolNames = array_column($result['tools'], 'name');
        self::assertContains('create_node', $toolNames);
        self::assertContains('read_node', $toolNames);

        // Each tool should have name, description, and inputSchema
        foreach ($result['tools'] as $tool) {
            self::assertArrayHasKey('name', $tool);
            self::assertArrayHasKey('description', $tool);
            self::assertArrayHasKey('inputSchema', $tool);
        }
    }

    public function testListToolsEmptyRegistry(): void
    {
        $registry = new ToolRegistry();
        $server = new McpServer($registry);

        $result = $server->listTools();

        self::assertSame(['tools' => []], $result);
    }

    public function testCallToolDelegatesToRegistry(): void
    {
        $registry = new ToolRegistry();
        $registry->register(
            new McpToolDefinition(name: 'create_node', description: 'Create', inputSchema: []),
            fn (array $args) => [
                'content' => [['type' => 'text', 'text' => \json_encode(['operation' => 'create', 'entity_type' => 'node'], \JSON_THROW_ON_ERROR)]],
            ],
        );

        $server = new McpServer($registry);
        $result = $server->callTool('create_node', ['attributes' => ['title' => 'Test']]);

        self::assertArrayHasKey('content', $result);
        self::assertCount(1, $result['content']);
        self::assertSame('text', $result['content'][0]['type']);

        $decoded = json_decode($result['content'][0]['text'], true);
        self::assertSame('create', $decoded['operation']);
        self::assertSame('node', $decoded['entity_type']);
    }

    public function testCallToolWithUnknownToolReturnsError(): void
    {
        $registry = new ToolRegistry();
        $server = new McpServer($registry);

        $result = $server->callTool('nonexistent_tool', []);

        self::assertTrue($result['isError']);
        self::assertCount(1, $result['content']);
        self::assertSame('text', $result['content'][0]['type']);

        $decoded = json_decode($result['content'][0]['text'], true);
        self::assertSame('Unknown tool: nonexistent_tool', $decoded['error']);
    }

    public function testCallToolWithExecutorError(): void
    {
        $registry = new ToolRegistry();
        $registry->register(
            new McpToolDefinition(name: 'fail_tool', description: 'Fails', inputSchema: []),
            fn (array $args) => throw new \RuntimeException('Tool execution failed'),
        );

        $server = new McpServer($registry);
        $result = $server->callTool('fail_tool', []);

        self::assertTrue($result['isError']);
    }
}
