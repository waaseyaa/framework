<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Auth;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/**
 * The result of a scope-aware MCP authentication (#2177 F3): the REAL owning
 * {@see AuthorizationPrincipalInterface} — never a token-derived identity, so approval
 * separation-of-duties comparisons keep their meaning — plus the explicit
 * capability scopes the presented credential is limited to.
 *
 * An empty scope list means "may use nothing", not "may use everything";
 * {@see \Waaseyaa\Mcp\McpEndpoint} intersects the tier's tool registry with
 * these scopes fail-closed.
 *
 * @api
 */
final readonly class ScopedPrincipal
{
    /** @param list<string> $scopes */
    public function __construct(
        public AuthorizationPrincipalInterface $account,
        public array $scopes,
    ) {}
}
