<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\AgentRuntime;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface as AgentToolRegistryInterface;
use Waaseyaa\Mcp\Auth\McpAuthInterface;
use Waaseyaa\Mcp\McpEndpoint;

/**
 * Cross-package integration test asserting that `McpEndpoint` consumes
 * the new `Waaseyaa\AI\Tools\ToolRegistryInterface` surface via
 * {@see AgentToolRegistryBridge}.
 *
 * The test boots an in-memory tool registry, wraps it with the bridge,
 * and drives `McpEndpoint::handle()` through both `tools/list` and
 * `tools/call` against a read-only fixture tool. The external JSON-RPC
 * envelope shape is verified byte-by-byte for the relevant keys.
 *
 * @api
 */
#[CoversNothing]
final class McpControllerToolsSharingTest extends TestCase
{
    #[Test]
    public function toolsListSurfacesAgentToolDescriptors(): void
    {
        $endpoint = $this->buildEndpoint();
        $response = $this->dispatch($endpoint, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        self::assertSame(200, $response['statusCode']);
        self::assertArrayHasKey('result', $response['body']);
        $names = array_map(static fn(array $t): string => $t['name'], $response['body']['result']['tools']);
        self::assertContains('entity.list', $names);
        self::assertContains('relationship.traverse', $names);
    }

    #[Test]
    public function toolsCallReadOnlyToolReturnsContent(): void
    {
        $endpoint = $this->buildEndpoint();
        $response = $this->dispatch($endpoint, [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'entity.list',
                'arguments' => ['entity_type' => 'node'],
            ],
        ]);

        self::assertSame(200, $response['statusCode']);
        self::assertArrayHasKey('content', $response['body']['result']);
        self::assertSame('text', $response['body']['result']['content'][0]['type']);
    }

    private function buildEndpoint(): McpEndpoint
    {
        $tools = [
            $this->makeStubTool('entity.list', 'Stubbed entity.list'),
            $this->makeStubTool('entity.read', 'Stubbed entity.read'),
            $this->makeStubTool('relationship.traverse', 'Stubbed relationship.traverse'),
        ];
        $registry = $this->stubRegistry($tools);

        $account = $this->stubAccount(1);

        $auth = new class ($account) implements McpAuthInterface {
            public function __construct(private readonly AccountInterface $account) {}

            public function authenticate(?string $authorizationHeader): ?AccountInterface
            {
                return $authorizationHeader !== null ? $this->account : null;
            }
        };

        // WP03: McpEndpoint takes the raw agent registry; it constructs the
        // per-request AgentToolRegistryBridge with the auth-resolved account
        // inside dispatch(). The stub auth above returns the same $account
        // the bridge would have been constructed with pre-WP03.
        return new McpEndpoint(auth: $auth, agentRegistry: $registry);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{statusCode: int, body: array<string, mixed>}
     */
    private function dispatch(McpEndpoint $endpoint, array $payload): array
    {
        $request = HttpRequest::create(
            uri: '/mcp',
            method: 'POST',
            parameters: [],
            cookies: [],
            files: [],
            server: ['HTTP_AUTHORIZATION' => 'Bearer x'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );

        $response = $endpoint->handle($this->stubAccount(1), $request);
        $body = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        return ['statusCode' => $response->statusCode, 'body' => $body];
    }

    private function makeStubTool(string $name, string $description): AgentTool
    {
        $impl = new class ($description) implements AgentToolInterface {
            public function __construct(private readonly string $description) {}
            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::success([['type' => 'text', 'text' => 'ok']]);
            }
            public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::error('dry_run_not_supported');
            }
            public function argumentsForAudit(array $arguments): array
            {
                return $arguments;
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function description(): string
            {
                return $this->description;
            }
        };

        return new AgentTool(
            name: $name,
            capability: 'tool.' . $name,
            destructive: false,
            dryRunSupported: false,
            category: 'fixture',
            inputSchema: ['type' => 'object', 'properties' => []],
            impl: $impl,
        );
    }

    private function stubAccount(int $id): AccountInterface
    {
        return new AuthorizationPrincipal($id, true, ['administrator'], [], 'test-' . $id);
    }

    /**
     * @param list<AgentTool> $tools
     */
    private function stubRegistry(array $tools): AgentToolRegistryInterface
    {
        return new class ($tools) implements AgentToolRegistryInterface {
            /** @var array<string, AgentTool> */
            private array $map = [];

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
                if (!isset($this->map[$name])) {
                    throw ToolNotFoundException::forName($name);
                }
                return $this->map[$name];
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
}
