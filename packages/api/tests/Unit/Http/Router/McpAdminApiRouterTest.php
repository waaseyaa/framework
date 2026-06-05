<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Api\Controller\McpAdminController;
use Waaseyaa\Api\Http\Router\McpAdminApiRouter;
use Waaseyaa\Api\McpAdmin\ServerConfigReadModelInterface;
use Waaseyaa\Api\McpAdmin\ServerConfigSnapshot;
use Waaseyaa\Api\McpAdmin\ToolRegistryReadModelInterface;
use Waaseyaa\Api\McpAdmin\ToolRegistryRow;

/**
 * Unit tests for McpAdminApiRouter (M5C WP01 T004).
 *
 * Mirrors MercureMonitorApiRouterTest shape.
 */
#[CoversClass(McpAdminApiRouter::class)]
final class McpAdminApiRouterTest extends TestCase
{
    private McpAdminController $controller;
    private McpAdminApiRouter $router;

    protected function setUp(): void
    {
        $registry = new class implements ToolRegistryReadModelInterface {
            public function listTools(): array
            {
                return [
                    new ToolRegistryRow('bimaaji_search', 'Search specs', 'bimaaji', ['bimaaji.read']),
                ];
            }

            public function findTool(string $name): ?\Waaseyaa\Api\McpAdmin\ToolDetail
            {
                return null;
            }
        };

        $config = new class implements ServerConfigReadModelInterface {
            public function serverConfig(): ServerConfigSnapshot
            {
                return new ServerConfigSnapshot(
                    transport: 'streamable-http',
                    protocolVersion: '2025-03-26',
                    registeredClients: [],
                    serverCapabilities: ['tools'],
                );
            }
        };

        $this->controller = new McpAdminController(registry: $registry, config: $config);
        $this->router = new McpAdminApiRouter($this->controller);
    }

    // ── supports() ──────────────────────────────────────────────────────────

    #[Test]
    public function supportsTrueForMcpAdminControllerRef(): void
    {
        $request = Request::create('/api/mcp/tools', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\McpAdminController::tools');

        self::assertTrue($this->router->supports($request));
    }

    #[Test]
    public function supportsFalseForUnrelatedController(): void
    {
        $request = Request::create('/api/something', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\NotificationController::index');

        self::assertFalse($this->router->supports($request));
    }

    #[Test]
    public function supportsFalseWhenControllerAttributeMissing(): void
    {
        $request = Request::create('/api/mcp/tools', 'GET');
        // no _controller attribute set

        self::assertFalse($this->router->supports($request));
    }

    // ── dispatch: tools ──────────────────────────────────────────────────────

    #[Test]
    public function dispatchesToolsAction(): void
    {
        $request = Request::create('/api/mcp/tools', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\McpAdminController::tools');

        $response = $this->router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('rows', $body['data']);
        self::assertCount(1, $body['data']['rows']);
    }

    // ── dispatch: tool ───────────────────────────────────────────────────────

    #[Test]
    public function dispatchesToolAction(): void
    {
        $request = Request::create('/api/mcp/tools/bimaaji_search', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\McpAdminController::tool');
        $request->attributes->set('name', 'bimaaji_search');

        $response = $this->router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('tool', $body['data']);
    }

    // ── dispatch: serverConfig ───────────────────────────────────────────────

    #[Test]
    public function dispatchesServerConfigAction(): void
    {
        $request = Request::create('/api/mcp/server-config', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\McpAdminController::serverConfig');

        $response = $this->router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('config', $body['data']);
        self::assertSame('streamable-http', $body['data']['config']['transport']);
    }

    // ── unknown action → 404 ─────────────────────────────────────────────────

    #[Test]
    public function returns404ForUnknownAction(): void
    {
        $request = Request::create('/api/mcp/unknown', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\McpAdminController::unknownAction');

        $response = $this->router->handle($request);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('errors', $body);
    }
}
