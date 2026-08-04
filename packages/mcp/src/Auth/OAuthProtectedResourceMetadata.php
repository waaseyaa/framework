<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Auth;

use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Serves RFC 9728 metadata for the configured OAuth-protected MCP resource. */
final readonly class OAuthProtectedResourceMetadata
{
    public function __construct(private OAuthProtectedResourceMetadataConfig $config) {}

    public function serve(): HttpResponse
    {
        return new HttpResponse(
            \json_encode($this->config->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
            200,
            [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'public, max-age=300',
            ],
        );
    }
}
