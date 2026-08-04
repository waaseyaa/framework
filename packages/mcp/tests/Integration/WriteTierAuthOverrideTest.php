<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Mcp\AuthenticatedMcpEndpoint;
use Waaseyaa\Mcp\Auth\BearerTokenAuth;
use Waaseyaa\Mcp\Auth\WriteTierAuthInterface;
use Waaseyaa\Mcp\McpServiceProvider;

/**
 * Release-gate acceptance for P0-1 (mission
 * wayfinding-stress-remediation-01KVGK4Q): an application's
 * `WriteTierAuthInterface` override actually takes effect on `/mcp/write`.
 *
 * The alpha.233 stress test found the write tier unconfigurable: an app could
 * bind `WriteTierAuthInterface`, but `McpServiceProvider` ALSO bound a package
 * default (`BearerTokenAuth([])`) AND the `AuthenticatedMcpEndpoint` closure
 * resolved its auth with the provider's OWN-bindings-first `resolve()`, so the
 * empty-token default shadowed the override and `/mcp/write` always 401'd.
 *
 * This test drives the REAL provider wiring: it registers `McpServiceProvider`
 * and an application service provider that binds `WriteTierAuthInterface`
 * (a `BearerTokenAuth` mapping a token to a capability-holding account), sharing
 * a {@see KernelServicesInterface} bus whose lookup mirrors the kernel's
 * {@see \Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistryKernelServices::get()}
 * provider-foreach fallthrough. It then resolves `AuthenticatedMcpEndpoint` the
 * same way `HttpKernelServiceResolver` does (the provider's bound closure) and
 * serves requests against it.
 *
 * Acceptance: with the override bound, a mapped token lists and calls the write
 * tool; no-token / junk / unprivileged-token requests all 401; with NO app
 * override the tier fails closed (FR-001..FR-003, NFR-002).
 */
#[CoversClass(McpServiceProvider::class)]
#[CoversClass(AuthenticatedMcpEndpoint::class)]
final class WriteTierAuthOverrideTest extends TestCase
{
    private const string TOKEN = 'app-write-token';
    private const string WRITE_CAP = 'present guided content';

    #[Test]
    public function an_app_override_is_honoured_so_a_mapped_token_lists_and_calls_the_write_tool(): void
    {
        $endpoint = $this->writeEndpointWithOverride($this->account(7, hasCapability: true));

        $list = $this->decode($this->serve($endpoint, $this->rpc('tools/list'), 'Bearer ' . self::TOKEN));
        $names = array_map(static fn(array $t): string => $t['name'], $list['result']['tools']);
        self::assertContains('wayfinding_write_demo', $names, 'The app-bound write tier must expose the write tool.');

        $call = $this->decode($this->serve(
            $endpoint,
            $this->rpc('tools/call', ['name' => 'wayfinding_write_demo', 'arguments' => []]),
            'Bearer ' . self::TOKEN,
        ));
        self::assertFalse($call['result']['isError'] ?? false, 'A capability-holding mapped token must be able to call the write tool.');
        self::assertSame('wrote', $call['result']['content'][0]['text']);
    }

    #[Test]
    public function without_an_app_override_the_write_tier_fails_closed(): void
    {
        // No app binds WriteTierAuthInterface — resolveWriteTierAuth() must fall
        // back to the empty-token BearerTokenAuth, so even the would-be token 401s.
        $endpoint = $this->writeEndpointWithoutOverride();

        $response = $this->serve($endpoint, $this->rpc('tools/list'), 'Bearer ' . self::TOKEN);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function no_bearer_token_is_unauthorized(): void
    {
        $endpoint = $this->writeEndpointWithOverride($this->account(7, hasCapability: true));

        self::assertSame(401, $this->serve($endpoint, $this->rpc('tools/list'), authorizationHeader: null)->getStatusCode());
    }

    #[Test]
    public function a_junk_token_is_unauthorized(): void
    {
        $endpoint = $this->writeEndpointWithOverride($this->account(7, hasCapability: true));

        self::assertSame(401, $this->serve($endpoint, $this->rpc('tools/list'), 'Bearer not-the-token')->getStatusCode());
    }

    #[Test]
    public function an_unprivileged_token_not_in_the_app_map_is_unauthorized(): void
    {
        // The app mints write-tier tokens ONLY for privileged agents, so an
        // unprivileged caller simply holds no valid token → 401 at the auth layer.
        $endpoint = $this->writeEndpointWithOverride($this->account(7, hasCapability: true));

        self::assertSame(401, $this->serve($endpoint, $this->rpc('tools/list'), 'Bearer unprivileged-caller-token')->getStatusCode());
    }

    #[Test]
    public function the_mcp_provider_does_not_bind_write_tier_auth_so_an_app_override_is_never_shadowed(): void
    {
        // Regression guard for the P0-1 precedence fix: McpServiceProvider must
        // NOT register a local WriteTierAuthInterface binding (that own-binding
        // shadow was the root cause). The seam is the cross-provider bus only.
        $mcp = new McpServiceProvider();
        $mcp->setKernelContext('', [], []);
        $mcp->register();

        self::assertArrayNotHasKey(
            WriteTierAuthInterface::class,
            $mcp->getBindings(),
            'McpServiceProvider must not bind a package WriteTierAuthInterface default; it would shadow an app override.',
        );
    }

    /**
     * Wire McpServiceProvider + an app provider that binds the write-tier auth
     * override and the agent tool registry, sharing one kernel-services bus, and
     * resolve the AuthenticatedMcpEndpoint via the provider's bound closure.
     */
    private function writeEndpointWithOverride(AccountInterface $tokenAccount): AuthenticatedMcpEndpoint
    {
        $appProvider = $this->appProvider(
            writeAuth: new BearerTokenAuth([self::TOKEN => $tokenAccount]),
            registry: $this->innerRegistry(),
        );

        return $this->resolveWriteEndpoint($appProvider);
    }

    private function writeEndpointWithoutOverride(): AuthenticatedMcpEndpoint
    {
        // App provider binds the registry but NOT WriteTierAuthInterface.
        $appProvider = $this->appProvider(writeAuth: null, registry: $this->innerRegistry());

        return $this->resolveWriteEndpoint($appProvider);
    }

    private function resolveWriteEndpoint(ServiceProvider $appProvider): AuthenticatedMcpEndpoint
    {
        $mcp = new McpServiceProvider();

        /** @var list<ServiceProvider> $providers */
        $providers = [$mcp, $appProvider];

        // A KernelServicesInterface whose lookup mirrors
        // ProviderRegistryKernelServices::get()'s provider-foreach fallthrough
        // (first provider with the binding wins). Because McpServiceProvider no
        // longer binds WriteTierAuthInterface, the app provider's binding is what
        // the bus returns — the exact path the fix relies on.
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
            // This test is scoped to auth resolution, not auditing. Durable
            // write auditing (#2177 F4) and the human-approval gate (#2177 F1)
            // both default ON and fail closed when unwireable, so both are
            // explicitly opted out here rather than silently satisfied — the
            // fail-closed wiring has its own coverage in McpServiceProviderTest.
            $provider->setKernelContext('', ['mcp' => [
                'rate_limit' => ['max_requests' => 0],
                'write_tier' => [
                    'durable_audit' => false,
                    'approval' => ['enabled' => false],
                ],
            ]], []);
            $provider->setKernelServices($bus);
        }
        foreach ($providers as $provider) {
            $provider->register();
        }

        $endpoint = $mcp->resolve(AuthenticatedMcpEndpoint::class);
        self::assertInstanceOf(AuthenticatedMcpEndpoint::class, $endpoint);

        return $endpoint;
    }

    private function appProvider(?WriteTierAuthInterface $writeAuth, ToolRegistryInterface $registry): ServiceProvider
    {
        return new class ($writeAuth, $registry) extends ServiceProvider {
            public function __construct(
                private readonly ?WriteTierAuthInterface $writeAuth,
                private readonly ToolRegistryInterface $registry,
            ) {}

            public function register(): void
            {
                // The agent tool registry the write tier scopes over.
                $this->singleton(ToolRegistryInterface::class, fn(): ToolRegistryInterface => $this->registry);

                // The app override point (only when provided).
                if ($this->writeAuth !== null) {
                    $this->singleton(WriteTierAuthInterface::class, fn(): WriteTierAuthInterface => $this->writeAuth);
                }
            }
        };
    }

    private function innerRegistry(): ToolRegistryInterface
    {
        $tool = $this->writeDemoTool();

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

    private function writeDemoTool(): AgentTool
    {
        $impl = new class extends AbstractAgentTool {
            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                $denied = $this->requireCapability('present guided content', $account);
                if ($denied !== null) {
                    return $denied;
                }

                return AgentToolResult::success([['type' => 'text', 'text' => 'wrote']]);
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function description(): string
            {
                return 'A destructive demo write tool gated by present guided content.';
            }
        };

        return new AgentTool(
            name: 'wayfinding_write_demo',
            capability: self::WRITE_CAP,
            destructive: true,
            dryRunSupported: false,
            category: 'wayfinding',
            inputSchema: $impl->inputSchema(),
            impl: $impl,
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function rpc(string $method, array $params = []): string
    {
        return \json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ], \JSON_THROW_ON_ERROR);
    }

    private function serve(AuthenticatedMcpEndpoint $endpoint, string $body, ?string $authorizationHeader): HttpResponse
    {
        $server = [];
        if ($authorizationHeader !== null) {
            $server['HTTP_AUTHORIZATION'] = $authorizationHeader;
        }
        $server += [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ];
        $request = HttpRequest::create('/mcp/write', 'POST', [], [], [], $server, $body);

        // The session account is forwarded for AppControllerRouter contract
        // compliance; the inner endpoint resolves the actor from the bearer token.
        return $endpoint->serve($this->account(99, hasCapability: false), $request);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(HttpResponse $response): array
    {
        self::assertSame(200, $response->getStatusCode(), 'Expected a dispatched JSON-RPC response.');

        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function account(int $id, bool $hasCapability): AccountInterface
    {
        $account = $this->createMock(AuthorizationPrincipalInterface::class);
        $account->method('id')->willReturn($id);
        $account->method('isAuthenticated')->willReturn($id > 0);
        $account->method('hasPermission')->willReturnCallback(
            static fn(string $permission): bool => $hasCapability && $permission === self::WRITE_CAP,
        );

        return $account;
    }
}
