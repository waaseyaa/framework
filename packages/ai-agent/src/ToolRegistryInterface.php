<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent;

use Waaseyaa\AI\Schema\Mcp\McpToolDefinition;

/**
 * Contract for tool registration and execution.
 *
 * Allows custom tools (MCP, Gmail, calendar, etc.) to be registered
 * alongside auto-generated entity CRUD tools.
 */
interface ToolRegistryInterface
{
    /**
     * Register a tool with its executor callable.
     */
    public function register(McpToolDefinition $tool, callable $executor): void;

    /**
     * Check if a tool is registered.
     */
    public function has(string $name): bool;

    /**
     * Get a tool definition by name, or null if not registered.
     */
    public function getTool(string $name): ?McpToolDefinition;

    /**
     * Get all registered tool definitions.
     *
     * @return McpToolDefinition[]
     */
    public function getTools(): array;

    /**
     * Execute a tool by name.
     *
     * @param array<string, mixed> $arguments
     * @return array{content: array<int, array{type: string, text: string}>, isError?: bool}
     */
    public function execute(string $name, array $arguments): array;
}
