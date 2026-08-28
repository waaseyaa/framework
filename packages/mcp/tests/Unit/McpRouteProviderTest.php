<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Http\Refusal\HttpRefusal;
use Waaseyaa\Foundation\Http\Refusal\RefusalEnvelope;
use Waaseyaa\Mcp\Auth\OAuthProtectedResourceMetadataConfig;
use Waaseyaa\Mcp\McpErrorCode;
use Waaseyaa\Mcp\McpRouteProvider;
use Waaseyaa\Mcp\StreamableHttpRequestSnapshot;
use Waaseyaa\Mcp\StreamableHttpTransportGuard;
use Waaseyaa\Routing\WaaseyaaRouter;

#[CoversClass(McpRouteProvider::class)]
final class McpRouteProviderTest extends TestCase
{
    #[Test]
    public function registerRoutesAddsMcpEndpointRoute(): void
    {
        $router = new WaaseyaaRouter();
        $provider = new McpRouteProvider();

        $provider->registerRoutes($router);

        $routes = $router->getRouteCollection();
        $endpointRoute = $routes->get('mcp.endpoint');

        $this->assertNotNull($endpointRoute);
        $this->assertSame('/mcp', $endpointRoute->getPath());
        $this->assertContains('POST', $endpointRoute->getMethods());
        $this->assertContains('GET', $endpointRoute->getMethods());
    }

    #[Test]
    public function registerRoutesAddsServerCardRoute(): void
    {
        $router = new WaaseyaaRouter();
        $provider = new McpRouteProvider();

        $provider->registerRoutes($router);

        $routes = $router->getRouteCollection();
        $cardRoute = $routes->get('mcp.server_card');

        $this->assertNotNull($cardRoute);
        $this->assertSame('/.well-known/mcp.json', $cardRoute->getPath());
        $this->assertContains('GET', $cardRoute->getMethods());
    }

    #[Test]
    public function mcpEndpointRouteIsCsrfExempt(): void
    {
        $router = new WaaseyaaRouter();
        $provider = new McpRouteProvider();
        $provider->registerRoutes($router);

        $routes = $router->getRouteCollection();
        $mcpRoute = $routes->get('mcp.endpoint');

        $this->assertNotNull($mcpRoute, 'mcp.endpoint route should be registered');
        $this->assertFalse($mcpRoute->getOption('_csrf'), 'MCP endpoint should be CSRF exempt');
    }

    #[Test]
    public function writeEndpointPinsPublicRouterAndCsrfExemptContract(): void
    {
        $router = new WaaseyaaRouter();
        new McpRouteProvider()->registerRoutes($router);

        $route = $router->getRouteCollection()->get('mcp.endpoint.write');

        self::assertNotNull($route);
        self::assertSame('/mcp/write', $route->getPath());
        self::assertSame(['POST', 'GET'], $route->getMethods());
        self::assertTrue($route->getOption('_public'));
        self::assertFalse($route->getOption('_csrf'));
    }

    #[Test]
    public function serverCardRouteIsPublic(): void
    {
        $router = new WaaseyaaRouter();
        $provider = new McpRouteProvider();

        $provider->registerRoutes($router);

        $routes = $router->getRouteCollection();
        $cardRoute = $routes->get('mcp.server_card');

        $this->assertTrue($cardRoute->getOption('_public'));
    }

    #[Test]
    public function oauth_protected_resource_metadata_is_registered_only_when_configured(): void
    {
        $router = new WaaseyaaRouter();
        new McpRouteProvider(false, new OAuthProtectedResourceMetadataConfig(
            'https://cms.example/mcp/write',
            ['https://identity.example'],
        ))->registerRoutes($router);

        $routes = $router->getRouteCollection();
        self::assertNull($routes->get('mcp.endpoint'));
        $metadata = $routes->get('mcp.oauth_protected_resource');
        self::assertNotNull($metadata);
        self::assertSame('/.well-known/oauth-protected-resource/mcp/write', $metadata->getPath());
        self::assertSame(['GET'], $metadata->getMethods());
        self::assertSame(
            'Waaseyaa\\Mcp\\McpEndpoint::serveProtectedResourceMetadata',
            $metadata->getDefault('_controller'),
        );
        self::assertTrue($metadata->getOption('_public'));
    }

    /**
     * #2594: both JSON-RPC tiers must declare the vocabulary the kernel refuses
     * in, or a kernel-level refusal (oversized body, malformed JSON) reaches a
     * JSON-RPC client as a JSON:API document it cannot interpret.
     */
    #[Test]
    public function both_json_rpc_tiers_declare_their_kernel_refusal_codes(): void
    {
        $router = new WaaseyaaRouter();
        new McpRouteProvider()->registerRoutes($router);

        $routes = $router->getRouteCollection();

        foreach (['mcp.endpoint', 'mcp.endpoint.write'] as $name) {
            $route = $routes->get($name);
            self::assertNotNull($route, "{$name} should be registered");
            self::assertSame(
                RefusalEnvelope::TRANSPORT_JSON_RPC,
                $route->getOption(RefusalEnvelope::TRANSPORT_OPTION),
                "{$name} should refuse in JSON-RPC",
            );
            self::assertSame(
                [
                    RefusalEnvelope::REASON_PAYLOAD_TOO_LARGE => McpErrorCode::REQUEST_TOO_LARGE,
                    RefusalEnvelope::REASON_PARSE_ERROR => -32700,
                ],
                $route->getOption(RefusalEnvelope::CODES_OPTION),
                "{$name} should map both kernel refusal reasons",
            );
        }
    }

    /**
     * The declared codes must produce the same wire shape the endpoint's own
     * transport guard produces, or the seam has merely moved the mismatch.
     */
    #[Test]
    public function the_declared_oversize_code_matches_the_transport_guards_own_refusal(): void
    {
        $router = new WaaseyaaRouter();
        new McpRouteProvider()->registerRoutes($router);
        $route = $router->getRouteCollection()->get('mcp.endpoint');
        self::assertNotNull($route);

        $kernelRefusal = RefusalEnvelope::fromRouteOptions($route->getOptions())->refuse(new HttpRefusal(
            status: 413,
            reason: RefusalEnvelope::REASON_PAYLOAD_TOO_LARGE,
            title: 'Payload Too Large',
            transportMessage: 'Request body exceeds maximum size',
        ));

        $guardRefusal = new StreamableHttpTransportGuard(maxRequestBytes: 8)->validate(
            new StreamableHttpRequestSnapshot(
                method: 'POST',
                origin: null,
                contentLength: '64',
                contentType: 'application/json',
                accept: 'application/json, text/event-stream',
                schemeAndHttpHost: 'https://cms.example',
                body: '',
            ),
        );
        self::assertNotNull($guardRefusal);

        $kernelError = json_decode((string) $kernelRefusal->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $guardError = json_decode($guardRefusal->body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(413, $kernelRefusal->getStatusCode());
        self::assertStringStartsWith(
            $guardRefusal->contentType,
            (string) $kernelRefusal->headers->get('Content-Type'),
        );
        self::assertSame($guardRefusal->statusCode, $kernelRefusal->getStatusCode());
        self::assertSame($guardError['jsonrpc'], $kernelError['jsonrpc']);
        self::assertSame($guardError['id'], $kernelError['id']);
        self::assertSame($guardError['error']['code'], $kernelError['error']['code']);
        self::assertSame($guardError['error']['message'], $kernelError['error']['message']);
    }
}
