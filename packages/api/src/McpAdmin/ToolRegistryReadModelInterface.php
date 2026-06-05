<?php

declare(strict_types=1);

namespace Waaseyaa\Api\McpAdmin;

/**
 * Read contract for the MCP tool registry admin surface (M5C WP01, FR-001–FR-005).
 *
 * Implemented in `packages/mcp` (Layer 6); resolved via `resolveOptional` in
 * `ApiServiceProvider` so the api package (Layer 4) remains decoupled from the
 * higher layer. When unbound the controller returns an empty-shape response.
 *
 * @api
 */
interface ToolRegistryReadModelInterface
{
    /**
     * List all registered MCP tools (registry index).
     *
     * @return list<ToolRegistryRow>
     */
    public function listTools(): array;

    /**
     * Find a single tool by name. Returns null when not registered.
     */
    public function findTool(string $name): ?ToolDetail;
}
