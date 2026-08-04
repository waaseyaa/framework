<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Auth;

/**
 * A scope-aware {@see McpAuthInterface} (#2177 F3): the strategy can state
 * WHICH capability scopes the presented credential is limited to, so the
 * endpoint narrows the request's tool surface to the intersection of tier
 * and token. Strategies without scope knowledge keep implementing plain
 * {@see McpAuthInterface} and the tier surface is unchanged.
 *
 * `authenticate()` and `authenticateWithScopes()` must agree: the former
 * returns exactly the latter's account (or null).
 *
 * @api
 */
interface ScopedMcpAuthInterface extends McpAuthInterface
{
    public function authenticateWithScopes(?string $authorizationHeader): ?ScopedPrincipal;
}
