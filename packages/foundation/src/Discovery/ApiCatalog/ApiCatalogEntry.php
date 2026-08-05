<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Discovery\ApiCatalog;

/**
 * One intentionally public API endpoint and its bounded RFC 8631 metadata.
 *
 * Sitemap, robots, and llms.txt documents are not API endpoints and therefore
 * do not belong here. A contributor may describe an endpoint through the
 * machine-facing `service-desc`, human-facing `service-doc`, and auxiliary
 * `service-meta` relations without widening the catalog to arbitrary links.
 *
 * @api
 */
final readonly class ApiCatalogEntry
{
    /**
     * @param list<ApiCatalogTarget> $serviceDescriptions
     * @param list<ApiCatalogTarget> $serviceDocumentation
     * @param list<ApiCatalogTarget> $serviceMetadata
     * @phpstan-param list<mixed> $serviceDescriptions Runtime validation protects the public array boundary.
     * @phpstan-param list<mixed> $serviceDocumentation Runtime validation protects the public array boundary.
     * @phpstan-param list<mixed> $serviceMetadata Runtime validation protects the public array boundary.
     */
    public function __construct(
        public ApiCatalogTarget $endpoint,
        public array $serviceDescriptions = [],
        public array $serviceDocumentation = [],
        public array $serviceMetadata = [],
    ) {
        self::assertTargetList($serviceDescriptions);
        self::assertTargetList($serviceDocumentation);
        self::assertTargetList($serviceMetadata);
    }

    /** @return array<string, mixed> */
    public function fingerprintData(): array
    {
        return [
            'endpoint' => $this->endpoint->fingerprintData(),
            'service-desc' => self::normalizedTargetData($this->serviceDescriptions),
            'service-doc' => self::normalizedTargetData($this->serviceDocumentation),
            'service-meta' => self::normalizedTargetData($this->serviceMetadata),
        ];
    }

    /**
     * @param list<ApiCatalogTarget> $targets
     * @return list<array{path: string, type: ?string, title: ?string}>
     */
    private static function normalizedTargetData(array $targets): array
    {
        $data = [];
        foreach ($targets as $target) {
            $fingerprint = json_encode($target->fingerprintData(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $data[$fingerprint] = $target->fingerprintData();
        }
        ksort($data, SORT_STRING);

        return array_values($data);
    }

    /** @param list<mixed> $targets */
    private static function assertTargetList(array $targets): void
    {
        foreach ($targets as $target) {
            if (!$target instanceof ApiCatalogTarget) {
                throw new \InvalidArgumentException('API catalog relation targets must be ApiCatalogTarget values.');
            }
        }
    }
}
