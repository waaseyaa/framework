<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\AgentRuntime\Fixture;

use Waaseyaa\HttpClient\HttpClientInterface;
use Waaseyaa\HttpClient\HttpResponse;

/**
 * In-process JSON-RPC 2.0 MCP server implementing the three methods the
 * tool source exercises (`initialize`, `tools/list`, `tools/call`). It
 * speaks the Streamable-HTTP transport (a single JSON document per call).
 *
 * The fixture is intentionally test-only — it does not implement the
 * full MCP protocol surface, only enough to drive
 * {@see \Waaseyaa\AI\Agent\Mcp\McpClientToolSource}.
 */
final class StubMcpServerHttpClient implements HttpClientInterface
{
    /**
     * @var array<string, array{
     *     description: string,
     *     inputSchema: array<string, mixed>,
     *     handler: \Closure(array<string, mixed>): array{isError: bool, content: list<array<string, mixed>>},
     * }>
     */
    private array $tools = [];

    public function __construct(private readonly bool $serverAvailable = true) {}

    /**
     * @param array<string, mixed> $inputSchema
     * @param \Closure(array<string, mixed>): array{isError: bool, content: list<array<string, mixed>>} $handler
     */
    public function registerTool(string $name, string $description, array $inputSchema, \Closure $handler): void
    {
        $this->tools[$name] = [
            'description' => $description,
            'inputSchema' => $inputSchema,
            'handler' => $handler,
        ];
    }

    public function request(string $method, string $url, array $headers = [], array|string|null $body = null): HttpResponse
    {
        if (!$this->serverAvailable) {
            return new HttpResponse(503, 'Service Unavailable');
        }

        if ($method !== 'POST') {
            return new HttpResponse(405, '{"error":"method not allowed"}');
        }

        $envelope = $this->decodeBody($body);
        if ($envelope === null) {
            return new HttpResponse(400, '{"error":"bad request"}');
        }

        $rpcMethod = is_string($envelope['method'] ?? null) ? $envelope['method'] : '';
        $params = is_array($envelope['params'] ?? null) ? $envelope['params'] : [];
        $id = $envelope['id'] ?? 'noid';

        if ($rpcMethod === 'notifications/initialized' && !array_key_exists('id', $envelope)) {
            return new HttpResponse(202, '');
        }

        $result = match ($rpcMethod) {
            'initialize' => [
                'protocolVersion' => '2025-11-25',
                'serverInfo' => ['name' => 'stub-mcp', 'version' => '0.1'],
                'capabilities' => ['tools' => new \stdClass()],
            ],
            'tools/list' => [
                'tools' => array_values(array_map(
                    static fn(string $name, array $tool): array => [
                        'name' => $name,
                        'description' => $tool['description'],
                        'inputSchema' => $tool['inputSchema'],
                    ],
                    array_keys($this->tools),
                    $this->tools,
                )),
            ],
            'tools/call' => $this->handleCall($params),
            default => null,
        };

        if ($result === null) {
            return new HttpResponse(200, json_encode([
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => ['code' => -32601, 'message' => 'method not found: ' . $rpcMethod],
            ], JSON_THROW_ON_ERROR));
        }

        return new HttpResponse(200, json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ], JSON_THROW_ON_ERROR));
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        return $this->request('GET', $url, $headers, null);
    }

    public function post(string $url, array $headers = [], array|string|null $body = null): HttpResponse
    {
        return $this->request('POST', $url, $headers, $body);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeBody(array|string|null $body): ?array
    {
        if (is_array($body)) {
            return $body;
        }
        if (!is_string($body) || $body === '') {
            return null;
        }
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{isError: bool, content: list<array<string, mixed>>}
     */
    private function handleCall(array $params): array
    {
        $name = is_string($params['name'] ?? null) ? $params['name'] : '';
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if (!isset($this->tools[$name])) {
            return [
                'isError' => true,
                'content' => [['type' => 'text', 'text' => 'unknown tool ' . $name]],
            ];
        }

        return ($this->tools[$name]['handler'])($args);
    }
}
