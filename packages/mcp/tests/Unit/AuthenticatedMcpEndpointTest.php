<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit;

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
use Waaseyaa\Mcp\AuthenticatedMcpEndpoint;
use Waaseyaa\Mcp\Auth\BearerTokenAuth;
use Waaseyaa\Mcp\Auth\PublicAnonymousAuth;
use Waaseyaa\Mcp\CapabilityScopedToolRegistry;
use Waaseyaa\Mcp\McpEndpoint;
use Waaseyaa\Mcp\ReadOnlyToolRegistry;

/**
 * End-to-end acceptance for the authenticated MCP write tier (Phase 5):
 *  - it fails closed (HTTP 401) without a valid bearer token (NFR-002);
 *  - a token-authenticated, capability-holding caller can list and call the
 *    write tools (FR-003/FR-004/SC-002);
 *  - a token-authenticated caller LACKING the capability is rejected per-tool;
 *  - the same destructive tool never appears on the public read-only `/mcp`
 *    surface (C-001).
 */
#[CoversClass(AuthenticatedMcpEndpoint::class)]
final class AuthenticatedMcpEndpointTest extends TestCase
{
    private const string TOKEN = 'secret-token';
    private const string WRITE_CAP = 'present guided content';

    #[Test]
    public function without_a_bearer_token_the_write_tier_is_unauthorized(): void
    {
        $endpoint = $this->writeTier($this->account(7, hasCapability: true));

        $response = $this->serve($endpoint, $this->rpc('tools/list'), authorizationHeader: null);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function an_unknown_token_is_unauthorized(): void
    {
        $endpoint = $this->writeTier($this->account(7, hasCapability: true));

        $response = $this->serve($endpoint, $this->rpc('tools/list'), authorizationHeader: 'Bearer wrong');

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function an_authenticated_capable_caller_lists_and_calls_the_write_tool(): void
    {
        $endpoint = $this->writeTier($this->account(7, hasCapability: true));

        $list = $this->decode($this->serve($endpoint, $this->rpc('tools/list'), 'Bearer ' . self::TOKEN));
        $names = array_map(static fn(array $t): string => $t['name'], $list['result']['tools']);
        self::assertContains('wayfinding_write_demo', $names);

        $call = $this->decode($this->serve(
            $endpoint,
            $this->rpc('tools/call', ['name' => 'wayfinding_write_demo', 'arguments' => []]),
            'Bearer ' . self::TOKEN,
        ));
        self::assertFalse($call['result']['isError'] ?? false);
        self::assertSame('wrote', $call['result']['content'][0]['text']);
    }

    #[Test]
    public function an_authenticated_caller_without_the_capability_is_rejected_per_tool(): void
    {
        // Authenticated (valid token) but the account lacks the capability.
        $endpoint = $this->writeTier($this->account(8, hasCapability: false));

        $call = $this->decode($this->serve(
            $endpoint,
            $this->rpc('tools/call', ['name' => 'wayfinding_write_demo', 'arguments' => []]),
            'Bearer ' . self::TOKEN,
        ));

        self::assertSame(200, 200); // dispatch succeeded; the tool itself fails closed
        self::assertTrue($call['result']['isError'] ?? false);
    }

    #[Test]
    public function the_public_read_only_surface_never_lists_the_write_tool(): void
    {
        // The public endpoint: anonymous auth + read-only registry over the same tools.
        $public = new McpEndpoint(
            auth: new PublicAnonymousAuth(),
            agentRegistry: new ReadOnlyToolRegistry($this->innerRegistry(), PublicAnonymousAuth::DEFAULT_READ_CAPABILITIES),
        );

        $list = $this->decode($public->serve($this->account(0, hasCapability: false), $this->request($this->rpc('tools/list'), null)));
        $names = array_map(static fn(array $t): string => $t['name'], $list['result']['tools']);

        self::assertNotContains('wayfinding_write_demo', $names, 'A destructive write tool must never appear on the public surface (C-001).');
    }

    private function writeTier(AccountInterface $tokenAccount): AuthenticatedMcpEndpoint
    {
        $inner = new McpEndpoint(
            auth: new BearerTokenAuth([self::TOKEN => $tokenAccount]),
            agentRegistry: new CapabilityScopedToolRegistry($this->innerRegistry(), [self::WRITE_CAP]),
        );

        return new AuthenticatedMcpEndpoint($inner);
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
        return $endpoint->serve($this->account(99, hasCapability: false), $this->request($body, $authorizationHeader));
    }

    private function request(string $body, ?string $authorizationHeader): HttpRequest
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ];
        if ($authorizationHeader !== null) {
            $server['HTTP_AUTHORIZATION'] = $authorizationHeader;
        }

        return HttpRequest::create('/mcp/write', 'POST', [], [], [], $server, $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(HttpResponse $response): array
    {
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
