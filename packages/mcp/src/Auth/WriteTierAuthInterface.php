<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Auth;

/**
 * Marker for the authentication strategy of the **authenticated MCP write tier**
 * (`/mcp/write`), kept distinct from the public `/mcp` {@see McpAuthInterface}
 * binding so an application can supply write-tier credentials without altering
 * the public read-only surface (C-001).
 *
 * The framework default (#2177 F3) is {@see DurableBearerTokenAuth} over the
 * durable {@see \Waaseyaa\Auth\Token\Bearer\BearerTokenStoreInterface} whenever
 * the kernel supplies the store, the `user` repository, and the audited
 * principal factory — a fresh deployment has no tokens, so every request still
 * fails closed with HTTP 401 until an operator issues one (`bearer-token:issue`).
 * When the durable path is unwireable the default degrades to the fail-closed
 * empty map `BearerTokenAuth([])`. An application binding this interface still
 * overrides both.
 *
 * @api
 */
interface WriteTierAuthInterface extends McpAuthInterface {}
