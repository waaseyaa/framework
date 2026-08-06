<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Support;

use Symfony\Component\HttpFoundation\Request;

/** MCP-owned JSON-RPC request fixture; intentionally test-autoload only. */
final class McpRequestFactory
{
    /** @param array<string, mixed> $params */
    public static function body(string $method, array $params = [], int|string $id = 1): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ], JSON_THROW_ON_ERROR);
    }

    public static function request(
        string $path,
        string $body,
        ?string $authorizationHeader = null,
    ): Request {
        $headers = ['Accept' => 'application/json, text/event-stream'];
        if ($authorizationHeader !== null) {
            $headers['Authorization'] = $authorizationHeader;
        }

        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $server['CONTENT_TYPE'] = 'application/json';

        return Request::create($path, 'POST', server: $server, content: $body);
    }
}
