<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface as AgentToolRegistryInterface;
use Waaseyaa\Mcp\Auth\McpAuthInterface;
use Waaseyaa\Mcp\McpEndpoint;
use Waaseyaa\Mcp\McpResponse;

#[CoversClass(McpEndpoint::class)]
#[CoversClass(McpResponse::class)]
final class McpEndpointTest extends TestCase
{
    private McpAuthInterface $auth;
    private AccountInterface $account;
    /** @var list<AgentTool> */
    private array $tools = [];

    protected function setUp(): void
    {
        $this->auth = $this->createMock(McpAuthInterface::class);
        $this->account = $this->createMock(AuthorizationPrincipalInterface::class);
        $this->account->method('id')->willReturn(1);
        $this->account->method('hasPermission')->willReturn(true);
        $this->tools = [];
    }

    private function createEndpoint(): McpEndpoint
    {
        return new McpEndpoint(
            auth: $this->auth,
            agentRegistry: $this->stubAgentRegistry($this->tools),
        );
    }

    private function dispatch(McpEndpoint $endpoint, string $method, string $body, ?string $authorizationHeader): McpResponse
    {
        $headers = [];
        if ($authorizationHeader !== null) {
            $headers['HTTP_AUTHORIZATION'] = $authorizationHeader;
        }

        $request = HttpRequest::create('/_mcp', $method, [], [], [], $headers, $body);

        return $endpoint->handle($this->account, $request);
    }

    /**
     * Build a small {@see AgentTool} fixture backed by an anonymous-class
     * {@see AgentToolInterface}. The impl echoes the call arguments under
     * `{type: text, text: <json>}` so tests can assert the bridge forwarded
     * them.
     */
    private function makeTool(string $name, array $schema = []): AgentTool
    {
        $impl = new class implements AgentToolInterface {
            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::success([
                    ['type' => 'text', 'text' => \json_encode(['operation' => 'echo', ...$arguments], \JSON_THROW_ON_ERROR)],
                ]);
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
                return 'Test tool fixture.';
            }
        };

        return new AgentTool(
            name: $name,
            capability: 'tool.test',
            destructive: false,
            dryRunSupported: false,
            category: 'test',
            inputSchema: $schema !== [] ? $schema : ['type' => 'object', 'properties' => []],
            impl: $impl,
        );
    }

    /**
     * @param list<AgentTool> $tools
     */
    private function stubAgentRegistry(array $tools): AgentToolRegistryInterface
    {
        return new class ($tools) implements AgentToolRegistryInterface {
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

    #[Test]
    public function missingAuthHeaderReturns401(): void
    {
        $this->auth->method('authenticate')->willReturn(null);

        $endpoint = $this->createEndpoint();
        $response = $this->dispatch($endpoint, 'POST', '{"jsonrpc":"2.0","id":1,"method":"tools/list"}', null);

        self::assertSame(401, $response->statusCode);
        $decoded = \json_decode($response->body, true);
        self::assertSame(-32001, $decoded['error']['code']);
        self::assertSame('Unauthorized', $decoded['error']['message']);
    }

    #[Test]
    public function invalidTokenReturns401(): void
    {
        $this->auth->method('authenticate')->willReturn(null);

        $endpoint = $this->createEndpoint();
        $response = $this->dispatch($endpoint, 'POST', '{"jsonrpc":"2.0","id":1,"method":"tools/list"}', 'Bearer bad-token');

        self::assertSame(401, $response->statusCode);
    }

    #[Test]
    public function toolsListReturnsToolDescriptors(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $this->tools = [
            $this->makeTool('create_node', [
                'type' => 'object',
                'properties' => ['attributes' => ['type' => 'object']],
                'required' => ['attributes'],
            ]),
        ];

        $endpoint = $this->createEndpoint();
        $response = $this->dispatch($endpoint, 'POST', \json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ], \JSON_THROW_ON_ERROR), 'Bearer valid-token');

        self::assertSame(200, $response->statusCode);

        $decoded = \json_decode($response->body, true);
        self::assertSame('2.0', $decoded['jsonrpc']);
        self::assertSame(1, $decoded['id']);
        self::assertArrayHasKey('result', $decoded);
        self::assertCount(1, $decoded['result']['tools']);
        self::assertSame('create_node', $decoded['result']['tools'][0]['name']);
        self::assertArrayHasKey('inputSchema', $decoded['result']['tools'][0]);
    }

    #[Test]
    public function toolsCallExecutesToolAndReturnsResult(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $this->tools = [$this->makeTool('read_node')];

        $endpoint = $this->createEndpoint();
        $response = $this->dispatch($endpoint, 'POST', \json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'read_node',
                'arguments' => ['id' => 42],
            ],
        ], \JSON_THROW_ON_ERROR), 'Bearer valid-token');

        self::assertSame(200, $response->statusCode);

        $decoded = \json_decode($response->body, true);
        self::assertSame(2, $decoded['id']);
        self::assertArrayHasKey('result', $decoded);
        self::assertSame('text', $decoded['result']['content'][0]['type']);
        // The fixture echoes the call arguments into the response text.
        self::assertStringContainsString('"id":42', $decoded['result']['content'][0]['text']);
    }

    #[Test]
    public function toolsCallWithUnknownToolReturnsError(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $this->tools = [];

        $endpoint = $this->createEndpoint();
        $response = $this->dispatch($endpoint, 'POST', \json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => [
                'name' => 'nonexistent_tool',
                'arguments' => [],
            ],
        ], \JSON_THROW_ON_ERROR), 'Bearer valid-token');

        self::assertSame(200, $response->statusCode);

        $decoded = \json_decode($response->body, true);
        self::assertArrayHasKey('error', $decoded);
        self::assertStringContainsString('Unknown tool', $decoded['error']['message']);
    }

    #[Test]
    public function invalidJsonReturnsParseError(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);

        $endpoint = $this->createEndpoint();
        $response = $this->dispatch($endpoint, 'POST', '{invalid json', 'Bearer valid-token');

        self::assertSame(200, $response->statusCode);

        $decoded = \json_decode($response->body, true);
        self::assertSame(-32700, $decoded['error']['code']);
    }

    #[Test]
    public function missingMethodFieldReturnsInvalidRequest(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);

        $endpoint = $this->createEndpoint();
        $response = $this->dispatch($endpoint, 'POST', \json_encode(['jsonrpc' => '2.0', 'id' => 1], \JSON_THROW_ON_ERROR), 'Bearer valid-token');

        self::assertSame(200, $response->statusCode);

        $decoded = \json_decode($response->body, true);
        self::assertSame(-32600, $decoded['error']['code']);
    }

    #[Test]
    public function responseContentTypeIsJson(): void
    {
        $this->auth->method('authenticate')->willReturn(null);

        $endpoint = $this->createEndpoint();
        $response = $this->dispatch($endpoint, 'POST', '{}', null);

        self::assertSame('application/json', $response->contentType);
    }
}
