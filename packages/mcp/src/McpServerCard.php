<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp;

use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Serves the MCP server card at `/.well-known/mcp.json`.
 *
 * Identity, endpoint, declared auth, and registry-listing fields come from
 * {@see McpServerCardConfig}, so deployments configure them without code
 * changes. The public read-only deployment advertises `authentication.type =
 * none`.
 *
 * @api
 */
final readonly class McpServerCard
{
    private McpServerCardConfig $config;

    public function __construct(?McpServerCardConfig $config = null)
    {
        $this->config = $config ?? new McpServerCardConfig();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $card = [
            'name' => $this->config->name,
            'version' => $this->config->version,
            'description' => $this->config->description,
            'endpoint' => $this->config->endpoint,
            'transport' => 'streamable-http',
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

        // Registry-listing fields (modelcontextprotocol registry server.json shape).
        // Verify against the current registry schema before submission.
        $registry = array_filter([
            '$schema' => $this->config->schemaUrl,
            'name' => $this->config->registryName,
            'repository' => $this->config->repositoryUrl !== null
                ? ['url' => $this->config->repositoryUrl, 'source' => 'github']
                : null,
            'websiteUrl' => $this->config->websiteUrl,
        ], static fn(mixed $v): bool => $v !== null);

        if ($registry !== []) {
            $card['registry'] = $registry;
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
