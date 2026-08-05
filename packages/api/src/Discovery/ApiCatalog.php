<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Discovery;

use Waaseyaa\Foundation\Discovery\ApiCatalog\ApiCatalogEntry;
use Waaseyaa\Foundation\Discovery\ApiCatalog\ApiCatalogTarget;

/**
 * Deterministic RFC 9727 API Catalog serialized as an RFC 9264 JSON Linkset.
 */
final class ApiCatalog
{
    public const string PATH = '/.well-known/api-catalog';
    public const string PROFILE = 'https://www.rfc-editor.org/info/rfc9727';
    public const string MEDIA_TYPE = 'application/linkset+json';

    private string $baseUrl;

    /** @var list<ApiCatalogEntry> */
    private array $entries;

    /**
     * @param list<ApiCatalogEntry> $entries
     * @phpstan-param list<mixed> $entries Runtime validation protects the public array boundary.
     */
    public function __construct(string $baseUrl, array $entries)
    {
        $this->baseUrl = self::canonicalBaseUrl($baseUrl);
        $this->entries = self::normalizeEntries($entries);
    }

    public function catalogUrl(): string
    {
        return $this->absolute(ApiCatalog::PATH);
    }

    /** @return array{linkset: list<array<string, mixed>>} */
    public function toArray(): array
    {
        $items = array_map(
            fn(ApiCatalogEntry $entry): array => $this->targetToArray($entry->endpoint),
            $this->entries,
        );

        $contexts = [[
            'anchor' => $this->catalogUrl(),
            'item' => $items,
        ]];

        foreach ($this->entries as $entry) {
            $context = ['anchor' => $this->absolute($entry->endpoint->path)];
            foreach ([
                'service-desc' => $entry->serviceDescriptions,
                'service-doc' => $entry->serviceDocumentation,
                'service-meta' => $entry->serviceMetadata,
            ] as $relation => $targets) {
                if ($targets !== []) {
                    $targets = $this->normalizeTargets($targets);
                    $context[$relation] = array_map(
                        fn(ApiCatalogTarget $target): array => $this->targetToArray($target),
                        $targets,
                    );
                }
            }
            if (count($context) > 1) {
                $contexts[] = $context;
            }
        }

        return ['linkset' => $contexts];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /** @return array<string, string> */
    private function targetToArray(ApiCatalogTarget $target): array
    {
        return array_filter([
            'href' => $this->absolute($target->path),
            'type' => $target->type,
            'title' => $target->title,
        ], static fn(?string $value): bool => $value !== null);
    }

    private function absolute(string $path): string
    {
        return $this->baseUrl . $path;
    }

    /**
     * @param list<ApiCatalogTarget> $targets
     * @return list<ApiCatalogTarget>
     */
    private function normalizeTargets(array $targets): array
    {
        $unique = [];
        foreach ($targets as $target) {
            $fingerprint = json_encode($target->fingerprintData(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $unique[$fingerprint] = $target;
        }
        ksort($unique, SORT_STRING);

        return array_values($unique);
    }

    private static function canonicalBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $parts = parse_url($baseUrl);
        if (
            $baseUrl === ''
            || preg_match('/[\x00-\x20\x7f]/', $baseUrl) === 1
            || $parts === false
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || !is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || preg_match('#(?:^|/)\.\.?($|/)#', $parts['path'] ?? '') === 1
        ) {
            throw new \InvalidArgumentException('API catalog base URL must be a canonical HTTPS origin with an optional path prefix.');
        }

        return $baseUrl;
    }

    /**
     * @param list<mixed> $entries
     * @return list<ApiCatalogEntry>
     */
    private static function normalizeEntries(array $entries): array
    {
        /** @var array<string, array{fingerprint: string, entry: ApiCatalogEntry}> $byPath */
        $byPath = [];
        foreach ($entries as $entry) {
            if (!$entry instanceof ApiCatalogEntry) {
                throw new \InvalidArgumentException('API catalog entries must be ApiCatalogEntry values.');
            }
            $path = $entry->endpoint->path;
            $fingerprint = json_encode($entry->fingerprintData(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (isset($byPath[$path]) && $byPath[$path]['fingerprint'] !== $fingerprint) {
                throw new \LogicException(sprintf('Conflicting API catalog definitions for endpoint "%s".', $path));
            }
            $byPath[$path] ??= ['fingerprint' => $fingerprint, 'entry' => $entry];
        }

        ksort($byPath, SORT_STRING);

        return array_values(array_map(
            static fn(array $row): ApiCatalogEntry => $row['entry'],
            $byPath,
        ));
    }
}
