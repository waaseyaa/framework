<?php

declare(strict_types=1);

namespace Waaseyaa\Api\McpAdmin;

/**
 * Read contract for the MCP server-config admin surface (M5C WP01, FR-006–FR-007).
 *
 * Implemented in `packages/mcp` (Layer 6); resolved via `resolveOptional` in
 * `ApiServiceProvider` so the api package (Layer 4) remains decoupled from the
 * higher layer. When unbound the controller returns an empty-shape response.
 *
 * @api
 */
interface ServerConfigReadModelInterface
{
    /**
     * Return the current MCP server configuration snapshot.
     */
    public function serverConfig(): ServerConfigSnapshot;
}
