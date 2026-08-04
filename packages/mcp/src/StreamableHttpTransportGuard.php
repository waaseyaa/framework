<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp;

/**
 * Enforces the wire contract for the stateless JSON-response profile of MCP
 * Streamable HTTP. SSE, sessions and resumability are deliberately not
 * advertised; GET therefore answers 405 exactly as the specification permits.
 *
 * @api
 */
final readonly class StreamableHttpTransportGuard
{
    public const int DEFAULT_MAX_REQUEST_BYTES = 10_485_760;

    /** @var list<string> */
    private array $allowedOrigins;

    /** @param list<string> $allowedOrigins Additional browser origins beyond same-origin. */
    public function __construct(
        array $allowedOrigins = [],
        private int $maxRequestBytes = self::DEFAULT_MAX_REQUEST_BYTES,
    ) {
        if ($this->maxRequestBytes < 1) {
            throw new \InvalidArgumentException('MCP maximum request size must be positive.');
        }
        $normalized = [];
        foreach ($allowedOrigins as $origin) {
            $normalized[] = self::normalizeOrigin($origin);
        }
        $this->allowedOrigins = \array_values(\array_unique($normalized));
    }

    public function validate(StreamableHttpRequestSnapshot $request): ?McpResponse
    {
        $origin = $request->origin;
        if ($origin !== null && !$this->originAllowed($origin, $request)) {
            return self::error(403, -32040, 'Forbidden origin');
        }

        $version = $request->protocolVersion;
        if ($version !== null && !McpProtocol::isSupported($version)) {
            return self::error(400, -32602, 'Unsupported MCP-Protocol-Version', [
                'supported' => McpProtocol::SUPPORTED,
            ]);
        }

        if ($request->method === 'GET') {
            if (!self::accepts($request, 'text/event-stream')) {
                return self::error(406, -32041, 'GET requires Accept: text/event-stream');
            }

            return new McpResponse('', 405, 'application/json', ['Allow' => 'POST']);
        }

        if ($request->method !== 'POST') {
            return new McpResponse('', 405, 'application/json', ['Allow' => 'POST']);
        }

        $declaredLength = $request->contentLength;
        if ($declaredLength !== null && \preg_match('/^\d+$/D', $declaredLength) !== 1) {
            return self::error(400, -32600, 'Invalid Content-Length');
        }
        if (($declaredLength !== null && (int) $declaredLength > $this->maxRequestBytes)
            || \strlen($request->body) > $this->maxRequestBytes
        ) {
            return self::error(413, -32043, 'Request body exceeds maximum size', [
                'max_request_bytes' => $this->maxRequestBytes,
            ]);
        }

        $contentType = \strtolower(\trim(\explode(';', (string) $request->contentType, 2)[0]));
        if ($contentType !== 'application/json') {
            return self::error(415, -32042, 'Content-Type must be application/json');
        }

        if (!self::accepts($request, 'application/json') || !self::accepts($request, 'text/event-stream')) {
            return self::error(
                406,
                -32041,
                'Accept must list application/json and text/event-stream',
            );
        }

        return null;
    }

    private function originAllowed(string $origin, StreamableHttpRequestSnapshot $request): bool
    {
        try {
            $normalized = self::normalizeOrigin($origin);
            $sameOrigin = self::normalizeOrigin($request->schemeAndHttpHost);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return $normalized === $sameOrigin || \in_array($normalized, $this->allowedOrigins, true);
    }

    private static function normalizeOrigin(string $origin): string
    {
        if ($origin === '' || \str_contains($origin, ',') || \preg_match('/[\x00-\x20\x7F]/', $origin) === 1) {
            throw new \InvalidArgumentException('MCP origin must be one absolute HTTP(S) origin.');
        }

        $parts = \parse_url($origin);
        if (!\is_array($parts)
            || !\in_array(\strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '/')
        ) {
            throw new \InvalidArgumentException('MCP origin must be one absolute HTTP(S) origin without a path.');
        }

        $scheme = \strtolower($parts['scheme']);
        $host = \strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        $portPart = \is_int($port) && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))
            ? ':' . $port
            : '';

        return $scheme . '://' . $host . $portPart;
    }

    private static function accepts(StreamableHttpRequestSnapshot $request, string $mediaType): bool
    {
        foreach (\explode(',', (string) $request->accept) as $item) {
            $segments = \array_map('trim', \explode(';', \strtolower($item)));
            if ($segments[0] !== $mediaType) {
                continue;
            }
            foreach (\array_slice($segments, 1) as $parameter) {
                if (\preg_match('/^q=0(?:\.0*)?$/', $parameter) === 1) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    /** @param array<string, mixed> $data */
    private static function error(int $status, int $code, string $message, array $data = []): McpResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== []) {
            $error['data'] = $data;
        }

        return new McpResponse(
            \json_encode(['jsonrpc' => '2.0', 'error' => $error, 'id' => null], \JSON_THROW_ON_ERROR),
            $status,
        );
    }
}
