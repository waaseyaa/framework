<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Mcp\Auth\BearerTokenAuth;
use Waaseyaa\Mcp\Auth\McpAuthInterface;
use Waaseyaa\Mcp\Auth\PublicAnonymousAuth;
use Waaseyaa\Mcp\McpEndpoint;
use Waaseyaa\Mcp\McpServiceProvider;
use Waaseyaa\Routing\Exception\RouteNotFoundException;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * Release gate for F2: a downstream application controls the public `/mcp` tier.
 *
 * The dual of {@see WriteTierAuthOverrideTest}. That test proved P0-1 was fixed
 * for `WriteTierAuthInterface`; the public tier still carried the original
 * defect — `McpServiceProvider::register()` bound `PublicAnonymousAuth` into its
 * OWN bindings, and `ServiceProvider::resolve()` consults those before the
 * cross-provider kernel-services bus, so an application binding was silently
 * shadowed and `/mcp` stayed anonymous regardless.
 *
 * These tests drive the REAL provider wiring, sharing a
 * {@see KernelServicesInterface} bus whose lookup mirrors the kernel's
 * `ProviderRegistryKernelServices::get()` provider-foreach fallthrough, and then
 * resolve `McpEndpoint` exactly as `HttpKernelServiceResolver` does.
 *
 * Acceptance: an application override is honoured under EITHER provider
 * ordering; with no override the anonymous read-only default is unchanged; and
 * `mcp.public.enabled=false` removes the endpoint and its discovery card at the
 * real routing boundary while leaving the write tier routed.
 */
#[CoversClass(McpServiceProvider::class)]
#[CoversClass(PublicAnonymousAuth::class)]
final class PublicTierAuthOverrideTest extends TestCase
{
    private const string TOKEN = 'app-public-token';
    /** Public so the anonymous tool fixture can read it from its own scope. */
    public const string READ_CAP = 'tool.entity.read';

    // ------------------------------------------------ application override (F2)

    #[Test]
    public function an_app_override_makes_an_unauthenticated_request_fail_closed(): void
    {
        $endpoint = $this->publicEndpoint($this->appProvider($this->failClosedAuth()));

        $response = $this->serve($endpoint, $this->rpc('tools/list'), authorizationHeader: null);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(-32001, $this->decode($response)['error']['code']);
    }

    #[Test]
    public function an_app_override_admits_a_mapped_token(): void
    {
        $endpoint = $this->publicEndpoint($this->appProvider($this->failClosedAuth()));

        $response = $this->serve($endpoint, $this->rpc('tools/list'), 'Bearer ' . self::TOKEN);
        $body = $this->decode($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['entity.read'], array_column($body['result']['tools'], 'name'));
    }

    #[Test]
    public function an_app_override_rejects_an_unknown_token(): void
    {
        $endpoint = $this->publicEndpoint($this->appProvider($this->failClosedAuth()));

        $response = $this->serve($endpoint, $this->rpc('tools/list'), 'Bearer not-the-token');

        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * The anti-shadowing guarantee. Provider discovery order is not something an
     * application controls, so the override must win either way. Before the fix
     * the `[mcp, app]` ordering silently lost.
     */
    #[Test]
    public function provider_ordering_cannot_silently_replace_the_application_binding(): void
    {
        foreach ([true, false] as $mcpFirst) {
            $endpoint = $this->publicEndpoint($this->appProvider($this->failClosedAuth()), mcpFirst: $mcpFirst);

            $response = $this->serve($endpoint, $this->rpc('tools/list'), authorizationHeader: null);

            self::assertSame(
                401,
                $response->getStatusCode(),
                sprintf('Override must hold with mcpFirst=%s.', var_export($mcpFirst, true)),
            );
        }
    }

    // ------------------------------------------- anonymous default is preserved

    #[Test]
    public function without_an_override_the_anonymous_read_only_default_is_unchanged(): void
    {
        $endpoint = $this->publicEndpoint($this->appProvider(null));

        $response = $this->serve($endpoint, $this->rpc('tools/list'), authorizationHeader: null);
        $body = $this->decode($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['entity.read'], array_column($body['result']['tools'], 'name'));
    }

    #[Test]
    public function without_an_override_an_anonymous_caller_can_still_execute_a_read_tool(): void
    {
        $endpoint = $this->publicEndpoint($this->appProvider(null));

        $response = $this->serve(
            $endpoint,
            $this->rpc('tools/call', ['name' => 'entity.read', 'arguments' => []]),
            authorizationHeader: null,
        );
        $body = $this->decode($response);

        self::assertSame('read-ok', $body['result']['content'][0]['text']);
        self::assertArrayNotHasKey('isError', $body['result']);
    }

    // ------------------------------- mcp.public.enabled at the routing boundary

    #[Test]
    public function by_default_the_public_endpoint_and_card_are_routable(): void
    {
        $router = $this->routerFor([]);

        self::assertSame('Waaseyaa\Mcp\McpEndpoint::serve', $router->match('/mcp')['_controller']);
        self::assertSame('Waaseyaa\Mcp\McpServerCard::serve', $router->match('/.well-known/mcp.json')['_controller']);
    }

    #[Test]
    public function disabling_the_public_tier_makes_the_endpoint_unroutable(): void
    {
        $router = $this->routerFor(['mcp' => ['public' => ['enabled' => false]]]);

        $this->expectException(RouteNotFoundException::class);
        $router->match('/mcp');
    }

    #[Test]
    public function disabling_the_public_tier_withdraws_the_discovery_card(): void
    {
        $router = $this->routerFor(['mcp' => ['public' => ['enabled' => false]]]);

        $this->expectException(RouteNotFoundException::class);
        $router->match('/.well-known/mcp.json');
    }

    #[Test]
    public function disabling_the_public_tier_leaves_the_write_tier_routable(): void
    {
        $router = $this->routerFor(['mcp' => ['public' => ['enabled' => false]]]);

        self::assertSame(
            'Waaseyaa\Mcp\AuthenticatedMcpEndpoint::serve',
            $router->match('/mcp/write')['_controller'],
        );
    }

    // ---------------------------------------------------------------- harness

    /** @param array<string, mixed> $config */
    private function routerFor(array $config): WaaseyaaRouter
    {
        $router = new WaaseyaaRouter();
        $provider = new McpServiceProvider();
        $provider->setKernelContext('', $config, []);
        $provider->routes($router, new EntityTypeManager(new EventDispatcher()));

        return $router;
    }

    private function failClosedAuth(): McpAuthInterface
    {
        return new BearerTokenAuth([self::TOKEN => $this->account(7)]);
    }

    private function publicEndpoint(ServiceProvider $appProvider, bool $mcpFirst = true): McpEndpoint
    {
        $mcp = new McpServiceProvider();

        /** @var list<ServiceProvider> $providers */
        $providers = $mcpFirst ? [$mcp, $appProvider] : [$appProvider, $mcp];

        // Mirrors ProviderRegistryKernelServices::get() — first provider holding
        // the binding wins. Because McpServiceProvider no longer binds
        // McpAuthInterface, the app's binding is what the bus returns.
        $bus = new class (static fn(): array => $providers) implements KernelServicesInterface {
            /** @param \Closure(): list<ServiceProvider> $providers */
            public function __construct(private \Closure $providers) {}

            public function get(string $abstract): ?object
            {
                foreach (($this->providers)() as $provider) {
                    if (isset($provider->getBindings()[$abstract])) {
                        return $provider->resolve($abstract);
                    }
                }

                return null;
            }
        };

        foreach ($providers as $provider) {
            $provider->setKernelContext('', [], []);
            $provider->setKernelServices($bus);
        }
        foreach ($providers as $provider) {
            $provider->register();
        }

        $endpoint = $mcp->resolve(McpEndpoint::class);
        self::assertInstanceOf(McpEndpoint::class, $endpoint);

        return $endpoint;
    }

    private function appProvider(?McpAuthInterface $auth): ServiceProvider
    {
        $registry = $this->innerRegistry();

        return new class ($auth, $registry) extends ServiceProvider {
            public function __construct(
                private readonly ?McpAuthInterface $auth,
                private readonly ToolRegistryInterface $registry,
            ) {}

            public function register(): void
            {
                $this->singleton(ToolRegistryInterface::class, fn(): ToolRegistryInterface => $this->registry);

                if ($this->auth !== null) {
                    $this->singleton(McpAuthInterface::class, fn(): McpAuthInterface => $this->auth);
                }
            }
        };
    }

    private function innerRegistry(): ToolRegistryInterface
    {
        $tool = $this->readDemoTool();

        return new class ([$tool]) implements ToolRegistryInterface {
            /** @var array<string, AgentTool> */
            private array $map = [];

            /** @param list<AgentTool> $tools */
            public function __construct(array $tools)
            {
                foreach ($tools as $tool) {
                    $this->map[$tool->name] = $tool;
                }
            }

            public function register(AgentTool $tool): void
            {
                $this->map[$tool->name] = $tool;
            }

            public function get(string $name): AgentTool
            {
                return $this->map[$name] ?? throw ToolNotFoundException::forName($name);
            }

            public function has(string $name): bool
            {
                return isset($this->map[$name]);
            }

            public function all(): iterable
            {
                return array_values($this->map);
            }
        };
    }

    /** Non-destructive and on the anonymous read-capability allowlist. */
    private function readDemoTool(): AgentTool
    {
        $impl = new class extends AbstractAgentTool {
            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                $denied = $this->requireCapability(PublicTierAuthOverrideTest::READ_CAP, $account);
                if ($denied !== null) {
                    return $denied;
                }

                return AgentToolResult::success([['type' => 'text', 'text' => 'read-ok']]);
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function description(): string
            {
                return 'A read tool on the anonymous allowlist.';
            }
        };

        return new AgentTool(
            name: 'entity.read',
            capability: self::READ_CAP,
            destructive: false,
            dryRunSupported: false,
            category: 'entity',
            inputSchema: $impl->inputSchema(),
            impl: $impl,
        );
    }

    /** @param array<string, mixed> $params */
    private function rpc(string $method, array $params = []): string
    {
        return \json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ], \JSON_THROW_ON_ERROR);
    }

    private function serve(McpEndpoint $endpoint, string $body, ?string $authorizationHeader): HttpResponse
    {
        $server = [];
        if ($authorizationHeader !== null) {
            $server['HTTP_AUTHORIZATION'] = $authorizationHeader;
        }
        $request = HttpRequest::create('/mcp', 'POST', [], [], [], $server, $body);

        // The session account is forwarded for AppControllerRouter contract
        // compliance; the endpoint resolves the MCP actor from the header.
        return $endpoint->serve($this->account(99), $request);
    }

    /** @return array<string, mixed> */
    private function decode(HttpResponse $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function account(int $id): AccountInterface
    {
        $account = $this->createMock(AuthorizationPrincipalInterface::class);
        $account->method('id')->willReturn($id);
        $account->method('isAuthenticated')->willReturn($id > 0);
        $account->method('hasPermission')->willReturn(true);

        return $account;
    }
}
