<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp;

/**
 * @api
 */
final readonly class McpResponse
{
    public function __construct(
        public string $body,
        public int $statusCode = 200,
        public string $contentType = 'application/json',
        /** @var array<string, string> */
        public array $headers = [],
    ) {}
}
