<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\ContentSearch;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/**
 * Version-bounded adapter over the optional waaseyaa/search package.
 *
 * Search FQCNs stay as strings so ai-tools can remain usable without Search.
 * Every value is copied into an ai-tools-owned closed array before it reaches
 * an agent or MCP caller.
 */
final class SearchPackageContentSearchAdapter
{
    private const string PROVIDER = 'Waaseyaa\\Search\\SearchProviderInterface';
    private const string FILTERS = 'Waaseyaa\\Search\\SearchFilters';
    private const string REQUEST = 'Waaseyaa\\Search\\SearchRequest';
    private const string HIT = 'Waaseyaa\\Search\\SearchHit';
    private const string FACET = 'Waaseyaa\\Search\\SearchFacet';
    private const string BUCKET = 'Waaseyaa\\Search\\FacetBucket';

    /** @var \Closure(object, AuthorizationPrincipalInterface): mixed */
    private readonly \Closure $search;

    public function __construct(object $provider)
    {
        if (!is_a($provider, self::PROVIDER)) {
            throw new ContentSearchBoundaryException('The optional search provider has an incompatible contract.');
        }
        foreach ([self::FILTERS, self::REQUEST, self::HIT, self::FACET, self::BUCKET] as $class) {
            if (!class_exists($class)) {
                throw new ContentSearchBoundaryException('The optional search package is incomplete.');
            }
        }
        $this->search = \Closure::fromCallable([$provider, 'search']);
    }

    public static function isAvailable(): bool
    {
        if (!interface_exists(self::PROVIDER)) {
            return false;
        }
        foreach ([self::FILTERS, self::REQUEST, self::HIT, self::FACET, self::BUCKET] as $class) {
            if (!class_exists($class)) {
                return false;
            }
        }

        return true;
    }

    public static function providerServiceId(): string
    {
        return self::PROVIDER;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function search(array $arguments, AuthorizationPrincipalInterface $principal): array
    {
        $filtersClass = self::FILTERS;
        $requestClass = self::REQUEST;
        $filters = new $filtersClass(
            topics: $arguments['topics'] ?? [],
            contentType: $arguments['content_type'] ?? '',
            sourceNames: $arguments['sources'] ?? [],
            minQuality: $arguments['min_quality'] ?? 0,
            sortField: $arguments['sort_field'] ?? 'relevance',
            sortOrder: $arguments['sort_order'] ?? 'desc',
        );
        $request = new $requestClass(
            query: $arguments['query'],
            filters: $filters,
            page: $arguments['page'] ?? 1,
            pageSize: $arguments['page_size'] ?? 20,
            includeFacets: $arguments['include_facets'] ?? true,
        );
        $result = self::invokeSearch($this->search, $request, $principal);
        if (!is_object($result)) {
            throw new ContentSearchBoundaryException('The optional search provider returned an invalid result.');
        }

        return [
            'total_hits' => self::nonNegativeInt($result, 'totalHits'),
            'total_pages' => self::nonNegativeInt($result, 'totalPages'),
            'current_page' => self::boundedInt($result, 'currentPage', 1, 200),
            'page_size' => self::boundedInt($result, 'pageSize', 1, 100),
            'hits' => self::hits($result),
            'facets' => self::facets($result),
        ];
    }

    private static function invokeSearch(\Closure $search, object $request, AuthorizationPrincipalInterface $principal): mixed
    {
        return $search($request, $principal);
    }

    /** @return list<array<string, mixed>> */
    private static function hits(object $result): array
    {
        $source = self::listProperty($result, 'hits');
        if (count($source) > 100) {
            throw new ContentSearchBoundaryException('The optional search provider returned too many hits.');
        }
        $hits = [];
        foreach ($source as $hit) {
            if (!is_object($hit) || !is_a($hit, self::HIT)) {
                throw new ContentSearchBoundaryException('The optional search provider returned an invalid hit.');
            }
            $hits[] = [
                'id' => self::boundedString($hit, 'id', 256),
                'title' => self::boundedString($hit, 'title', 512),
                'url' => self::boundedString($hit, 'url', 2048),
                'highlight' => self::boundedString($hit, 'highlight', 4096),
                'score' => self::finiteFloat($hit, 'score'),
                'source' => self::boundedString($hit, 'sourceName', 128),
                'content_type' => self::boundedString($hit, 'contentType', 128),
                'quality_score' => self::boundedInt($hit, 'qualityScore', 0, 100),
                'crawled_at' => self::boundedString($hit, 'crawledAt', 64),
                'topics' => self::stringList($hit, 'topics', 20, 128),
                'image' => self::boundedString($hit, 'ogImage', 2048),
            ];
        }

        return $hits;
    }

    /** @return list<array{name: string, buckets: list<array{key: string, count: int}>}> */
    private static function facets(object $result): array
    {
        $source = self::listProperty($result, 'facets');
        if (count($source) > 20) {
            throw new ContentSearchBoundaryException('The optional search provider returned too many facets.');
        }
        $facets = [];
        foreach ($source as $facet) {
            if (!is_object($facet) || !is_a($facet, self::FACET)) {
                throw new ContentSearchBoundaryException('The optional search provider returned an invalid facet.');
            }
            $bucketSource = self::listProperty($facet, 'buckets');
            if (count($bucketSource) > 100) {
                throw new ContentSearchBoundaryException('The optional search provider returned too many facet buckets.');
            }
            $buckets = [];
            foreach ($bucketSource as $bucket) {
                if (!is_object($bucket) || !is_a($bucket, self::BUCKET)) {
                    throw new ContentSearchBoundaryException('The optional search provider returned an invalid facet bucket.');
                }
                $buckets[] = [
                    'key' => self::boundedString($bucket, 'key', 128),
                    'count' => self::nonNegativeInt($bucket, 'count'),
                ];
            }
            $facets[] = ['name' => self::boundedString($facet, 'name', 128), 'buckets' => $buckets];
        }

        return $facets;
    }

    private static function property(object $object, string $property): mixed
    {
        return get_object_vars($object)[$property] ?? null;
    }

    /** @return list<mixed> */
    private static function listProperty(object $object, string $property): array
    {
        $value = self::property($object, $property);
        if (!is_array($value) || !array_is_list($value)) {
            throw new ContentSearchBoundaryException('The optional search result contains an invalid list.');
        }

        return $value;
    }

    private static function boundedString(object $object, string $property, int $maximum): string
    {
        $value = self::property($object, $property);
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $maximum) {
            throw new ContentSearchBoundaryException('The optional search result contains an invalid string.');
        }

        return $value;
    }

    private static function nonNegativeInt(object $object, string $property): int
    {
        $value = self::property($object, $property);
        if (!is_int($value) || $value < 0) {
            throw new ContentSearchBoundaryException('The optional search result contains an invalid integer.');
        }

        return $value;
    }

    private static function boundedInt(object $object, string $property, int $minimum, int $maximum): int
    {
        $value = self::property($object, $property);
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new ContentSearchBoundaryException('The optional search result contains an invalid bounded integer.');
        }

        return $value;
    }

    private static function finiteFloat(object $object, string $property): float
    {
        $value = self::property($object, $property);
        if (!is_float($value) || !is_finite($value)) {
            throw new ContentSearchBoundaryException('The optional search result contains an invalid score.');
        }

        return $value;
    }

    /** @return list<string> */
    private static function stringList(object $object, string $property, int $maximumCount, int $maximumLength): array
    {
        $values = self::listProperty($object, $property);
        if (count($values) > $maximumCount) {
            throw new ContentSearchBoundaryException('The optional search result contains too many string values.');
        }
        foreach ($values as $value) {
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $maximumLength) {
                throw new ContentSearchBoundaryException('The optional search result contains an invalid string list.');
            }
        }

        return $values;
    }
}
