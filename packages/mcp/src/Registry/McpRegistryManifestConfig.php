<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Registry;

use Waaseyaa\Foundation\Exception\ConfigException;

/** Strict deployment-owned inputs for an official MCP Registry server.json. */
final readonly class McpRegistryManifestConfig
{
    private function __construct(
        public string $name,
        public string $description,
        public string $remoteUrl,
        public ?string $repositoryUrl = null,
        public ?string $repositorySource = null,
        public ?string $websiteUrl = null,
    ) {}

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        $allowed = ['name', 'description', 'remote_url', 'repository_url', 'repository_source', 'website_url'];
        foreach (\array_keys($config) as $key) {
            if (!\in_array($key, $allowed, true)) {
                throw self::malformed($key, 'a supported Registry manifest key');
            }
        }

        $name = self::requiredString($config, 'name');
        if (\preg_match('/^[a-zA-Z0-9.-]+\/[a-zA-Z0-9._-]+$/D', $name) !== 1 || \strlen($name) > 200) {
            throw self::malformed('name', 'a namespaced Registry id such as io.github.owner/server');
        }

        $description = self::requiredString($config, 'description');
        if (\strlen($description) > 100 || \preg_match('//u', $description) !== 1) {
            throw self::malformed('description', 'valid UTF-8 text of at most 100 bytes');
        }

        $remoteUrl = self::requiredString($config, 'remote_url');
        self::assertHttpsUri($remoteUrl, 'remote_url');

        $repositoryUrl = self::optionalString($config, 'repository_url');
        if ($repositoryUrl !== null) {
            self::assertHttpsUri($repositoryUrl, 'repository_url');
        }
        $repositorySource = self::optionalString($config, 'repository_source');
        if ($repositoryUrl === null && $repositorySource !== null) {
            throw self::malformed('repository_source', 'absent unless repository_url is configured');
        }
        if ($repositoryUrl !== null && $repositorySource === null) {
            $repositorySource = \strtolower((string) \parse_url($repositoryUrl, \PHP_URL_HOST)) === 'github.com'
                ? 'github'
                : null;
        }
        if ($repositoryUrl !== null && $repositorySource === null) {
            throw self::malformed('repository_source', 'a source-forge identifier for repository_url');
        }

        $websiteUrl = self::optionalString($config, 'website_url');
        if ($websiteUrl !== null) {
            self::assertHttpsUri($websiteUrl, 'website_url');
        }

        return new self($name, $description, $remoteUrl, $repositoryUrl, $repositorySource, $websiteUrl);
    }

    /** @param array<string, mixed> $config */
    private static function requiredString(array $config, string $key): string
    {
        return self::optionalString($config, $key)
            ?? throw self::malformed($key, 'a non-empty string');
    }

    /** @param array<string, mixed> $config */
    private static function optionalString(array $config, string $key): ?string
    {
        if (!\array_key_exists($key, $config)) {
            return null;
        }
        if (!\is_string($config[$key]) || $config[$key] === '' || $config[$key] !== \trim($config[$key])) {
            throw self::malformed($key, 'a non-empty trimmed string');
        }

        return $config[$key];
    }

    private static function assertHttpsUri(string $uri, string $key): void
    {
        $parts = \parse_url($uri);
        if (!\is_array($parts)
            || \strtolower($parts['scheme'] ?? '') !== 'https'
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw self::malformed($key, 'an absolute public HTTPS URI without credentials, query, or fragment');
        }
        $host = \strtolower($parts['host']);
        if (\str_starts_with($host, '[') && \str_ends_with($host, ']')) {
            $host = \substr($host, 1, -1);
        }
        if (\in_array($host, ['localhost', '127.0.0.1', '::1'], true) || \str_ends_with($host, '.localhost')) {
            throw self::malformed($key, 'an absolute public HTTPS URI, not a loopback address');
        }
        if (\filter_var($host, \FILTER_VALIDATE_IP) !== false
            && \filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE) === false
        ) {
            throw self::malformed($key, 'an absolute public HTTPS URI, not a private or reserved IP address');
        }
    }

    private static function malformed(string $key, string $expectation): ConfigException
    {
        return new ConfigException('mcp.registry.' . $key . ' must be ' . $expectation . '.');
    }
}
