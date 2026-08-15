<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Mcp;

use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\HttpClient\HttpClientInterface;
use Waaseyaa\HttpClient\HttpRequestException;
use Waaseyaa\HttpClient\HttpResponse;

/**
 * Minimal MCP client that speaks Streamable-HTTP only (per C-008).
 *
 * Supports the three MCP operations needed by {@see McpClientToolSource}:
 *
 * - `initialize`   — handshake; records server identity + capabilities.
 * - `tools/list`   — enumerates tool descriptors for catalogue ingestion.
 * - `tools/call`   — executes a remote tool with the given arguments.
 *
 * No stdio transport, no SSE-only servers — Streamable HTTP only. Per
 * C-010, callers pass an `$authHeader` value resolved from a configured
 * env-var name at call time, not at config-load time. JSON-RPC 2.0 envelope.
 *
 * @api
 */
final class StreamableHttpMcpClient
{
    private const PROTOCOL_VERSION = '2025-11-25';
    /** @var non-empty-list<string> */
    private const SUPPORTED_PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];
    private const CLIENT_NAME = 'waaseyaa-ai-agent';
    private const CLIENT_VERSION = '0.1';

    private readonly LoggerInterface $logger;
    /** @var array<string, string> */
    private array $protocolVersions = [];
    /** @var array<string, string> */
    private array $sessionIds = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Perform the MCP `initialize` handshake.
     *
     * @throws McpServerUnavailableException On transport failure or 5xx response.
     */
    public function initialize(string $url, ?string $authHeader): McpServerInfo
    {
        $this->assertHttpUrl($url);

        $response = $this->rpc($url, $authHeader, 'initialize', [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => new \stdClass(),
            'clientInfo' => [
                'name' => self::CLIENT_NAME,
                'version' => self::CLIENT_VERSION,
            ],
        ]);

        $serverInfo = $response['serverInfo'] ?? [];
        $name = is_array($serverInfo) && isset($serverInfo['name']) && is_string($serverInfo['name'])
            ? $serverInfo['name']
            : 'unknown';
        $version = is_array($serverInfo) && isset($serverInfo['version']) && is_string($serverInfo['version'])
            ? $serverInfo['version']
            : '0';
        $protocolVersion = isset($response['protocolVersion']) && is_string($response['protocolVersion'])
            ? $response['protocolVersion']
            : self::PROTOCOL_VERSION;
        if (!in_array($protocolVersion, self::SUPPORTED_PROTOCOL_VERSIONS, true)) {
            throw new McpServerUnavailableException(
                url: $url,
                message: sprintf('MCP server %s negotiated unsupported protocol version %s', $url, $protocolVersion),
            );
        }
        $this->protocolVersions[$url] = $protocolVersion;
        $this->notifyInitialized($url, $authHeader);
        $capabilities = isset($response['capabilities']) && is_array($response['capabilities'])
            ? $response['capabilities']
            : [];

        return new McpServerInfo(
            name: $name,
            version: $version,
            protocolVersion: $protocolVersion,
            capabilities: $capabilities,
        );
    }

    /**
     * Enumerate tool descriptors advertised by the server.
     *
     * @return list<McpRemoteToolDescriptor>
     *
     * @throws McpServerUnavailableException On transport failure or 5xx response.
     */
    public function listTools(string $url, ?string $authHeader): array
    {
        $this->assertHttpUrl($url);

        $response = $this->rpc($url, $authHeader, 'tools/list', []);

        $tools = $response['tools'] ?? [];
        if (!is_array($tools)) {
            return [];
        }

        $descriptors = [];
        foreach ($tools as $tool) {
            if (!is_array($tool)) {
                continue;
            }
            $name = isset($tool['name']) && is_string($tool['name']) ? $tool['name'] : null;
            if ($name === null || $name === '') {
                continue;
            }
            $description = isset($tool['description']) && is_string($tool['description'])
                ? $tool['description']
                : '';
            $inputSchema = isset($tool['inputSchema']) && is_array($tool['inputSchema'])
                ? $tool['inputSchema']
                : ['type' => 'object', 'properties' => new \stdClass()];

            // Non-standard hint fields (destructive, etc.) live under metadata.
            $metadata = [];
            foreach ($tool as $key => $value) {
                if (in_array($key, ['name', 'description', 'inputSchema'], true)) {
                    continue;
                }
                if (is_string($key)) {
                    $metadata[$key] = $value;
                }
            }

            $descriptors[] = new McpRemoteToolDescriptor(
                name: $name,
                description: $description,
                inputSchema: $inputSchema,
                metadata: $metadata,
            );
        }

        return $descriptors;
    }

    /**
     * Invoke a tool on the remote MCP server.
     *
     * @param array<string, mixed> $arguments Tool arguments as a JSON object.
     *
     * @throws McpServerUnavailableException On transport failure or 5xx response.
     */
    public function callTool(
        string $url,
        ?string $authHeader,
        string $toolName,
        array $arguments,
    ): McpRemoteToolResult {
        $this->assertHttpUrl($url);

        $response = $this->rpc($url, $authHeader, 'tools/call', [
            'name' => $toolName,
            'arguments' => $arguments === [] ? new \stdClass() : $arguments,
        ]);

        $isError = isset($response['isError']) && $response['isError'] === true;
        $content = $response['content'] ?? [];
        if (!is_array($content)) {
            $content = [];
        }
        $blocks = [];
        foreach ($content as $block) {
            if (is_array($block)) {
                $blocks[] = $block;
            }
        }

        return new McpRemoteToolResult(
            isError: $isError,
            content: $blocks,
        );
    }

    /**
     * Reject any non-HTTP(S) URL up front — C-008 forbids stdio transport,
     * and any non-HTTP scheme would also fail at the HTTP client layer.
     *
     * @throws \InvalidArgumentException On a non-HTTP(S) URL.
     */
    private function assertHttpUrl(string $url): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new \InvalidArgumentException(sprintf(
                'StreamableHttpMcpClient supports http(s) URLs only; got "%s" (C-008: no stdio transport).',
                $url,
            ));
        }
    }

    /**
     * Send a JSON-RPC 2.0 request and return the `result` payload.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     *
     * @throws McpServerUnavailableException On transport failure or 5xx response.
     */
    private function rpc(string $url, ?string $authHeader, string $method, array $params): array
    {
        $envelope = [
            'jsonrpc' => '2.0',
            'id' => bin2hex(random_bytes(8)),
            'method' => $method,
            'params' => $params === [] ? new \stdClass() : $params,
        ];

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => $this->protocolVersions[$url] ?? self::PROTOCOL_VERSION,
        ];
        if (isset($this->sessionIds[$url])) {
            $headers['MCP-Session-Id'] = $this->sessionIds[$url];
        }
        if ($authHeader !== null && $authHeader !== '') {
            $headers['Authorization'] = $authHeader;
        }

        try {
            $payload = json_encode($envelope, JSON_THROW_ON_ERROR);
            $response = $this->httpClient->post($url, $headers, $payload);
        } catch (HttpRequestException $e) {
            $this->logger->warning('MCP transport failure', [
                'url' => $url,
                'method' => $method,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            throw new McpServerUnavailableException(
                url: $url,
                message: sprintf('MCP transport failure for %s (%s): %s', $url, $method, $e->getMessage()),
                previous: $e,
            );
        } catch (\JsonException $e) {
            // Encoding our own envelope failed — treat as fatal; caller's bug.
            throw new \RuntimeException('Failed to encode MCP JSON-RPC envelope: ' . $e->getMessage(), 0, $e);
        }

        if ($method === 'initialize') {
            $sessionId = self::header($response, 'MCP-Session-Id');
            if ($sessionId !== null && $sessionId !== '') {
                $this->sessionIds[$url] = $sessionId;
            }
        }

        if ($response->statusCode === 404 && isset($this->sessionIds[$url])) {
            unset($this->sessionIds[$url], $this->protocolVersions[$url]);
            throw new McpServerUnavailableException(
                url: $url,
                message: sprintf('MCP session expired for %s; initialize a new session', $url),
            );
        }

        if ($response->statusCode >= 500) {
            $this->logger->warning('MCP server returned 5xx', [
                'url' => $url,
                'method' => $method,
                'status' => $response->statusCode,
            ]);
            throw new McpServerUnavailableException(
                url: $url,
                message: sprintf('MCP server %s returned HTTP %d for %s', $url, $response->statusCode, $method),
            );
        }

        try {
            $decoded = $this->decodeBody($response);
        } catch (\JsonException $e) {
            throw new McpServerUnavailableException(
                url: $url,
                message: sprintf('MCP server %s returned malformed JSON for %s: %s', $url, $method, $e->getMessage()),
                previous: $e,
            );
        }

        if (isset($decoded['error']) && is_array($decoded['error'])) {
            throw new McpRemoteErrorException();
        }

        $result = $decoded['result'] ?? null;
        if (!is_array($result)) {
            return [];
        }

        return $result;
    }

    private function notifyInitialized(string $url, ?string $authHeader): void
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => $this->protocolVersions[$url],
        ];
        if ($authHeader !== null && $authHeader !== '') {
            $headers['Authorization'] = $authHeader;
        }
        if (isset($this->sessionIds[$url])) {
            $headers['MCP-Session-Id'] = $this->sessionIds[$url];
        }

        try {
            $response = $this->httpClient->post($url, $headers, json_encode([
                'jsonrpc' => '2.0',
                'method' => 'notifications/initialized',
            ], JSON_THROW_ON_ERROR));
        } catch (HttpRequestException $e) {
            throw new McpServerUnavailableException(
                url: $url,
                message: sprintf('MCP initialized notification failed for %s: %s', $url, $e->getMessage()),
                previous: $e,
            );
        }

        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new McpServerUnavailableException(
                url: $url,
                message: sprintf('MCP server %s rejected notifications/initialized with HTTP %d', $url, $response->statusCode),
            );
        }
    }

    private static function header(HttpResponse $response, string $name): ?string
    {
        foreach ($response->headers as $header => $value) {
            if (strcasecmp($header, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Decode the response body. Supports both `application/json` and
     * `text/event-stream` (Streamable HTTP responses may stream a single
     * `message` event whose `data:` line carries the JSON-RPC reply).
     *
     * @return array<string, mixed>
     *
     * @throws \JsonException
     */
    private function decodeBody(HttpResponse $response): array
    {
        $body = trim($response->body);
        if ($body === '') {
            return [];
        }

        // Streamable HTTP servers may reply with an SSE-framed single event.
        if (str_starts_with($body, 'event:') || str_starts_with($body, 'data:')) {
            $jsonLine = '';
            $lines = preg_split('/\r?\n/', $body);
            if ($lines === false) {
                $lines = [];
            }
            foreach ($lines as $line) {
                if (str_starts_with($line, 'data:')) {
                    $jsonLine = trim(substr($line, 5));
                    break;
                }
            }
            if ($jsonLine === '') {
                return [];
            }
            $decoded = json_decode($jsonLine, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        }

        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
