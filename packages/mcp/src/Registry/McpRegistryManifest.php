<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Registry;

use Waaseyaa\Foundation\Exception\ConfigException;
use Waaseyaa\Mcp\McpImplementationInfo;

/**
 * Official Registry `server.json` projection for one public deployment.
 *
 * @api
 */
final readonly class McpRegistryManifest
{
    public const string SCHEMA = 'https://static.modelcontextprotocol.io/schemas/2025-12-11/server.schema.json';

    public function __construct(
        private McpRegistryManifestConfig $config,
        private McpImplementationInfo $implementation,
    ) {
        if ($this->implementation->version === '0.0.0+unknown') {
            throw new ConfigException(
                'mcp.implementation.version must be configured before producing an official Registry manifest.',
            );
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return \array_filter([
            '$schema' => self::SCHEMA,
            'name' => $this->config->name,
            'title' => $this->implementation->name,
            'description' => $this->config->description,
            'version' => $this->implementation->version,
            'repository' => $this->config->repositoryUrl !== null
                ? [
                    'url' => $this->config->repositoryUrl,
                    'source' => $this->config->repositorySource,
                ]
                : null,
            'websiteUrl' => $this->config->websiteUrl,
            'remotes' => [[
                'type' => 'streamable-http',
                'url' => $this->config->remoteUrl,
            ]],
        ], static fn(mixed $value): bool => $value !== null);
    }

    public function toJson(): string
    {
        return \json_encode($this->toArray(), \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n";
    }
}
