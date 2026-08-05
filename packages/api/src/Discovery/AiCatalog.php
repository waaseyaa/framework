<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Discovery;

use Waaseyaa\Foundation\Discovery\AiCatalog\AiCatalogEntry;

/** Deterministic ARD v0.9 extension of the draft AI Catalog 1.0 document. */
final class AiCatalog
{
    public const string PATH = '/.well-known/ai-catalog.json';
    public const string MEDIA_TYPE = 'application/ai-catalog+json';
    public const string SPEC_VERSION = '1.0';

    private string $baseUrl;
    private string $publisher;

    /** @var list<AiCatalogEntry> */
    private array $entries;

    /** @var array<string, list<string>> */
    private array $representativeQueries;

    /**
     * @param list<AiCatalogEntry> $entries
     * @param array<string, list<string>> $representativeQueries
     * @phpstan-param list<mixed> $entries Runtime validation protects the public array boundary.
     * @phpstan-param array<mixed, mixed> $representativeQueries Runtime validation protects configuration input.
     */
    public function __construct(string $baseUrl, array $entries, array $representativeQueries = [])
    {
        [$this->baseUrl, $this->publisher] = self::canonicalAuthority($baseUrl);
        $this->entries = self::normalizeEntries($entries);
        $this->representativeQueries = self::normalizeRepresentativeQueries(
            $representativeQueries,
            array_column($this->entries, 'key'),
        );
    }

    public function catalogUrl(): string
    {
        return $this->absolute(self::PATH);
    }

    /** @return array{specVersion: string, entries: list<array<string, mixed>>} */
    public function toArray(): array
    {
        $entries = [];
        foreach ($this->entries as $entry) {
            $data = [
                'identifier' => 'urn:air:' . $this->publisher . ':' . $entry->key,
                // ARD's pinned extension schema requires displayName even though
                // the base AI Catalog draft only recommends omitting duplicates.
                'displayName' => $entry->displayName,
                'type' => $entry->type,
                'url' => $this->absolute($entry->path),
            ];
            if ($entry->description !== null) {
                $data['description'] = $entry->description;
            }
            if ($entry->capabilities !== []) {
                $data['capabilities'] = $entry->capabilities;
            }
            if (isset($this->representativeQueries[$entry->key])) {
                $data['representativeQueries'] = $this->representativeQueries[$entry->key];
            }
            $entries[] = $data;
        }

        return [
            'specVersion' => self::SPEC_VERSION,
            'entries' => $entries,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /** @param array<mixed, mixed> $queries */
    public static function assertRepresentativeQueries(array $queries): void
    {
        self::normalizeRepresentativeQueries($queries, null);
    }

    /**
     * @param list<mixed> $entries
     * @param array<mixed, mixed> $queries
     */
    public static function assertEntryQueryCompatibility(array $entries, array $queries): void
    {
        $entries = self::normalizeEntries($entries);
        self::normalizeRepresentativeQueries($queries, array_column($entries, 'key'));
    }

    private function absolute(string $path): string
    {
        return $this->baseUrl . $path;
    }

    /** @return array{string, string} */
    private static function canonicalAuthority(string $baseUrl): array
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $parts = parse_url($baseUrl);
        $path = is_array($parts) && is_string($parts['path'] ?? null) ? $parts['path'] : '';
        $decodedPath = rawurldecode($path);
        $host = is_array($parts) && is_string($parts['host'] ?? null)
            ? strtolower(rtrim($parts['host'], '.'))
            : '';
        if (
            $baseUrl === ''
            || preg_match('/[\x00-\x20\x7f]/', $baseUrl) === 1
            || $parts === false
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $host) !== 1
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || str_contains($path, '\\')
            || preg_match('/[\x00-\x20\x7f]/', $decodedPath) === 1
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $path) === 1
            || preg_match('/%(?:25|2f|5c)/i', $path) === 1
            || preg_match('#(?:^|/)\.\.?($|/)#', $decodedPath) === 1
        ) {
            throw new \InvalidArgumentException('AI catalog base URL must be a canonical HTTPS URL with a DNS authority.');
        }

        return [$baseUrl, $host];
    }

    /**
     * @param list<mixed> $entries
     * @return list<AiCatalogEntry>
     */
    private static function normalizeEntries(array $entries): array
    {
        /** @var array<string, array{fingerprint: string, entry: AiCatalogEntry}> $byKey */
        $byKey = [];
        foreach ($entries as $entry) {
            if (!$entry instanceof AiCatalogEntry) {
                throw new \InvalidArgumentException('AI catalog entries must be AiCatalogEntry values.');
            }
            $fingerprint = json_encode($entry->fingerprintData(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (isset($byKey[$entry->key]) && $byKey[$entry->key]['fingerprint'] !== $fingerprint) {
                throw new \LogicException(sprintf('Conflicting AI catalog definitions for entry "%s".', $entry->key));
            }
            $byKey[$entry->key] ??= ['fingerprint' => $fingerprint, 'entry' => $entry];
        }
        ksort($byKey, SORT_STRING);

        return array_values(array_map(
            static fn(array $row): AiCatalogEntry => $row['entry'],
            $byKey,
        ));
    }

    /**
     * @param array<mixed, mixed> $queries
     * @param list<string>|null $installedKeys
     * @return array<string, list<string>>
     */
    private static function normalizeRepresentativeQueries(array $queries, ?array $installedKeys): array
    {
        $installed = $installedKeys === null ? null : array_fill_keys($installedKeys, true);
        $normalized = [];
        foreach ($queries as $key => $examples) {
            if (!is_string($key) || preg_match('/^[a-z0-9][a-z0-9._-]*(?::[a-z0-9][a-z0-9._-]*)+$/', $key) !== 1) {
                throw new \InvalidArgumentException('ai_catalog.representative_queries keys must be lowercase URN-safe entry keys.');
            }
            if ($installed !== null && !isset($installed[$key])) {
                throw new \InvalidArgumentException('ai_catalog.representative_queries contains an unknown public entry key.');
            }
            if (!is_array($examples) || !array_is_list($examples) || count($examples) < 2 || count($examples) > 5) {
                throw new \InvalidArgumentException('ai_catalog.representative_queries entries must contain 2 to 5 strings.');
            }
            $seen = [];
            foreach ($examples as $example) {
                if (
                    !is_string($example)
                    || $example === ''
                    || trim($example) !== $example
                    || strlen($example) > 300
                    || preg_match('/[\x00-\x1f\x7f]/', $example) !== 0
                    || isset($seen[$example])
                ) {
                    throw new \InvalidArgumentException('ai_catalog.representative_queries entries must be unique public text strings.');
                }
                $seen[$example] = true;
            }
            $normalized[$key] = $examples;
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }
}
