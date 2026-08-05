<?php

declare(strict_types=1);

namespace Waaseyaa\Search;

/** Bounded serializable search input; the acting principal is passed separately. @api */
final readonly class SearchRequest
{
    public const int MAX_QUERY_LENGTH = 256;
    public const int MAX_PAGE_SIZE = 100;

    public function __construct(
        public string $query,
        public SearchFilters $filters = new SearchFilters(),
        public int $page = 1,
        public int $pageSize = 20,
        public bool $includeFacets = true,
    ) {
        if (!mb_check_encoding($query, 'UTF-8') || mb_strlen($query, 'UTF-8') > self::MAX_QUERY_LENGTH || preg_match('/[\x00-\x1F\x7F]/u', $query) === 1) {
            throw new \InvalidArgumentException('Search query is malformed or exceeds its maximum length.');
        }
        if ($page < 1) {
            throw new \InvalidArgumentException('Search page must be at least 1.');
        }
        if ($pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            throw new \InvalidArgumentException('Search page size is outside the supported range.');
        }
        if ($page > 1 && $page - 1 > intdiv(PHP_INT_MAX, $pageSize)) {
            throw new \InvalidArgumentException('Search page offset exceeds the supported range.');
        }
    }
}
