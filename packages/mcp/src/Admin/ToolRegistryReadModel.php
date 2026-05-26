<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Admin;

use Waaseyaa\AI\Tools\ToolRegistryInterface as AgentToolRegistryInterface;
use Waaseyaa\Api\McpAdmin\RecentInvocation;
use Waaseyaa\Api\McpAdmin\ToolDetail;
use Waaseyaa\Api\McpAdmin\ToolRegistryReadModelInterface;
use Waaseyaa\Api\McpAdmin\ToolRegistryRow;

/**
 * MCP-layer adapter implementing the api read contract for the tool registry
 * admin surface (M5C WP01 T003).
 *
 * Cross-layer pattern: this class is in Layer 6 (`waaseyaa/mcp`) and implements
 * `ToolRegistryReadModelInterface` defined in Layer 4 (`waaseyaa/api`). The
 * downward import (L6 → L4 interface) is allowed. The L4 `ApiServiceProvider`
 * resolves this binding via `resolveOptional` so that installations without
 * `waaseyaa/mcp` remain unaffected.
 *
 * `recentInvocations` are fetched from the optional M5A read model when bound;
 * when absent the field is an empty list (graceful degradation).
 */
final class ToolRegistryReadModel implements ToolRegistryReadModelInterface
{
    public function __construct(
        private readonly AgentToolRegistryInterface $agentRegistry,
        private readonly ?RecentInvocationsQueryInterface $invocationQuery = null,
    ) {}

    public function listTools(): array
    {
        $rows = [];
        foreach ($this->agentRegistry->all() as $tool) {
            $rows[] = new ToolRegistryRow(
                name: $tool->name,
                summary: $tool->impl->description() !== '' ? $tool->impl->description() : null,
                category: $tool->category,
                requiredCapabilities: [$tool->capability],
            );
        }

        return $rows;
    }

    public function findTool(string $name): ?ToolDetail
    {
        if (!$this->agentRegistry->has($name)) {
            return null;
        }

        $tool = $this->agentRegistry->get($name);
        $description = $tool->impl->description();

        $recentInvocations = [];
        if ($this->invocationQuery !== null) {
            $recentInvocations = $this->invocationQuery->recentForTool($name, 25);
        }

        return new ToolDetail(
            name: $tool->name,
            summary: $description !== '' ? $description : null,
            description: $description !== '' ? $description : null,
            category: $tool->category,
            requiredCapabilities: [$tool->capability],
            inputSchema: $tool->inputSchema,
            recentInvocations: $recentInvocations,
        );
    }
}
