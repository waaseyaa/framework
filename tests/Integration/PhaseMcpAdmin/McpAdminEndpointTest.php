<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseMcpAdmin;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Access\AccessChecker;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Api\ApiServiceProvider;
use Waaseyaa\Api\Controller\McpAdminController;
use Waaseyaa\Api\Http\Router\McpAdminApiRouter;
use Waaseyaa\Api\McpAdmin\ServerConfigReadModelInterface;
use Waaseyaa\Api\McpAdmin\ToolRegistryReadModelInterface;
use Waaseyaa\Api\McpAdmin\ToolRegistryRow;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\BuiltinRouteRegistrar;
use Waaseyaa\Mcp\Admin\ServerConfigReadModel;
use Waaseyaa\Mcp\Auth\BearerTokenAuth;
use Waaseyaa\Mcp\McpServiceProvider;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * Kernel-boot integration test for the M5C MCP admin read-only surface (WP01 T004).
 *
 * FR binding guard: this test MUST FAIL if either of the two McpServiceProvider
 * bindings (ToolRegistryReadModelInterface, ServerConfigReadModelInterface) is
 * removed. If absent, `ApiServiceProvider::resolveOptional()` returns null, the
 * router is not wired, and the assertions below will fail.
 *
 * Asserts:
 *   1. `BuiltinRouteRegistrar` registers all three routes with `_role: admin`.
 *   2. `AccessChecker` admits admin and 403s non-admin on all three routes.
 *   3. `GET /api/mcp/tools` returns a tools list with camelCase shape.
 *   4. `GET /api/mcp/tools/{name}` returns tool detail with inputSchema.
 *   5. `GET /api/mcp/server-config` returns snapshot with tokenFingerprint only (NFR-003).
 *   6. No plaintext token appears in any serialised response (NFR-003).
 *
 * Spec: kitty-specs/mcp-endpoint-admin-m5c-01KSEFTB/spec.md
 */
#[CoversNothing]
final class McpAdminEndpointTest extends TestCase
{
    private WaaseyaaRouter $waaseyaaRouter;
    private McpAdminApiRouter $mcpRouter;
    private string $plaintextToken;

    protected function setUp(): void
    {
        $this->plaintextToken = 'supersecret-token-64-hexchars-abcdef1234567890abcdef1234567890ab';

        $entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $this->waaseyaaRouter = new WaaseyaaRouter(new RequestContext('', 'GET'));

        // WP5: routes are now registered by ApiServiceProvider::routes().
        $registrar = new BuiltinRouteRegistrar($entityTypeManager, [new ApiServiceProvider()]);
        $registrar->register($this->waaseyaaRouter);

        $account = $this->adminAccount();
        $auth = new BearerTokenAuth(tokens: [$this->plaintextToken => $account]);
        $configModel = new ServerConfigReadModel(auth: $auth);

        $registry = $this->createFakeRegistry();

        $controller = new McpAdminController(
            registry: $registry,
            config: $configModel,
        );

        $this->mcpRouter = new McpAdminApiRouter($controller);
    }

    // ── Route registration ───────────────────────────────────────────────────

    #[Test]
    public function allThreeMcpAdminRoutesAreRegistered(): void
    {
        $routes = $this->waaseyaaRouter->getRouteCollection();

        $toolsIndex = $routes->get('api.mcp.admin.tools.index');
        $toolsShow = $routes->get('api.mcp.admin.tools.show');
        $serverConfig = $routes->get('api.mcp.admin.server-config');

        self::assertNotNull($toolsIndex, 'GET /api/mcp/tools must be registered');
        self::assertSame('/api/mcp/tools', $toolsIndex->getPath());
        self::assertSame(['GET'], $toolsIndex->getMethods());

        self::assertNotNull($toolsShow, 'GET /api/mcp/tools/{name} must be registered');
        self::assertSame('/api/mcp/tools/{name}', $toolsShow->getPath());
        self::assertSame(['GET'], $toolsShow->getMethods());

        self::assertNotNull($serverConfig, 'GET /api/mcp/server-config must be registered');
        self::assertSame('/api/mcp/server-config', $serverConfig->getPath());
        self::assertSame(['GET'], $serverConfig->getMethods());
    }

    #[Test]
    public function allThreeRoutesRequireAdminRole(): void
    {
        $routes = $this->waaseyaaRouter->getRouteCollection();

        foreach (['api.mcp.admin.tools.index', 'api.mcp.admin.tools.show', 'api.mcp.admin.server-config'] as $routeName) {
            $route = $routes->get($routeName);
            self::assertNotNull($route, "Route {$routeName} must be registered");
            self::assertSame('admin', $route->getOption('_role'), "Route {$routeName} must require admin role");
        }
    }

    // ── Access control ───────────────────────────────────────────────────────

    #[Test]
    public function accessCheckerAllowsAdminAndRejectsNonAdmin(): void
    {
        $routes = $this->waaseyaaRouter->getRouteCollection();
        $checker = new AccessChecker();
        $admin = $this->adminAccount();
        $nonAdmin = $this->nonAdminAccount();

        foreach (['api.mcp.admin.tools.index', 'api.mcp.admin.tools.show', 'api.mcp.admin.server-config'] as $routeName) {
            $route = $routes->get($routeName);
            self::assertNotNull($route, "Route {$routeName} must be registered");

            $adminResult = $checker->check($route, $admin);
            self::assertTrue($adminResult->isAllowed(), "Admin must be allowed on {$routeName}");

            $nonAdminResult = $checker->check($route, $nonAdmin);
            self::assertTrue($nonAdminResult->isForbidden(), "Non-admin must be forbidden on {$routeName}");
        }
    }

    // ── GET /api/mcp/tools ───────────────────────────────────────────────────

    #[Test]
    public function toolsEndpointReturnsCamelCaseRows(): void
    {
        $request = Request::create('/api/mcp/tools', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\McpAdminController::tools');

        self::assertTrue($this->mcpRouter->supports($request));
        $response = $this->mcpRouter->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('rows', $body['data']);
        self::assertCount(2, $body['data']['rows']);

        $first = $body['data']['rows'][0];
        self::assertArrayHasKey('name', $first);
        self::assertArrayHasKey('category', $first);
        self::assertArrayHasKey('requiredCapabilities', $first);
        self::assertSame('bimaaji_search', $first['name']);
    }

    // ── GET /api/mcp/tools/{name} ────────────────────────────────────────────

    #[Test]
    public function toolShowEndpointReturnsDetailWithInputSchema(): void
    {
        $request = Request::create('/api/mcp/tools/bimaaji_search', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\McpAdminController::tool');
        $request->attributes->set('name', 'bimaaji_search');

        $response = $this->mcpRouter->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('tool', $body['data']);
        $tool = $body['data']['tool'];
        self::assertNotNull($tool);
        self::assertSame('bimaaji_search', $tool['name']);
        self::assertArrayHasKey('inputSchema', $tool);
        self::assertArrayHasKey('requiredCapabilities', $tool);
        self::assertArrayHasKey('recentInvocations', $tool);
    }

    #[Test]
    public function toolShowEndpointReturnsNullForUnknownTool(): void
    {
        $request = Request::create('/api/mcp/tools/nonexistent', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\McpAdminController::tool');
        $request->attributes->set('name', 'nonexistent');

        $response = $this->mcpRouter->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertNull($body['data']['tool']);
    }

    // ── GET /api/mcp/server-config ────────────────────────────────────────────

    #[Test]
    public function serverConfigEndpointReturnsCamelCaseSnapshot(): void
    {
        $request = Request::create('/api/mcp/server-config', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\McpAdminController::serverConfig');

        $response = $this->mcpRouter->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('config', $body['data']);
        $config = $body['data']['config'];
        self::assertSame('streamable-http', $config['transport']);
        self::assertSame('2026-07-28', $config['protocolVersion']);
        self::assertArrayHasKey('registeredClients', $config);
        self::assertCount(1, $config['registeredClients']);

        $client = $config['registeredClients'][0];
        self::assertArrayHasKey('tokenFingerprint', $client);
        // NFR-003: fingerprint must be 16-char lowercase hex.
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $client['tokenFingerprint']);
    }

    // ── NFR-003: no plaintext token in any response ──────────────────────────

    #[Test]
    public function serverConfigResponseContainsNoPlaintextToken(): void
    {
        $request = Request::create('/api/mcp/server-config', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\McpAdminController::serverConfig');

        $response = $this->mcpRouter->handle($request);
        $rawBody = (string) $response->getContent();

        self::assertStringNotContainsString($this->plaintextToken, $rawBody);
        // Verify the fingerprint IS present.
        self::assertStringContainsString(substr(hash('sha256', $this->plaintextToken), 0, 16), $rawBody);
    }

    // ── McpServiceProvider binding guard ─────────────────────────────────────

    #[Test]
    public function mcpServiceProviderBindsBothAdminInterfaces(): void
    {
        // Prove both admin bindings exist in the service provider so
        // ApiServiceProvider::resolveOptional() can find them at boot time.
        $sp = new McpServiceProvider();
        $sp->setKernelContext(__DIR__, [], []);
        $sp->register();

        $bindings = $sp->getBindings();

        self::assertArrayHasKey(
            ToolRegistryReadModelInterface::class,
            $bindings,
            'McpServiceProvider must bind ToolRegistryReadModelInterface',
        );
        self::assertArrayHasKey(
            ServerConfigReadModelInterface::class,
            $bindings,
            'McpServiceProvider must bind ServerConfigReadModelInterface',
        );
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function createFakeRegistry(): ToolRegistryReadModelInterface
    {
        return new class implements ToolRegistryReadModelInterface {
            public function listTools(): array
            {
                return [
                    new ToolRegistryRow('bimaaji_search', 'Search specs', 'bimaaji', ['bimaaji.read']),
                    new ToolRegistryRow('entity_read', 'Read entity', 'entity', ['entity.view']),
                ];
            }

            public function findTool(string $name): ?\Waaseyaa\Api\McpAdmin\ToolDetail
            {
                if ($name === 'bimaaji_search') {
                    return new \Waaseyaa\Api\McpAdmin\ToolDetail(
                        name: 'bimaaji_search',
                        summary: 'Search specs',
                        description: 'Search the specs directory.',
                        category: 'bimaaji',
                        requiredCapabilities: ['bimaaji.read'],
                        inputSchema: ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]],
                        recentInvocations: [],
                    );
                }
                return null;
            }
        };
    }

    private function adminAccount(): AccountInterface
    {
        return new class implements AccountInterface {
            public function id(): int
            {
                return 1;
            }
            public function getRoles(): array
            {
                return ['admin'];
            }
            public function hasPermission(string $permission): bool
            {
                return true;
            }
            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }

    private function nonAdminAccount(): AccountInterface
    {
        return new class implements AccountInterface {
            public function id(): int
            {
                return 99;
            }
            public function getRoles(): array
            {
                return ['editor'];
            }
            public function hasPermission(string $permission): bool
            {
                return false;
            }
            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }
}
