<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Auth;

/**
 * Marker for the authentication strategy of the **authenticated MCP write tier**
 * (`/mcp/write`), kept distinct from the public `/mcp` {@see McpAuthInterface}
 * binding so an application can supply write-tier credentials without altering
 * the public read-only surface (C-001).
 *
 * The framework default binding is `BearerTokenAuth([])` — an empty token map,
 * so every write-tier request fails closed with HTTP 401 until a deployment
 * re-binds this with its token→account map (the accounts carrying the
 * `present guided content` capability). Token→account mapping is inherently
 * application-specific, so the framework cannot ship a non-empty default.
 *
 * @api
 */
interface WriteTierAuthInterface extends McpAuthInterface {}
