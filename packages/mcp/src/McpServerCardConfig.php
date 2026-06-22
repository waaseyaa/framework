<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp;

/**
 * Configurable identity + advertisement for the MCP server card served at
 * `/.well-known/mcp.json`, and the fields needed to prepare an MCP-registry
 * listing.
 *
 * Registry-field note: the field set below targets the modelcontextprotocol
 * registry `server.json` shape (namespaced `name`, `description`, `version`,
 * `repository`, `websiteUrl`, `$schema`). The live registry schema URL evolves;
 * this environment cannot reach it to pin the exact revision, so the implemented
 * fields are listed here and flagged — verify against the current
 * `server.json` schema before submitting a listing.
 *
 * @api
 */
final readonly class McpServerCardConfig
{
    /**
     * @param 'none'|'bearer'|'oauth2' $authType Declared auth scheme. The public
     *        read-only surface advertises `none`.
     * @param ?string $registryName Namespaced registry id (e.g. `io.github.owner/server`).
     * @param ?string $repositoryUrl Source repository URL (registry `repository.url`).
     * @param ?string $websiteUrl Project/site URL (registry `websiteUrl`).
     * @param ?string $schemaUrl  registry `$schema` pointer (verify current revision).
     */
    public function __construct(
        public string $name = 'Waaseyaa',
        public string $description = 'AI-native content management system',
        public string $version = '0.1.0',
        public string $endpoint = '/mcp',
        public string $authType = 'none',
        public ?string $url = null,
        public ?string $registryName = null,
        public ?string $repositoryUrl = null,
        public ?string $websiteUrl = null,
        public ?string $schemaUrl = null,
    ) {}

    /**
     * Build from a config array (e.g. `mcp.server_card` in config sync).
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $string = static fn(string $key, ?string $default): ?string
            => isset($config[$key]) && \is_string($config[$key]) && $config[$key] !== '' ? $config[$key] : $default;

        $authType = $string('auth_type', 'none');
        $authType = \in_array($authType, ['none', 'bearer', 'oauth2'], true) ? $authType : 'none';

        return new self(
            name: $string('name', 'Waaseyaa') ?? 'Waaseyaa',
            description: $string('description', 'AI-native content management system') ?? 'AI-native content management system',
            version: $string('version', '0.1.0') ?? '0.1.0',
            endpoint: $string('endpoint', '/mcp') ?? '/mcp',
            authType: $authType,
            url: $string('url', null),
            registryName: $string('registry_name', null),
            repositoryUrl: $string('repository_url', null),
            websiteUrl: $string('website_url', null),
            schemaUrl: $string('schema_url', null),
        );
    }
}
