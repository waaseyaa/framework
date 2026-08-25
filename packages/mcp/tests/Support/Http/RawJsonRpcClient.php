<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Support\Http;

use Waaseyaa\Mcp\McpProtocol;

/**
 * A generic MCP client: raw HTTP/1.1 over a socket, hand-rolled JSON-RPC, no
 * knowledge of Waaseyaa beyond the protocol itself.
 *
 * Deliberately dependency-free. An in-process `Request`/`Response` pair, or a
 * client built on the framework's own HTTP stack, would let a defect in the
 * encode path pass through unnoticed; here the only thing shared with the
 * server is the wire.
 *
 * Every wait is bounded by {@see $timeoutSeconds}; a slow or wedged server
 * raises {@see McpServerUnavailable} rather than hanging the suite.
 */
final class RawJsonRpcClient
{
    /** Sent on every request after `initialize`, as a conforming client does. */
    private ?string $negotiatedProtocolVersion = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly float $timeoutSeconds = 10.0,
    ) {}

    /**
     * Step one of the handshake. `2025-06-18` is a real published revision the
     * official SDKs offer, and is legacy-negotiable here, so the request stays
     * a plain body without the modern `_meta` envelope.
     */
    public function initialize(
        string $protocolVersion = '2025-06-18',
        string $clientName = 'waaseyaa-conformance-probe',
        string $clientVersion = '1.0.0',
        int|string $id = 1,
    ): RawHttpResponse {
        $response = $this->call('initialize', [
            'protocolVersion' => $protocolVersion,
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => $clientName, 'version' => $clientVersion],
        ], $id);

        $negotiated = $response->decodeAssoc()['result']['protocolVersion'] ?? null;
        if (\is_string($negotiated) && McpProtocol::isLegacySupported($negotiated)) {
            $this->negotiatedProtocolVersion = $negotiated;
        }

        return $response;
    }

    /** Step two: the notification, which carries no id and expects no result. */
    public function notifyInitialized(): RawHttpResponse
    {
        return $this->postJson(\json_encode([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
            'params' => new \stdClass(),
        ], \JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $params */
    public function call(string $method, array $params = [], int|string $id = 1): RawHttpResponse
    {
        $envelope = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];
        if ($params !== []) {
            $envelope['params'] = $params;
        }

        return $this->postJson(\json_encode($envelope, \JSON_THROW_ON_ERROR));
    }

    /** `ping` takes no params; the envelope is sent exactly as the spec shows it. */
    public function ping(int|string $id = 1): RawHttpResponse
    {
        return $this->call('ping', [], $id);
    }

    public function postJson(string $body): RawHttpResponse
    {
        $headers = [
            'Host' => $this->host . ':' . $this->port,
            'User-Agent' => 'waaseyaa-conformance-probe/1.0',
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
            'Content-Length' => (string) \strlen($body),
            'Connection' => 'close',
        ];
        if ($this->negotiatedProtocolVersion !== null) {
            $headers['MCP-Protocol-Version'] = $this->negotiatedProtocolVersion;
        }

        return $this->exchange('POST', '/mcp', $headers, $body);
    }

    public function get(string $path, string $accept = 'application/json'): RawHttpResponse
    {
        return $this->exchange('GET', $path, [
            'Host' => $this->host . ':' . $this->port,
            'Accept' => $accept,
            'Connection' => 'close',
        ], '');
    }

    /** @param array<string, string> $headers */
    private function exchange(string $method, string $path, array $headers, string $body): RawHttpResponse
    {
        $errno = 0;
        $error = '';
        $socket = @\stream_socket_client(
            \sprintf('tcp://%s:%d', $this->host, $this->port),
            $errno,
            $error,
            $this->timeoutSeconds,
        );
        if (!\is_resource($socket)) {
            throw new McpServerUnavailable(\sprintf(
                'could not connect to %s:%d (%d %s)',
                $this->host,
                $this->port,
                $errno,
                $error,
            ));
        }

        try {
            // Sub-second so a stalled read returns to the deadline check
            // promptly instead of parking on the socket.
            \stream_set_timeout($socket, 0, 200_000);

            $request = \sprintf("%s %s HTTP/1.1\r\n", $method, $path);
            foreach ($headers as $name => $value) {
                $request .= $name . ': ' . $value . "\r\n";
            }
            $request .= "\r\n" . $body;

            if (@\fwrite($socket, $request) === false) {
                throw new McpServerUnavailable('could not write the request to the socket');
            }

            return self::parse($this->readAll($socket));
        } finally {
            @\fclose($socket);
        }
    }

    /**
     * Read to EOF, bounded by {@see $timeoutSeconds}.
     *
     * The server answers `Connection: close` with no `Content-Length`, so EOF is
     * the frame delimiter. A response that ever arrived chunked would surface as
     * a JSON decode error carrying the raw bytes rather than being silently
     * mis-parsed.
     *
     * @param resource $socket
     */
    private function readAll($socket): string
    {
        $deadline = \microtime(true) + $this->timeoutSeconds;
        $raw = '';

        while (!\feof($socket)) {
            if (\microtime(true) >= $deadline) {
                throw new McpServerUnavailable(\sprintf(
                    'no complete response within %.1fs (%d bytes read)',
                    $this->timeoutSeconds,
                    \strlen($raw),
                ));
            }

            $chunk = @\fread($socket, 8192);
            if ($chunk === false) {
                break;
            }
            $raw .= $chunk;
        }

        if ($raw === '') {
            throw new McpServerUnavailable('the server closed the connection without a response');
        }

        return $raw;
    }

    private static function parse(string $raw): RawHttpResponse
    {
        $split = \strpos($raw, "\r\n\r\n");
        if ($split === false) {
            throw new McpServerUnavailable('the response had no header terminator');
        }

        $lines = \explode("\r\n", \substr($raw, 0, $split));
        $statusLine = \array_shift($lines) ?? '';
        if (\preg_match('#^HTTP/1\.[01] (\d{3})#', $statusLine, $matches) !== 1) {
            throw new McpServerUnavailable('unparseable status line: ' . $statusLine);
        }

        /** @var array<string, list<string>> $headers */
        $headers = [];
        foreach ($lines as $line) {
            $colon = \strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $headers[\strtolower(\trim(\substr($line, 0, $colon)))][] = \trim(\substr($line, $colon + 1));
        }

        return new RawHttpResponse((int) $matches[1], $headers, \substr($raw, $split + 4));
    }
}
