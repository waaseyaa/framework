<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp;

use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * Registers the MCP HTTP surface.
 *
 * The public read-only tier (`/mcp` plus its `/.well-known/mcp.json` discovery
 * card) is registered only when the deployment allows it — see
 * {@see McpServiceProvider::publicEndpointEnabled()} and the `mcp.public.enabled`
 * config key. Disabling it removes the routes outright, so the surface answers
 * 404 rather than existing in a half-disabled state that still confirms an MCP
 * server is present.
 *
 * The authenticated write tier (`/mcp/write`) is always registered and is
 * independently fail-closed: without an application-supplied
 * {@see Auth\WriteTierAuthInterface} every request is HTTP 401.
 */
final readonly class McpRouteProvider
{
    /**
     * @param bool $publicEndpointEnabled Whether to register the anonymous
     *        read-only tier and its discovery card. Defaults to true so direct
     *        construction keeps the historical surface.
     */
    public function __construct(
        private bool $publicEndpointEnabled = true,
        private ?Auth\OAuthProtectedResourceMetadataConfig $oauthResource = null,
    ) {}

    public function registerRoutes(WaaseyaaRouter $router): void
    {
        if ($this->publicEndpointEnabled) {
            $router->addRoute(
                'mcp.endpoint',
                RouteBuilder::create('/mcp')
                    ->controller('Waaseyaa\\Mcp\\McpEndpoint::serve')
                    ->methods('POST', 'GET')
                    ->allowAll()
                    ->csrfExempt()
                    ->build(),
            );

            // The discovery card lives and dies with the endpoint it advertises:
            // a card pointing at a 404 is worse than no card at all.
            $router->addRoute(
                'mcp.server_card',
                RouteBuilder::create('/.well-known/mcp.json')
                    ->controller('Waaseyaa\\Mcp\\McpServerCard::serve')
                    ->methods('GET')
                    ->allowAll()
                    ->build(),
            );
        }

        // Authenticated MCP write tier (FR-004): a SEPARATE route from the public
        // read-only `/mcp`, so the alpha.221 trio is untouched (C-001). The route
        // is open at the HTTP layer; authentication is enforced inside the inner
        // McpEndpoint by the bearer-token auth strategy (fail-closed: 401 without
        // a valid token). It is deliberately NOT gated by `mcp.public.enabled` —
        // an authenticated write tier with no anonymous read tier is a supported,
        // and expected, production shape.
        $router->addRoute(
            'mcp.endpoint.write',
            RouteBuilder::create('/mcp/write')
                ->controller('Waaseyaa\\Mcp\\AuthenticatedMcpEndpoint::serve')
                ->methods('POST', 'GET')
                ->allowAll()
                ->csrfExempt()
                ->build(),
        );

        if ($this->oauthResource !== null) {
            $router->addRoute(
                'mcp.oauth_protected_resource',
                RouteBuilder::create($this->oauthResource->metadataPath())
                    ->controller('Waaseyaa\\Mcp\\McpEndpoint::serveProtectedResourceMetadata')
                    ->methods('GET')
                    ->allowAll()
                    ->build(),
            );
        }
    }
}
