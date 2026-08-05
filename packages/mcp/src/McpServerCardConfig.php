<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp;

use Waaseyaa\Foundation\Exception\ConfigException;

/**
 * Configurable advertisement for the compatibility card served at
 * `/.well-known/mcp.json`. Shared name/version identity belongs to
 * {@see McpImplementationInfo}; official Registry metadata belongs to
 * `Registry\\McpRegistryManifestConfig`.
 *
 * @api
 */
final readonly class McpServerCardConfig
{
    /**
     * @param 'none'|'bearer'|'oauth2' $authType Declared auth scheme. OAuth
     *        deployments pair this card with RFC 9728 protected-resource
     *        metadata and an OAuthAccessTokenValidatorInterface implementation.
     */
    public function __construct(
        public string $description = 'AI-native content management system',
        public string $endpoint = '/mcp',
        public string $authType = 'none',
        public ?string $url = null,
    ) {
        if ($this->description === ''
            || $this->description !== \trim($this->description)
            || \preg_match('//u', $this->description) !== 1
            || \preg_match('/[\x00-\x1F\x7F]/', $this->description) === 1
        ) {
            throw self::malformed('description', 'non-empty trimmed UTF-8 text without control characters');
        }
        self::assertEndpoint($this->endpoint);
        if (!\in_array($this->authType, ['none', 'bearer', 'oauth2'], true)) {
            throw self::malformed('auth_type', 'one of none, bearer, or oauth2');
        }
        if ($this->url !== null) {
            self::assertSecureAbsoluteUri($this->url, 'url');
        }
    }

    /**
     * Build from a config array (e.g. `mcp.server_card` in config sync).
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        foreach (['name', 'version'] as $legacyIdentityKey) {
            if (\array_key_exists($legacyIdentityKey, $config)) {
                throw new ConfigException('mcp.server_card.' . $legacyIdentityKey . ' moved to mcp.implementation.');
            }
        }
        foreach (['registry_name', 'repository_url', 'website_url', 'schema_url'] as $legacyRegistryKey) {
            if (\array_key_exists($legacyRegistryKey, $config)) {
                throw new ConfigException('mcp.server_card.' . $legacyRegistryKey . ' moved to mcp.registry.');
            }
        }
        foreach (\array_keys($config) as $key) {
            if (!\in_array($key, ['description', 'endpoint', 'auth_type', 'url'], true)) {
                throw self::malformed($key, 'a supported compatibility-card key');
            }
        }

        $description = self::string($config, 'description', 'AI-native content management system');
        $endpoint = self::string($config, 'endpoint', '/mcp');

        $authType = self::string($config, 'auth_type', 'none');

        $url = self::nullableString($config, 'url');

        return new self(
            description: $description,
            endpoint: $endpoint,
            authType: $authType,
            url: $url,
        );
    }

    /** @param array<string, mixed> $config */
    private static function string(array $config, string $key, string $default): string
    {
        return self::nullableString($config, $key) ?? $default;
    }

    /** @param array<string, mixed> $config */
    private static function nullableString(array $config, string $key): ?string
    {
        if (!\array_key_exists($key, $config)) {
            return null;
        }
        if (!\is_string($config[$key]) || $config[$key] === '' || $config[$key] !== \trim($config[$key])) {
            throw self::malformed($key, 'a non-empty trimmed string');
        }

        return $config[$key];
    }

    private static function assertSecureAbsoluteUri(string $uri, string $key): void
    {
        $parts = \parse_url($uri);
        if (!\is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || !\in_array(\strtolower($parts['scheme']), ['http', 'https'], true)
        ) {
            throw self::malformed($key, 'an absolute HTTP(S) URI without credentials, query, or fragment');
        }
        $host = \strtolower($parts['host']);
        if (\strtolower($parts['scheme']) !== 'https' && !\in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            throw self::malformed($key, 'HTTPS except on a loopback host');
        }
    }

    private static function assertEndpoint(string $endpoint): void
    {
        if (!\str_starts_with($endpoint, '/')
            || \str_starts_with($endpoint, '//')
            || \str_contains($endpoint, '?')
            || \str_contains($endpoint, '#')
        ) {
            throw self::malformed('endpoint', 'an absolute-path reference without query or fragment');
        }
    }

    private static function malformed(string $key, string $expectation): ConfigException
    {
        return new ConfigException('mcp.server_card.' . $key . ' must be ' . $expectation . '.');
    }
}
