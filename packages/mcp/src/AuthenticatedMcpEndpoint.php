<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp;

use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Waaseyaa\Access\AccountInterface;

/**
 * The authenticated MCP **write tier** controller — a separate route from the
 * public read-only `/mcp` (FR-004), so the alpha.221 public trio is untouched
 * (C-001).
 *
 * It exists only as a distinct, routable controller class: a route controller
 * resolves to a single container binding, so a second endpoint with different
 * wiring (bearer auth + a {@see CapabilityScopedToolRegistry} that exposes
 * destructive tools) needs its own class. The actual JSON-RPC dispatch — auth,
 * the per-request bridge, capability gating — is the unchanged {@see McpEndpoint}
 * it composes; {@see McpServiceProvider} constructs that inner endpoint with the
 * write-tier auth + registry. Authentication is fail-closed in the inner
 * endpoint (no/invalid bearer token ⇒ HTTP 401), and each tool re-checks its
 * capability (NFR-002).
 *
 * @api
 */
final readonly class AuthenticatedMcpEndpoint
{
    public function __construct(
        private McpEndpoint $inner,
    ) {}

    /**
     * HTTP controller entry point for the write-tier route. The session
     * `$account` is forwarded for `AppControllerRouter` contract compliance; the
     * inner endpoint resolves the MCP actor from the bearer token (see
     * {@see McpEndpoint::serve()}).
     */
    public function serve(
        AccountInterface $account,
        HttpRequest $request,
    ): HttpResponse {
        return $this->inner->serve($account, $request);
    }
}
