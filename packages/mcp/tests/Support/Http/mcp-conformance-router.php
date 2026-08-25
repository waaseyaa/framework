<?php

declare(strict_types=1);

/**
 * `php -S` router script for the MCP wire-conformance harness.
 *
 * Booted by {@see \Waaseyaa\Mcp\Tests\Support\Http\McpConformanceServer} so a
 * generic JSON-RPC client can drive `/mcp` over a real socket. Everything the
 * endpoint needs is wired from the REAL provider — `McpServiceProvider` plus an
 * application provider supplying the tool registry, resolved through the same
 * kernel-services bus fallthrough the kernel uses — so the bytes on the wire are
 * produced by the production encode path and nothing else.
 *
 * The tool on the registry is a fixture, deliberately: this harness asserts the
 * ENDPOINT's envelope (status, headers, JSON-RPC shape), not any individual
 * tool's payload, so it stays independent of concurrent work on the shipped
 * tools' content blocks.
 *
 * Routes:
 *   GET  /__ready — 204 once the endpoint can be constructed (readiness probe).
 *   POST /mcp     — the public MCP tier.
 *   anything else — 404.
 */

use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Mcp\McpEndpoint;
use Waaseyaa\Mcp\McpServiceProvider;
use Waaseyaa\Testing\Factory\AuthorizationPrincipalFactory;
use Waaseyaa\Testing\Kernel\KernelServicesFixture;

$repositoryRoot = getenv('WAASEYAA_MCP_CONFORMANCE_ROOT');
if (!is_string($repositoryRoot) || $repositoryRoot === '') {
    $repositoryRoot = dirname(__DIR__, 5);
}

// require_once, not require: a diagnostic bootstrap may have loaded the
// autoloader (and a substitute class definition) before including this router.
require_once $repositoryRoot . '/vendor/autoload.php';

/** The capability the anonymous tier grants; the fixture tool sits on it. */
const WAASEYAA_MCP_CONFORMANCE_READ_CAPABILITY = 'tool.entity.read';

/**
 * A non-destructive read tool on the anonymous allowlist.
 *
 * Its result is intentionally the plainest conformant thing a tool can return,
 * so every assertion the suite makes is about the endpoint's envelope.
 */
function waaseyaa_mcp_conformance_tool(): AgentTool
{
    $impl = new class extends AbstractAgentTool {
        public function execute(array $arguments, AccountInterface $account): AgentToolResult
        {
            $denied = $this->requireCapability(WAASEYAA_MCP_CONFORMANCE_READ_CAPABILITY, $account);
            if ($denied !== null) {
                return $denied;
            }

            return AgentToolResult::success([['type' => 'text', 'text' => 'conformance-ok']]);
        }

        public function inputSchema(): array
        {
            return ['type' => 'object', 'properties' => []];
        }

        public function description(): string
        {
            return 'Wire-conformance fixture tool on the anonymous read allowlist.';
        }
    };

    return new AgentTool(
        name: 'entity.read',
        capability: WAASEYAA_MCP_CONFORMANCE_READ_CAPABILITY,
        destructive: false,
        dryRunSupported: false,
        category: 'entity',
        inputSchema: $impl->inputSchema(),
        impl: $impl,
    );
}

function waaseyaa_mcp_conformance_registry(): ToolRegistryInterface
{
    return new class ([waaseyaa_mcp_conformance_tool()]) implements ToolRegistryInterface {
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

/**
 * Real provider wiring, mirroring ProviderRegistryKernelServices::get() — the
 * first provider holding a binding wins.
 */
function waaseyaa_mcp_conformance_endpoint(): McpEndpoint
{
    $mcp = new McpServiceProvider();
    $app = new class (waaseyaa_mcp_conformance_registry()) extends ServiceProvider {
        public function __construct(private readonly ToolRegistryInterface $registry) {}

        public function register(): void
        {
            $this->singleton(ToolRegistryInterface::class, fn(): ToolRegistryInterface => $this->registry);
        }
    };

    /** @var list<ServiceProvider> $providers */
    $providers = [$mcp, $app];

    $bus = new KernelServicesFixture(fallback: static function (string $abstract) use ($providers): ?object {
        foreach ($providers as $provider) {
            if (isset($provider->getBindings()[$abstract])) {
                return $provider->resolve($abstract);
            }
        }

        return null;
    });

    foreach ($providers as $provider) {
        // Durable rate limiting has its own coverage; this harness isolates the
        // wire contract, so the limiter is off and every request is admitted.
        $provider->setKernelContext('', ['mcp' => ['rate_limit' => ['max_requests' => 0]]], []);
        $provider->setKernelServices($bus);
    }
    foreach ($providers as $provider) {
        $provider->register();
    }

    $endpoint = $mcp->resolve(McpEndpoint::class);
    if (!$endpoint instanceof McpEndpoint) {
        throw new RuntimeException('MCP conformance router could not resolve McpEndpoint.');
    }

    return $endpoint;
}

/** Emit exactly the bytes the endpoint produced — no re-encoding, no charset fixups. */
function waaseyaa_mcp_conformance_emit(HttpResponse $response): void
{
    http_response_code($response->getStatusCode());
    foreach ($response->headers->allPreserveCase() as $name => $values) {
        foreach ((array) $values as $index => $value) {
            header($name . ': ' . (string) $value, $index === 0);
        }
    }
    echo (string) $response->getContent();
}

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if ($path === '/__ready') {
    // Constructing the endpoint is what "ready" means: a bound port with a
    // broken bootstrap must not read as ready.
    waaseyaa_mcp_conformance_endpoint();
    http_response_code(204);

    return;
}

if ($path !== '/mcp') {
    http_response_code(404);
    header('Content-Type: application/json');
    echo '{"error":"not found"}';

    return;
}

// The session account exists only for AppControllerRouter contract compliance;
// the public tier resolves its actor from the Authorization header. Anonymous
// here so the harness never accidentally proves an authenticated path.
waaseyaa_mcp_conformance_emit(
    waaseyaa_mcp_conformance_endpoint()->serve(
        AuthorizationPrincipalFactory::anonymous(),
        HttpRequest::createFromGlobals(),
    ),
);
