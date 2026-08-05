<?php

declare(strict_types=1);

namespace Waaseyaa\Api\ContentSearch;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

final class SearchPackageContentSearchAdapter implements ContentSearchReadModelInterface
{
    private const string PROVIDER = 'Waaseyaa\\Search\\SearchProviderInterface';
    private const string FILTERS = 'Waaseyaa\\Search\\SearchFilters';
    private const string REQUEST = 'Waaseyaa\\Search\\SearchRequest';
    private const string RESULT = 'Waaseyaa\\Search\\SearchResult';
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
        foreach ([self::FILTERS, self::REQUEST, self::RESULT, self::HIT, self::FACET, self::BUCKET] as $class) {
            if (!class_exists($class)) {
                throw new ContentSearchBoundaryException('The optional search package is incomplete.');
            }
        }
        $this->search = \Closure::fromCallable([$provider, 'search']);
    }

    public function search(ContentSearchQuery $query, AuthorizationPrincipalInterface $principal): ContentSearchPage
    {
        $filtersClass = self::FILTERS;
        $requestClass = self::REQUEST;
        $filters = new $filtersClass(
            topics: $query->topics,
            contentType: $query->contentType,
            sourceNames: $query->sourceNames,
            minQuality: $query->minQuality,
            sortField: $query->sortField,
            sortOrder: $query->sortOrder,
        );
        $request = new $requestClass(
            query: $query->query,
            filters: $filters,
            page: $query->page,
            pageSize: $query->pageSize,
            includeFacets: $query->includeFacets,
        );
        $result = ($this->search)($request, $principal);
        // is_a() on the provider establishes the interface's SearchResult
        // return type at the PHP boundary. Every nested object and scalar is
        // still revalidated below before it enters the API-owned DTO.

        return new ContentSearchPage(
            totalHits: self::intProperty($result, 'totalHits'),
            totalPages: self::intProperty($result, 'totalPages'),
            currentPage: self::intProperty($result, 'currentPage'),
            pageSize: self::intProperty($result, 'pageSize'),
            hits: self::hits($result),
            facets: self::facets($result),
        );
    }

    /** @return list<array{id: string, title: string, url: string, highlight: string, score: float, source: string, contentType: string, qualityScore: int, crawledAt: string, topics: list<string>, image: string}> */
    private static function hits(object $result): array
    {
        $source = self::arrayProperty($result, 'hits');
        $hits = [];
        foreach ($source as $hit) {
            if (!is_object($hit) || !is_a($hit, self::HIT)) {
                throw new ContentSearchBoundaryException('The optional search provider returned an invalid hit.');
            }
            $hits[] = [
                'id' => self::stringProperty($hit, 'id'),
                'title' => self::stringProperty($hit, 'title'),
                'url' => self::stringProperty($hit, 'url'),
                'highlight' => self::stringProperty($hit, 'highlight'),
                'score' => self::floatProperty($hit, 'score'),
                'source' => self::stringProperty($hit, 'sourceName'),
                'contentType' => self::stringProperty($hit, 'contentType'),
                'qualityScore' => self::intProperty($hit, 'qualityScore'),
                'crawledAt' => self::stringProperty($hit, 'crawledAt'),
                'topics' => self::stringListProperty($hit, 'topics'),
                'image' => self::stringProperty($hit, 'ogImage'),
            ];
        }

        return $hits;
    }

    /** @return list<array{name: string, buckets: list<array{key: string, count: int}>}> */
    private static function facets(object $result): array
    {
        $facets = [];
        foreach (self::arrayProperty($result, 'facets') as $facet) {
            if (!is_object($facet) || !is_a($facet, self::FACET)) {
                throw new ContentSearchBoundaryException('The optional search provider returned an invalid facet.');
            }
            $buckets = [];
            foreach (self::arrayProperty($facet, 'buckets') as $bucket) {
                if (!is_object($bucket) || !is_a($bucket, self::BUCKET)) {
                    throw new ContentSearchBoundaryException('The optional search provider returned an invalid facet bucket.');
                }
                $buckets[] = [
                    'key' => self::stringProperty($bucket, 'key'),
                    'count' => self::intProperty($bucket, 'count'),
                ];
            }
            $facets[] = ['name' => self::stringProperty($facet, 'name'), 'buckets' => $buckets];
        }

        return $facets;
    }

    private static function intProperty(object $object, string $property): int
    {
        $value = self::property($object, $property);
        if (!is_int($value)) {
            throw new ContentSearchBoundaryException('The optional search result contains an invalid integer.');
        }

        return $value;
    }

    private static function floatProperty(object $object, string $property): float
    {
        $value = self::property($object, $property);
        if (!is_float($value)) {
            throw new ContentSearchBoundaryException('The optional search result contains an invalid float.');
        }

        return $value;
    }

    private static function stringProperty(object $object, string $property): string
    {
        $value = self::property($object, $property);
        if (!is_string($value)) {
            throw new ContentSearchBoundaryException('The optional search result contains an invalid string.');
        }

        return $value;
    }

    /** @return array<mixed> */
    private static function arrayProperty(object $object, string $property): array
    {
        $value = self::property($object, $property);
        if (!is_array($value) || !array_is_list($value)) {
            throw new ContentSearchBoundaryException('The optional search result contains an invalid list.');
        }

        return $value;
    }

    /** @return list<string> */
    private static function stringListProperty(object $object, string $property): array
    {
        $values = self::arrayProperty($object, $property);
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new ContentSearchBoundaryException('The optional search result contains an invalid string list.');
            }
        }

        return $values;
    }

    private static function property(object $object, string $property): mixed
    {
        return get_object_vars($object)[$property] ?? null;
    }
}
