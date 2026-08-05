<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Discovery\AiCatalog;

/**
 * One intentionally public AI artifact contributed by an installed provider.
 *
 * The key is deployment-neutral and becomes the suffix of a domain-anchored
 * `urn:air` identifier at serialization time. Artifact locations are limited
 * to safe same-origin paths; providers cannot supply an authority.
 *
 * @api
 */
final readonly class AiCatalogEntry
{
    /** @var list<string> */
    public array $capabilities;

    /**
     * @param list<string> $capabilities
     * @phpstan-param list<mixed> $capabilities Runtime validation protects the public array boundary.
     */
    public function __construct(
        public string $key,
        public string $displayName,
        public string $type,
        public string $path,
        public ?string $description = null,
        array $capabilities = [],
    ) {
        self::assertKey($key);
        self::assertPublicText($displayName, 'display name', 200);
        self::assertMediaType($type);
        self::assertPath($path);
        if ($description !== null) {
            self::assertPublicText($description, 'description', 1000);
        }
        $this->capabilities = self::normalizeCapabilities($capabilities);
    }

    /** @return array{key: string, displayName: string, type: string, path: string, description: ?string, capabilities: list<string>} */
    public function fingerprintData(): array
    {
        return [
            'key' => $this->key,
            'displayName' => $this->displayName,
            'type' => $this->type,
            'path' => $this->path,
            'description' => $this->description,
            'capabilities' => $this->capabilities,
        ];
    }

    private static function assertKey(string $key): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]*(?::[a-z0-9][a-z0-9._-]*)+$/', $key) !== 1) {
            throw new \InvalidArgumentException('AI catalog entry key must contain at least two lowercase URN-safe segments.');
        }
    }

    private static function assertMediaType(string $type): void
    {
        if (preg_match('/^[A-Za-z0-9!#$&^_.+\-]+\/[A-Za-z0-9!#$&^_.+\-]+$/', $type) !== 1) {
            throw new \InvalidArgumentException('AI catalog entry media type is malformed.');
        }
    }

    private static function assertPath(string $path): void
    {
        $decoded = rawurldecode($path);
        if (
            $path === ''
            || !str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_contains($path, '\\')
            || str_contains($path, '?')
            || str_contains($path, '#')
            || preg_match('/[\x00-\x20\x7f]/', $path) === 1
            || preg_match('/[\x00-\x20\x7f]/', $decoded) === 1
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $path) === 1
            || preg_match('/%(?:25|2f|5c)/i', $path) === 1
            || preg_match('#(?:^|/)\.\.?($|/)#', $decoded) === 1
        ) {
            throw new \InvalidArgumentException('AI catalog artifact must be a safe root-relative path.');
        }

        $parts = parse_url($path);
        if (
            $parts === false
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new \InvalidArgumentException('AI catalog artifact must stay on the configured canonical origin.');
        }
    }

    private static function assertPublicText(string $value, string $field, int $maxBytes): void
    {
        if (
            $value === ''
            || trim($value) !== $value
            || strlen($value) > $maxBytes
            || preg_match('/[\x00-\x1f\x7f]/', $value) !== 0
        ) {
            throw new \InvalidArgumentException(sprintf('AI catalog entry %s is malformed.', $field));
        }
    }

    /**
     * @param list<mixed> $capabilities
     * @return list<string>
     */
    private static function normalizeCapabilities(array $capabilities): array
    {
        $normalized = [];
        foreach ($capabilities as $capability) {
            if (!is_string($capability)) {
                throw new \InvalidArgumentException('AI catalog capabilities must be strings.');
            }
            self::assertPublicText($capability, 'capability', 100);
            $normalized[$capability] = $capability;
        }
        ksort($normalized, SORT_STRING);

        return array_values($normalized);
    }
}
