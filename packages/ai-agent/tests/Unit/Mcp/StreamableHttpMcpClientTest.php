<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Agent\Mcp\McpRemoteToolResult;
use Waaseyaa\AI\Agent\Mcp\McpServerInfo;
use Waaseyaa\AI\Agent\Mcp\McpServerUnavailableException;
use Waaseyaa\AI\Agent\Mcp\StreamableHttpMcpClient;
use Waaseyaa\AI\Agent\Tests\Unit\Mcp\Fixture\StubHttpClient;
use Waaseyaa\HttpClient\HttpRequestException;
use Waaseyaa\HttpClient\HttpResponse;

#[CoversClass(StreamableHttpMcpClient::class)]
final class StreamableHttpMcpClientTest extends TestCase
{
    #[Test]
    public function initializeReturnsServerInfo(): void
    {
        $http = new StubHttpClient();
        $http->enqueueJson([
            'jsonrpc' => '2.0',
            'id' => 'x',
            'result' => [
                'protocolVersion' => '2025-11-25',
                'serverInfo' => ['name' => 'stub', 'version' => '0.1'],
                'capabilities' => ['tools' => new \stdClass()],
            ],
        ]);
        $http->enqueueResponse(new HttpResponse(202, ''));

        $client = new StreamableHttpMcpClient($http);
        $info = $client->initialize('https://example.invalid/mcp', null);

        self::assertInstanceOf(McpServerInfo::class, $info);
        self::assertSame('stub', $info->name);
        self::assertSame('0.1', $info->version);
        self::assertSame('2025-11-25', $info->protocolVersion);
        self::assertArrayHasKey('tools', $info->capabilities);
        self::assertCount(2, $http->requests);
        self::assertSame('2025-11-25', $http->requests[0]['headers']['MCP-Protocol-Version']);
        $notification = json_decode((string) $http->requests[1]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('notifications/initialized', $notification['method'] ?? null);
    }

    #[Test]
    public function listToolsParsesDescriptors(): void
    {
        $http = new StubHttpClient();
        $http->enqueueJson([
            'jsonrpc' => '2.0',
            'id' => 'x',
            'result' => [
                'tools' => [
                    [
                        'name' => 'echo',
                        'description' => 'Echo back input',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => ['msg' => ['type' => 'string']],
                        ],
                        'destructive' => false,
                    ],
                    [
                        'name' => 'noop_extra',
                        // missing description and schema — defaults apply
                    ],
                    'not-a-tool',
                ],
            ],
        ]);

        $client = new StreamableHttpMcpClient($http);
        $descriptors = $client->listTools('https://example.invalid/mcp', null);

        self::assertCount(2, $descriptors);
        self::assertSame('echo', $descriptors[0]->name);
        self::assertSame('Echo back input', $descriptors[0]->description);
        self::assertSame(false, $descriptors[0]->metadata['destructive']);
        self::assertSame('noop_extra', $descriptors[1]->name);
    }

    #[Test]
    public function callToolReturnsResultEnvelope(): void
    {
        $http = new StubHttpClient();
        $http->enqueueJson([
            'jsonrpc' => '2.0',
            'id' => 'x',
            'result' => [
                'isError' => false,
                'content' => [
                    ['type' => 'text', 'text' => 'pong'],
                ],
            ],
        ]);

        $client = new StreamableHttpMcpClient($http);
        $result = $client->callTool('https://example.invalid/mcp', 'Bearer s3cret', 'ping', []);

        self::assertInstanceOf(McpRemoteToolResult::class, $result);
        self::assertFalse($result->isError);
        self::assertSame('pong', $result->content[0]['text']);
    }

    #[Test]
    public function authHeaderIsSentWhenProvided(): void
    {
        $http = new StubHttpClient();
        $http->enqueueJson(['jsonrpc' => '2.0', 'id' => 'x', 'result' => ['tools' => []]]);

        $client = new StreamableHttpMcpClient($http);
        $client->listTools('https://example.invalid/mcp', 'Bearer abc123');

        self::assertCount(1, $http->requests);
        self::assertSame('Bearer abc123', $http->requests[0]['headers']['Authorization']);
        self::assertSame('2025-11-25', $http->requests[0]['headers']['MCP-Protocol-Version']);
    }

    #[Test]
    public function negotiated_session_and_version_are_sent_on_initialized_notification_and_subsequent_calls(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(new HttpResponse(200, json_encode([
            'jsonrpc' => '2.0',
            'id' => 'x',
            'result' => [
                'protocolVersion' => '2025-06-18',
                'serverInfo' => ['name' => 'stub', 'version' => '1'],
                'capabilities' => ['tools' => []],
            ],
        ], JSON_THROW_ON_ERROR), ['Mcp-Session-Id' => 'session-abc']));
        $http->enqueueResponse(new HttpResponse(202, ''));
        $http->enqueueJson(['jsonrpc' => '2.0', 'id' => 'y', 'result' => ['tools' => []]]);

        $client = new StreamableHttpMcpClient($http);
        $client->initialize('https://example.invalid/mcp', 'Bearer token');
        $client->listTools('https://example.invalid/mcp', 'Bearer token');

        self::assertCount(3, $http->requests);
        foreach ([1, 2] as $index) {
            self::assertSame('2025-06-18', $http->requests[$index]['headers']['MCP-Protocol-Version']);
            self::assertSame('session-abc', $http->requests[$index]['headers']['MCP-Session-Id']);
        }
    }

    #[Test]
    public function unsupported_negotiated_protocol_is_refused_before_initialized_notification(): void
    {
        $http = new StubHttpClient();
        $http->enqueueJson([
            'jsonrpc' => '2.0',
            'id' => 'x',
            'result' => [
                'protocolVersion' => '2024-11-05',
                'serverInfo' => ['name' => 'legacy', 'version' => '1'],
            ],
        ]);

        $client = new StreamableHttpMcpClient($http);

        $this->expectException(McpServerUnavailableException::class);
        $this->expectExceptionMessageMatches('/unsupported protocol version/');
        $client->initialize('https://example.invalid/mcp', null);
    }

    #[Test]
    public function fiveHundredResponseThrowsServerUnavailable(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(new HttpResponse(503, 'Service Unavailable'));

        $client = new StreamableHttpMcpClient($http);

        $this->expectException(McpServerUnavailableException::class);
        $client->initialize('https://example.invalid/mcp', null);
    }

    #[Test]
    public function transportFailureThrowsServerUnavailable(): void
    {
        $http = new StubHttpClient();
        $http->enqueueException(new HttpRequestException(
            'connection refused',
            'https://example.invalid/mcp',
            'POST',
        ));

        $client = new StreamableHttpMcpClient($http);

        $this->expectException(McpServerUnavailableException::class);
        $client->listTools('https://example.invalid/mcp', null);
    }

    #[Test]
    public function nonHttpUrlIsRejectedPerC008(): void
    {
        $client = new StreamableHttpMcpClient(new StubHttpClient());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/C-008/');
        $client->initialize('stdio:///path/to/binary', null);
    }

    #[Test]
    public function sseFramedResponseIsDecoded(): void
    {
        $http = new StubHttpClient();
        $http->enqueueResponse(new HttpResponse(
            200,
            "event: message\ndata: " . json_encode([
                'jsonrpc' => '2.0',
                'id' => 'x',
                'result' => ['tools' => [['name' => 'sse_tool', 'description' => 'd', 'inputSchema' => ['type' => 'object']]]],
            ], JSON_THROW_ON_ERROR) . "\n\n",
        ));

        $client = new StreamableHttpMcpClient($http);
        $descriptors = $client->listTools('https://example.invalid/mcp', null);

        self::assertCount(1, $descriptors);
        self::assertSame('sse_tool', $descriptors[0]->name);
    }

    #[Test]
    public function jsonRpcErrorObjectThrowsRuntimeException(): void
    {
        $http = new StubHttpClient();
        $http->enqueueJson([
            'jsonrpc' => '2.0',
            'id' => 'x',
            'error' => ['code' => -32601, 'message' => 'Method not found'],
        ]);

        $client = new StreamableHttpMcpClient($http);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Method not found/');
        $client->callTool('https://example.invalid/mcp', null, 'missing', []);
    }
}
