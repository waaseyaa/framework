<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Agent\ToolRegistry;
use Waaseyaa\AI\Schema\Mcp\McpToolDefinition;

#[CoversClass(ToolRegistry::class)]
final class ToolRegistryTest extends TestCase
{
    public function testRegisterAndRetrieveTool(): void
    {
        $registry = new ToolRegistry();
        $tool = new McpToolDefinition(
            name: 'gmail_send',
            description: 'Send an email',
            inputSchema: ['type' => 'object', 'properties' => []],
        );

        $registry->register($tool, fn (array $args) => ['content' => [['type' => 'text', 'text' => 'sent']]]);

        $this->assertTrue($registry->has('gmail_send'));
        $this->assertSame($tool, $registry->getTool('gmail_send'));
    }

    public function testGetToolsReturnsAllRegistered(): void
    {
        $registry = new ToolRegistry();
        $tool1 = new McpToolDefinition(name: 'tool_a', description: 'A', inputSchema: []);
        $tool2 = new McpToolDefinition(name: 'tool_b', description: 'B', inputSchema: []);

        $registry->register($tool1, fn (array $args) => []);
        $registry->register($tool2, fn (array $args) => []);

        $tools = $registry->getTools();
        $this->assertCount(2, $tools);
    }

    public function testExecuteDelegatesToCallable(): void
    {
        $registry = new ToolRegistry();
        $tool = new McpToolDefinition(name: 'echo', description: 'Echo', inputSchema: []);

        $registry->register($tool, fn (array $args) => [
            'content' => [['type' => 'text', 'text' => \json_encode($args, \JSON_THROW_ON_ERROR)]],
        ]);

        $result = $registry->execute('echo', ['message' => 'hello']);
        $this->assertSame('{"message":"hello"}', $result['content'][0]['text']);
    }

    public function testExecuteThrowsForUnknownTool(): void
    {
        $registry = new ToolRegistry();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown tool: nonexistent');

        $registry->execute('nonexistent', []);
    }

    public function testExecuteWrapsExceptionsInMcpErrorFormat(): void
    {
        $registry = new ToolRegistry();
        $tool = new McpToolDefinition(name: 'fail', description: 'Fails', inputSchema: []);

        $registry->register($tool, fn (array $args) => throw new \RuntimeException('boom'));

        $result = $registry->execute('fail', []);
        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('boom', $result['content'][0]['text']);
    }

    public function testHasReturnsFalseForUnregistered(): void
    {
        $registry = new ToolRegistry();
        $this->assertFalse($registry->has('unknown'));
    }

    public function testGetToolReturnsNullForUnregistered(): void
    {
        $registry = new ToolRegistry();
        $this->assertNull($registry->getTool('unknown'));
    }
}
