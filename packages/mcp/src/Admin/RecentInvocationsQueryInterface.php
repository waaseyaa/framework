<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Admin;

use Waaseyaa\Api\McpAdmin\RecentInvocation;

/**
 * Narrow optional port for fetching recent MCP tool invocations from the
 * observability store (M5C WP01 T003).
 *
 * Implemented by an adapter in `packages/ai-observability` (M5A) when that
 * package is installed. When absent the `ToolRegistryReadModel` degrades
 * gracefully to an empty `recentInvocations` list.
 *
 * This port deliberately avoids importing `AiObservabilityReadModelInterface`
 * directly so `waaseyaa/mcp` does not gain a hard compile-time dependency on
 * `waaseyaa/ai-observability` (which may ship as an optional add-on).
 *
 * @api
 */
interface RecentInvocationsQueryInterface
{
    /**
     * Return the most recent invocations of a named MCP tool, ordered newest first.
     *
     * @param non-empty-string $toolName  Exact tool name (already URL-decoded).
     * @param positive-int     $limit     Maximum number of rows (typically 25).
     * @return list<RecentInvocation>
     */
    public function recentForTool(string $toolName, int $limit): array;
}
