<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp;

/**
 * Framework-neutral HTTP facts required by the MCP transport guard.
 *
 * The Symfony request is projected into this immutable value at the existing
 * MCP HTTP adapter boundary. Protocol validation therefore cannot retain or
 * depend on the transport framework's mutable request object.
 */
final readonly class StreamableHttpRequestSnapshot
{
    public function __construct(
        public string $method,
        public ?string $origin,
        public ?string $contentLength,
        public ?string $contentType,
        public ?string $accept,
        public string $schemeAndHttpHost,
        public string $body,
    ) {}
}
