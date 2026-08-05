<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp;

/** Immutable projection of protocol-significant Streamable HTTP headers. */
final readonly class McpRequestHeaders
{
    public function __construct(
        public ?string $protocolVersion = null,
        public ?string $method = null,
        public ?string $name = null,
        public bool $malformed = false,
    ) {}
}
