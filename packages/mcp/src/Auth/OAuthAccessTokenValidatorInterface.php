<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Auth;

/**
 * Trust boundary between MCP and an OAuth 2.1 authorization server.
 *
 * Implementations MUST verify the token's issuer, integrity or introspection
 * response, expiry, revocation state, and audience/resource binding before
 * returning a principal. They MUST map the subject to an active real account
 * and return only granted capability scopes. Tokens issued for another
 * resource must return null and must never be passed through downstream.
 *
 * @api
 */
interface OAuthAccessTokenValidatorInterface
{
    public function validate(string $accessToken, string $resource): ?ScopedPrincipal;
}
