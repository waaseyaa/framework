<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp;

use Waaseyaa\Foundation\Exception\ConfigException;

/** Resolves the shared MCP identity without deriving it from an HTTP request. */
final readonly class McpImplementationInfoResolver
{
    public function __construct(private ?string $installedPackageVersion) {}

    /** @param array<string, mixed> $config */
    public function resolve(string $projectRoot, array $config): McpImplementationInfo
    {
        $mcp = $config['mcp'] ?? [];
        if (!\is_array($mcp)) {
            throw self::malformed('mcp', 'a configuration map');
        }
        $implementation = $mcp['implementation'] ?? [];
        if (!\is_array($implementation)) {
            throw self::malformed('mcp.implementation', 'a configuration map');
        }
        foreach (\array_keys($implementation) as $key) {
            if (!\is_string($key) || !\in_array($key, ['name', 'version'], true)) {
                throw self::malformed('mcp.implementation.' . (string) $key, 'a supported identity key');
            }
        }

        $name = self::optionalString($implementation, 'name', 'mcp.implementation.name') ?? 'Waaseyaa';
        $configuredVersion = self::optionalString($implementation, 'version', 'mcp.implementation.version');
        $version = $configuredVersion
            ?? $this->frameworkCheckoutVersion($projectRoot)
            ?? self::normalizeVersion($this->installedPackageVersion)
            ?? '0.0.0+unknown';

        try {
            return new McpImplementationInfo($name, $version);
        } catch (\InvalidArgumentException) {
            throw self::malformed('mcp.implementation', 'bounded non-empty UTF-8 name and version strings');
        }
    }

    private function frameworkCheckoutVersion(string $projectRoot): ?string
    {
        if ($projectRoot === '') {
            return null;
        }
        $composerPath = $projectRoot . '/composer.json';
        $versionPath = $projectRoot . '/VERSION';
        if (!\is_file($composerPath) || !\is_file($versionPath)) {
            return null;
        }

        try {
            $composer = \json_decode((string) \file_get_contents($composerPath), true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($composer) || ($composer['name'] ?? null) !== 'waaseyaa/framework') {
            return null;
        }

        return self::normalizeVersion((string) \file_get_contents($versionPath));
    }

    private static function normalizeVersion(?string $version): ?string
    {
        if ($version === null) {
            return null;
        }
        $version = \trim($version);
        if ($version === '') {
            return null;
        }
        if (\preg_match('/^v(?=\d)/', $version) === 1) {
            $version = \substr($version, 1);
        }

        return $version;
    }

    /** @param array<string, mixed> $config */
    private static function optionalString(array $config, string $key, string $path): ?string
    {
        if (!\array_key_exists($key, $config)) {
            return null;
        }
        if (!\is_string($config[$key]) || \trim($config[$key]) === '') {
            throw self::malformed($path, 'a non-empty string');
        }

        return $config[$key];
    }

    private static function malformed(string $key, string $expectation): ConfigException
    {
        return new ConfigException($key . ' must be ' . $expectation . '.');
    }
}
