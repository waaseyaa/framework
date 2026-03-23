<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent;

/**
 * Lightweight MCP server adapter that exposes tools from a ToolRegistry.
 *
 * Implements the tools/list and tools/call portions of the Model Context
 * Protocol. This is a thin adapter, not a full protocol server (no
 * transport layer).
 */
final class McpServer
{
    public function __construct(
        private readonly ToolRegistryInterface $registry,
    ) {}

    /**
     * List available tools (MCP tools/list).
     *
     * @return array{tools: array<int, array{name: string, description: string, inputSchema: array<string, mixed>}>}
     */
    public function listTools(): array
    {
        $tools = [];
        foreach ($this->registry->getTools() as $tool) {
            $tools[] = $tool->toArray();
        }
        return ['tools' => $tools];
    }

    /**
     * Call a tool (MCP tools/call).
     *
     * @param string $name The tool name to call
     * @param array<string, mixed> $arguments Tool input arguments
     * @return array{content: array<int, array{type: string, text: string}>, isError?: bool}
     */
    public function callTool(string $name, array $arguments): array
    {
        if (!$this->registry->has($name)) {
            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => \json_encode(
                            ['error' => "Unknown tool: {$name}"],
                            \JSON_THROW_ON_ERROR,
                        ),
                    ],
                ],
                'isError' => true,
            ];
        }

        return $this->registry->execute($name, $arguments);
    }
}
