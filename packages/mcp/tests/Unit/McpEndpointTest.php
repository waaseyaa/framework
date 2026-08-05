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
use Waaseyaa\Mcp\Auth\OAuthProtectedResourceMetadata;
use Waaseyaa\Mcp\Auth\OAuthProtectedResourceMetadataConfig;
use Waaseyaa\Mcp\McpEndpoint;
use Waaseyaa\Mcp\McpProtocol;
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

    private function createEndpoint(?string $unauthorizedChallenge = null): McpEndpoint
    {
        return new McpEndpoint(
            auth: $this->auth,
            agentRegistry: $this->stubAgentRegistry($this->tools),
            unauthorizedChallenge: $unauthorizedChallenge,
        );
    }

    /** @param array<string, string> $protocolHeaders */
    private function dispatch(
        McpEndpoint $endpoint,
        string $method,
        string $body,
        ?string $authorizationHeader,
        array $protocolHeaders = [],
    ): McpResponse
    {
        $headers = $protocolHeaders;
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
    public function oauth_protected_endpoint_challenges_with_resource_metadata_and_scopes(): void
    {
        $this->auth->method('authenticate')->willReturn(null);
        $challenge = 'Bearer resource_metadata="https://cms.example/.well-known/oauth-protected-resource/mcp/write", scope="content.write"';

        $response = $this->dispatch(
            $this->createEndpoint($challenge),
            'POST',
            '{"jsonrpc":"2.0","id":1,"method":"tools/list"}',
            null,
        );

        self::assertSame(401, $response->statusCode);
        self::assertSame($challenge, $response->headers['WWW-Authenticate'] ?? null);
    }

    #[Test]
    public function protected_resource_metadata_is_rendered_only_when_bound(): void
    {
        self::assertSame(404, $this->createEndpoint()->serveProtectedResourceMetadata()->getStatusCode());

        $config = new OAuthProtectedResourceMetadataConfig(
            'https://cms.example/mcp/write',
            ['https://identity.example'],
            ['content.write'],
        );
        $endpoint = new McpEndpoint(
            auth: $this->auth,
            agentRegistry: $this->stubAgentRegistry([]),
            oauthProtectedResourceMetadata: new OAuthProtectedResourceMetadata($config),
        );

        $response = $endpoint->serveProtectedResourceMetadata();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('max-age=300, public', $response->headers->get('Cache-Control'));
        self::assertSame($config->toArray(), \json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR));
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
    public function legacy_tools_list_response_bytes_do_not_change(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);

        $response = $this->dispatch(
            $this->createEndpoint(),
            'POST',
            '{"jsonrpc":"2.0","id":1,"method":"tools/list"}',
            'Bearer valid-token',
        );

        self::assertSame('{"jsonrpc":"2.0","id":1,"result":{"tools":[]}}', $response->body);
        self::assertSame([], $response->headers);
    }

    #[Test]
    public function legacy_initialize_and_tool_call_response_bytes_do_not_change(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $this->tools = [$this->makeTool('read_node')];
        $endpoint = $this->createEndpoint();

        $initialize = $this->dispatch($endpoint, 'POST', \json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => [],
                'clientInfo' => ['name' => 'legacy-client', 'version' => '1.0.0'],
            ],
        ], \JSON_THROW_ON_ERROR), 'Bearer valid-token');
        self::assertSame(
            '{"jsonrpc":"2.0","id":1,"result":{"protocolVersion":"2025-11-25","capabilities":{"tools":{"listChanged":false}},"serverInfo":{"name":"Waaseyaa","version":"0.1.0"}}}',
            $initialize->body,
        );

        $call = $this->dispatch($endpoint, 'POST', \json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'read_node', 'arguments' => ['id' => 42]],
        ], \JSON_THROW_ON_ERROR), 'Bearer valid-token');
        self::assertSame(
            '{"jsonrpc":"2.0","id":2,"result":{"content":[{"type":"text","text":"{\\"operation\\":\\"echo\\",\\"id\\":42}"}]}}',
            $call->body,
        );
    }

    #[Test]
    public function modern_server_discover_reports_the_current_stateless_private_surface(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);

        $response = $this->dispatchModern($this->createEndpoint(), 'server/discover');
        $decoded = \json_decode($response->body, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->statusCode);
        self::assertSame('no-store', $response->headers['Cache-Control'] ?? null);
        self::assertSame([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => [
                'supportedVersions' => McpProtocol::SUPPORTED,
                'capabilities' => ['tools' => ['listChanged' => false]],
                'instructions' => 'List available tools before calling them. Tool visibility and execution are restricted to the authenticated principal.',
                'ttlMs' => 0,
                'cacheScope' => 'private',
                'resultType' => 'complete',
                '_meta' => [
                    'io.modelcontextprotocol/serverInfo' => [
                        'name' => 'Waaseyaa',
                        'version' => '0.1.0',
                    ],
                ],
            ],
        ], $decoded);
    }

    #[Test]
    public function modern_tools_list_has_complete_result_metadata_and_private_cache_policy(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $this->tools = [$this->makeTool('read_node')];

        $response = $this->dispatchModern($this->createEndpoint(), 'tools/list');
        $result = \json_decode($response->body, true, 512, \JSON_THROW_ON_ERROR)['result'];

        self::assertSame('read_node', $result['tools'][0]['name']);
        self::assertSame('complete', $result['resultType']);
        self::assertSame(0, $result['ttlMs']);
        self::assertSame('private', $result['cacheScope']);
        self::assertSame('Waaseyaa', $result['_meta']['io.modelcontextprotocol/serverInfo']['name']);
        self::assertSame('no-store', $response->headers['Cache-Control'] ?? null);
    }

    #[Test]
    public function modern_tools_call_requires_and_accepts_the_matching_name_header(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $this->tools = [$this->makeTool('read_node')];
        $body = \json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'read_node',
                'arguments' => ['id' => 42],
                '_meta' => [
                    McpProtocol::VERSION_META_KEY => McpProtocol::CURRENT,
                    'io.modelcontextprotocol/clientCapabilities' => [],
                ],
            ],
        ], \JSON_THROW_ON_ERROR);
        $baseHeaders = [
            'HTTP_MCP_PROTOCOL_VERSION' => McpProtocol::CURRENT,
            'HTTP_MCP_METHOD' => 'tools/call',
        ];

        $missing = $this->dispatch($this->createEndpoint(), 'POST', $body, 'Bearer valid-token', $baseHeaders);
        self::assertSame(-32020, \json_decode($missing->body, true)['error']['code']);

        $response = $this->dispatch($this->createEndpoint(), 'POST', $body, 'Bearer valid-token', $baseHeaders + [
            'HTTP_MCP_NAME' => '=?base64?' . \base64_encode('read_node') . '?=',
        ]);
        $result = \json_decode($response->body, true, 512, \JSON_THROW_ON_ERROR)['result'];
        self::assertSame('complete', $result['resultType']);
        self::assertSame('text', $result['content'][0]['type']);
        self::assertSame('no-store', $response->headers['Cache-Control'] ?? null);
    }

    #[Test]
    public function modern_core_notifications_are_refused_without_a_jsonrpc_response(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $method = 'notifications/cancelled';
        $body = \json_encode([
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => [
                'requestId' => 7,
                '_meta' => [
                    McpProtocol::VERSION_META_KEY => McpProtocol::CURRENT,
                    'io.modelcontextprotocol/clientCapabilities' => [],
                ],
            ],
        ], \JSON_THROW_ON_ERROR);

        $response = $this->dispatch($this->createEndpoint(), 'POST', $body, 'Bearer valid-token', [
            'HTTP_MCP_PROTOCOL_VERSION' => McpProtocol::CURRENT,
            'HTTP_MCP_METHOD' => $method,
        ]);

        self::assertSame(400, $response->statusCode);
        self::assertSame('', $response->body);
        self::assertSame('no-store', $response->headers['Cache-Control'] ?? null);
    }

    #[Test]
    public function streamable_http_adapter_preserves_transport_defenses_and_modern_headers(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $method = 'server/discover';
        $request = HttpRequest::create('/mcp', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => 'Bearer valid-token',
            'HTTP_MCP_PROTOCOL_VERSION' => McpProtocol::CURRENT,
            'HTTP_MCP_METHOD' => $method,
        ], content: $this->modernBody($method));

        $response = $this->createEndpoint()->serve($this->account, $request);
        $decoded = \json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertSame('complete', $decoded['result']['resultType']);
    }

    #[Test]
    public function modern_header_mismatch_and_unsupported_version_use_current_error_codes(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $endpoint = $this->createEndpoint();
        $body = $this->modernBody('tools/list');

        $mismatch = $this->dispatch($endpoint, 'POST', $body, 'Bearer valid-token', [
            'HTTP_MCP_PROTOCOL_VERSION' => McpProtocol::CURRENT,
            'HTTP_MCP_METHOD' => 'tools/call',
        ]);
        self::assertSame(400, $mismatch->statusCode);
        self::assertSame('no-store', $mismatch->headers['Cache-Control'] ?? null);
        self::assertSame([
            'jsonrpc' => '2.0',
            'error' => ['code' => -32020, 'message' => 'HeaderMismatch'],
            'id' => 1,
        ], \json_decode($mismatch->body, true, 512, \JSON_THROW_ON_ERROR));

        $unsupported = $this->dispatch($endpoint, 'POST', \str_replace(McpProtocol::CURRENT, '2099-01-01', $body), 'Bearer valid-token', [
            'HTTP_MCP_PROTOCOL_VERSION' => '2099-01-01',
            'HTTP_MCP_METHOD' => 'tools/list',
        ]);
        self::assertSame(400, $unsupported->statusCode);
        self::assertSame('no-store', $unsupported->headers['Cache-Control'] ?? null);
        self::assertSame([
            'jsonrpc' => '2.0',
            'error' => [
                'code' => -32022,
                'message' => 'Unsupported protocol version',
                'data' => [
                    'supported' => McpProtocol::SUPPORTED,
                    'requested' => '2099-01-01',
                ],
            ],
            'id' => 1,
        ], \json_decode($unsupported->body, true, 512, \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function malformed_modern_metadata_is_refused_with_no_store(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $body = \json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => [
                '_meta' => [McpProtocol::VERSION_META_KEY => McpProtocol::CURRENT],
            ],
        ], \JSON_THROW_ON_ERROR);

        $response = $this->dispatch($this->createEndpoint(), 'POST', $body, 'Bearer valid-token', [
            'HTTP_MCP_PROTOCOL_VERSION' => McpProtocol::CURRENT,
            'HTTP_MCP_METHOD' => 'tools/list',
        ]);

        self::assertSame(400, $response->statusCode);
        self::assertSame(-32602, \json_decode($response->body, true)['error']['code']);
        self::assertSame('no-store', $response->headers['Cache-Control'] ?? null);
    }

    #[Test]
    public function legacy_unknown_version_is_checked_after_authentication_and_returns_current_error(): void
    {
        $this->auth->method('authenticate')->willReturn(null, $this->account);
        $headers = ['HTTP_MCP_PROTOCOL_VERSION' => '2099-01-01'];
        $body = '{"jsonrpc":"2.0","id":1,"method":"tools/list"}';
        $endpoint = $this->createEndpoint();

        $unauthorized = $this->dispatch($endpoint, 'POST', $body, null, $headers);
        self::assertSame(401, $unauthorized->statusCode);

        $unsupported = $this->dispatch($endpoint, 'POST', $body, 'Bearer valid-token', $headers);
        self::assertSame(400, $unsupported->statusCode);
        self::assertSame(-32022, \json_decode($unsupported->body, true)['error']['code']);
        self::assertSame('2099-01-01', \json_decode($unsupported->body, true)['error']['data']['requested']);
        self::assertSame('no-store', $unsupported->headers['Cache-Control'] ?? null);
    }

    #[Test]
    public function legacy_only_methods_are_not_found_under_the_modern_protocol(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);

        foreach (['initialize', 'ping'] as $method) {
            $response = $this->dispatchModern($this->createEndpoint(), $method);
            self::assertSame(404, $response->statusCode);
            self::assertSame(-32601, \json_decode($response->body, true)['error']['code']);
        }
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

        self::assertSame(400, $response->statusCode);

        $decoded = \json_decode($response->body, true);
        self::assertSame(-32700, $decoded['error']['code']);
    }

    #[Test]
    public function missingMethodFieldReturnsInvalidRequest(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);

        $endpoint = $this->createEndpoint();
        $response = $this->dispatch($endpoint, 'POST', \json_encode(['jsonrpc' => '2.0', 'id' => 1], \JSON_THROW_ON_ERROR), 'Bearer valid-token');

        self::assertSame(400, $response->statusCode);

        $decoded = \json_decode($response->body, true);
        self::assertSame(-32600, $decoded['error']['code']);
    }

    #[Test]
    public function initialize_negotiates_supported_versions_and_falls_forward_for_an_unknown_version(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $endpoint = $this->createEndpoint();

        foreach (['2025-11-25' => '2025-11-25', '2025-06-18' => '2025-06-18', '1999-01-01' => '2025-11-25'] as $requested => $expected) {
            $response = $this->dispatch($endpoint, 'POST', \json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => $requested,
                    'capabilities' => [],
                    'clientInfo' => ['name' => 'test-client', 'version' => '1.0.0'],
                ],
            ], \JSON_THROW_ON_ERROR), 'Bearer valid-token');

            self::assertSame($expected, \json_decode($response->body, true)['result']['protocolVersion']);
        }
    }

    private function dispatchModern(McpEndpoint $endpoint, string $method): McpResponse
    {
        return $this->dispatch($endpoint, 'POST', $this->modernBody($method), 'Bearer valid-token', [
            'HTTP_MCP_PROTOCOL_VERSION' => McpProtocol::CURRENT,
            'HTTP_MCP_METHOD' => $method,
        ]);
    }

    private function modernBody(string $method): string
    {
        return \json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => [
                '_meta' => [
                    McpProtocol::VERSION_META_KEY => McpProtocol::CURRENT,
                    'io.modelcontextprotocol/clientCapabilities' => [],
                ],
            ],
        ], \JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function initialize_requires_the_lifecycle_identity_and_capability_fields(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $response = $this->dispatch($this->createEndpoint(), 'POST', \json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [],
        ], \JSON_THROW_ON_ERROR), 'Bearer valid-token');

        self::assertSame(-32602, \json_decode($response->body, true)['error']['code']);
    }

    #[Test]
    public function initialized_and_cancelled_notifications_return_202_with_no_body(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $endpoint = $this->createEndpoint();

        foreach ([
            ['method' => 'notifications/initialized', 'params' => []],
            ['method' => 'notifications/cancelled', 'params' => ['requestId' => 7, 'reason' => 'timeout']],
        ] as $notification) {
            $response = $this->dispatch($endpoint, 'POST', \json_encode([
                'jsonrpc' => '2.0',
                ...$notification,
            ], \JSON_THROW_ON_ERROR), 'Bearer valid-token');

            self::assertSame(202, $response->statusCode);
            self::assertSame('', $response->body);
        }
    }

    #[Test]
    public function malformed_cancellation_notification_is_rejected_without_a_jsonrpc_response(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $response = $this->dispatch($this->createEndpoint(), 'POST', \json_encode([
            'jsonrpc' => '2.0',
            'method' => 'notifications/cancelled',
            'params' => [],
        ], \JSON_THROW_ON_ERROR), 'Bearer valid-token');

        self::assertSame(400, $response->statusCode);
        self::assertSame('', $response->body);
    }

    #[Test]
    public function response_messages_are_accepted_with_202_and_ignored(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $response = $this->dispatch($this->createEndpoint(), 'POST', \json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => [],
        ], \JSON_THROW_ON_ERROR), 'Bearer valid-token');

        self::assertSame(202, $response->statusCode);
        self::assertSame('', $response->body);
    }

    #[Test]
    public function malformed_response_messages_are_http_400(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $endpoint = $this->createEndpoint();
        foreach ([
            ['jsonrpc' => '2.0', 'id' => null, 'result' => []],
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => [], 'error' => ['code' => -1, 'message' => 'both']],
            ['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => '-1', 'message' => 'wrong code type']],
        ] as $payload) {
            $response = $this->dispatch(
                $endpoint,
                'POST',
                \json_encode($payload, \JSON_THROW_ON_ERROR),
                'Bearer valid-token',
            );
            self::assertSame(400, $response->statusCode);
            self::assertSame(-32600, \json_decode($response->body, true)['error']['code']);
        }
    }

    #[Test]
    public function batch_payload_wrong_jsonrpc_and_invalid_ids_are_http_400(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $endpoint = $this->createEndpoint();
        $payloads = [
            [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']],
            ['jsonrpc' => '1.0', 'id' => 1, 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'id' => null, 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'id' => 1.5, 'method' => 'ping'],
        ];

        foreach ($payloads as $payload) {
            $response = $this->dispatch(
                $endpoint,
                'POST',
                \json_encode($payload, \JSON_THROW_ON_ERROR),
                'Bearer valid-token',
            );
            self::assertSame(400, $response->statusCode);
            self::assertSame(-32600, \json_decode($response->body, true)['error']['code']);
        }
    }

    #[Test]
    public function request_methods_without_ids_are_not_misexecuted_as_notifications(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $response = $this->dispatch($this->createEndpoint(), 'POST', \json_encode([
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => ['name' => 'anything', 'arguments' => []],
        ], \JSON_THROW_ON_ERROR), 'Bearer valid-token');

        self::assertSame(400, $response->statusCode);
        self::assertSame('', $response->body);
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
