<?php

declare(strict_types=1);

namespace Waaseyaa\Api\ContentSearch;

final readonly class ContentSearchQuery
{
    /**
     * @param list<string> $topics
     * @param list<string> $sourceNames
     */
    public function __construct(
        public string $query,
        public array $topics = [],
        public string $contentType = '',
        public array $sourceNames = [],
        public int $minQuality = 0,
        public string $sortField = 'relevance',
        public string $sortOrder = 'desc',
        public int $page = 1,
        public int $pageSize = 20,
        public bool $includeFacets = true,
    ) {
        if (
            $query === ''
            || !mb_check_encoding($query, 'UTF-8')
            || mb_strlen($query, 'UTF-8') > 256
            || preg_match('/[\x00-\x1F\x7F]/u', $query) === 1
        ) {
            throw new \InvalidArgumentException('Search query is malformed or exceeds its maximum length.');
        }
        self::assertList($topics);
        self::assertList($sourceNames);
        self::assertString($contentType, 128);
        if ($minQuality < 0 || $minQuality > 100) {
            throw new \InvalidArgumentException('Search minimum quality is outside the supported range.');
        }
        if (!in_array($sortField, ['relevance', 'created_at', 'quality_score', 'entity_type', 'content_type'], true)) {
            throw new \InvalidArgumentException('Unsupported search sort field.');
        }
        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            throw new \InvalidArgumentException('Unsupported search sort order.');
        }
        if ($page < 1 || $page > 200 || $pageSize < 1 || $pageSize > 100) {
            throw new \InvalidArgumentException('Search pagination is outside the supported range.');
        }
    }

    /** @param array<mixed> $values */
    private static function assertList(array $values): void
    {
        if (!array_is_list($values) || count($values) > 20) {
            throw new \InvalidArgumentException('Search filter list is malformed.');
        }
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException('Search filter list is malformed.');
            }
            self::assertString($value, 128);
        }
    }

    private static function assertString(string $value, int $maximum): void
    {
        if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $maximum) {
            throw new \InvalidArgumentException('Search string is malformed or too long.');
        }
    }
}
