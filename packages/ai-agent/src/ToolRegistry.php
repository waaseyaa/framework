<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent;

use Waaseyaa\AI\Schema\Mcp\McpToolDefinition;

/**
 * In-memory tool registry mapping tool names to definitions and executors.
 */
final class ToolRegistry implements ToolRegistryInterface
{
    /** @var array<string, McpToolDefinition> */
    private array $tools = [];

    /** @var array<string, callable> */
    private array $executors = [];

    public function register(McpToolDefinition $tool, callable $executor): void
    {
        $this->tools[$tool->name] = $tool;
        $this->executors[$tool->name] = $executor;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function getTool(string $name): ?McpToolDefinition
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return McpToolDefinition[]
     */
    public function getTools(): array
    {
        return \array_values($this->tools);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{content: array<int, array{type: string, text: string}>, isError?: bool}
     */
    public function execute(string $name, array $arguments): array
    {
        if (!$this->has($name)) {
            throw new \InvalidArgumentException("Unknown tool: {$name}");
        }

        try {
            return ($this->executors[$name])($arguments);
        } catch (\Throwable $e) {
            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => \json_encode(['error' => $e->getMessage()], \JSON_THROW_ON_ERROR),
                    ],
                ],
                'isError' => true,
            ];
        }
    }
}
