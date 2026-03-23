<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Schema;

use Waaseyaa\AI\Agent\ToolRegistryInterface;
use Waaseyaa\AI\Schema\Mcp\McpToolDefinition;
use Waaseyaa\AI\Schema\Mcp\McpToolExecutor;
use Waaseyaa\AI\Schema\Mcp\McpToolGenerator;

/**
 * Central registry combining JSON Schema and MCP tool outputs.
 *
 * Provides a unified API for accessing entity schemas and tool definitions,
 * making it easy for AI agents to discover the full CMS surface area.
 */
final class SchemaRegistry
{
    /**
     * @var McpToolDefinition[]|null
     */
    private ?array $toolCache = null;

    public function __construct(
        private readonly EntityJsonSchemaGenerator $schemaGenerator,
        private readonly McpToolGenerator $toolGenerator,
    ) {}

    /**
     * Get JSON Schema for a single entity type.
     *
     * @return array<string, mixed> JSON Schema array
     */
    public function getSchema(string $entityTypeId): array
    {
        return $this->schemaGenerator->generate($entityTypeId);
    }

    /**
     * Get all schemas keyed by entity type ID.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAllSchemas(): array
    {
        return $this->schemaGenerator->generateAll();
    }

    /**
     * Get all MCP tool definitions.
     *
     * @return McpToolDefinition[]
     */
    public function getTools(): array
    {
        return $this->toolCache ??= $this->toolGenerator->generateAll();
    }

    /**
     * Get a specific MCP tool by name.
     */
    public function getTool(string $name): ?McpToolDefinition
    {
        foreach ($this->getTools() as $tool) {
            if ($tool->name === $name) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * Register all auto-generated entity CRUD tools into a tool registry.
     */
    public function registerEntityTools(ToolRegistryInterface $registry, McpToolExecutor $executor): void
    {
        foreach ($this->getTools() as $tool) {
            $toolName = $tool->name;
            $registry->register(
                $tool,
                static fn (array $arguments) => $executor->execute($toolName, $arguments),
            );
        }
    }
}
