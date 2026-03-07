<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Waaseyaa\Search\SearchFilters;
use Waaseyaa\Search\SearchProviderInterface;
use Waaseyaa\Search\SearchRequest;
use Waaseyaa\Search\SearchResult;

final class SearchTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly SearchProviderInterface $provider,
    ) {}

    /** @return TwigFunction[] */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('search', $this->search(...)),
            new TwigFunction('query_param', $this->queryParam(...)),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function search(string $query, array $filters = [], int $page = 1, int $pageSize = 20): SearchResult
    {
        if ($query === '') {
            return SearchResult::empty();
        }

        $searchFilters = new SearchFilters(
            topics: isset($filters['topic']) && $filters['topic'] !== ''
                ? [(string) $filters['topic']]
                : [],
            contentType: (string) ($filters['content_type'] ?? ''),
            sourceNames: isset($filters['source']) && $filters['source'] !== ''
                ? [(string) $filters['source']]
                : [],
            minQuality: (int) ($filters['min_quality'] ?? 0),
        );

        return $this->provider->search(new SearchRequest(
            query: $query,
            filters: $searchFilters,
            page: max(1, $page),
            pageSize: min(100, max(1, $pageSize)),
        ));
    }

    public function queryParam(string $name, string $default = ''): string
    {
        $value = $_GET[$name] ?? $default;
        return is_string($value) ? $value : $default;
    }
}
