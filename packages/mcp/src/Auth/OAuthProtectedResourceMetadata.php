<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Auth;

use Waaseyaa\Mcp\McpResponse;

/** Serves RFC 9728 metadata for the configured OAuth-protected MCP resource. */
final readonly class OAuthProtectedResourceMetadata
{
    public function __construct(private OAuthProtectedResourceMetadataConfig $config) {}

    public function response(): McpResponse
    {
        return new McpResponse(
            \json_encode($this->config->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
            200,
            'application/json',
            [
                'Cache-Control' => 'public, max-age=300',
            ],
        );
    }
}
