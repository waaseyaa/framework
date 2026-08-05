<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp;

use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Serves the MCP server card at `/.well-known/mcp.json`.
 *
 * Shared identity comes from {@see McpImplementationInfo}; endpoint and
 * declared auth come from {@see McpServerCardConfig}. Official Registry
 * metadata is deliberately absent. The public read-only deployment advertises
 * `authentication.type = none`.
 *
 * @api
 */
final readonly class McpServerCard
{
    private McpServerCardConfig $config;
    private McpImplementationInfo $implementation;

    public function __construct(
        ?McpServerCardConfig $config = null,
        ?McpImplementationInfo $implementation = null,
    ) {
        $this->config = $config ?? new McpServerCardConfig();
        $this->implementation = $implementation ?? new McpImplementationInfo('Waaseyaa', '0.1.0');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $card = [
            'name' => $this->implementation->name,
            'version' => $this->implementation->version,
            'description' => $this->config->description,
            'endpoint' => $this->config->endpoint,
            'transport' => 'streamable-http',
            'protocolVersions' => McpProtocol::SUPPORTED,
            'transportCapabilities' => [
                'jsonResponse' => true,
                'sse' => false,
                'sessions' => false,
                'resumability' => false,
            ],
            'capabilities' => [
                'tools' => true,
                'resources' => false,
                'prompts' => false,
            ],
            'authentication' => [
                'type' => $this->config->authType,
            ],
        ];

        if ($this->config->url !== null) {
            $card['url'] = $this->config->url;
        }

        return $card;
    }

    public function toJson(): string
    {
        return \json_encode($this->toArray(), \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
    }

    /** Standard controller entry point returning an HttpResponse. */
    public function serve(): HttpResponse
    {
        return new HttpResponse(
            $this->toJson(),
            200,
            ['Content-Type' => 'application/json'],
        );
    }
}
