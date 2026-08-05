<?php

declare(strict_types=1);

namespace Waaseyaa\Search;

/** Bounded filters evaluated only against principal-safe metadata. @api */
final readonly class SearchFilters
{
    private const int MAX_VALUES = 20;
    private const int MAX_VALUE_LENGTH = 128;

    /**
     * @param string[] $topics
     * @param string[] $sourceNames
     */
    public function __construct(
        public array $topics = [],
        public string $contentType = '',
        public array $sourceNames = [],
        public int $minQuality = 0,
        // Applied only to the principal-safe bounded candidate window.
        public string $sortField = 'relevance',
        public string $sortOrder = 'desc',
    ) {
        self::assertValues($topics, 'topics');
        self::assertValues($sourceNames, 'source names');
        if (!mb_check_encoding($contentType, 'UTF-8') || mb_strlen($contentType, 'UTF-8') > self::MAX_VALUE_LENGTH) {
            throw new \InvalidArgumentException('Search content type exceeds its maximum length.');
        }
        if ($minQuality < 0 || $minQuality > 100) {
            throw new \InvalidArgumentException('Search minimum quality must be between 0 and 100.');
        }
        if (!mb_check_encoding($sortField, 'UTF-8') || !in_array($sortField, ['relevance', 'created_at', 'quality_score', 'entity_type', 'content_type'], true)) {
            throw new \InvalidArgumentException('Unsupported search sort field.');
        }
        if (!mb_check_encoding($sortOrder, 'UTF-8') || !in_array(strtolower($sortOrder), ['asc', 'desc'], true)) {
            throw new \InvalidArgumentException('Unsupported search sort order.');
        }
    }

    public function isEmpty(): bool
    {
        return $this->topics === []
            && $this->contentType === ''
            && $this->sourceNames === []
            && $this->minQuality === 0;
    }

    /** @param array<mixed> $values */
    private static function assertValues(array $values, string $label): void
    {
        if (!array_is_list($values) || count($values) > self::MAX_VALUES) {
            throw new \InvalidArgumentException(sprintf('Search %s are malformed or exceed the maximum count.', $label));
        }
        foreach ($values as $value) {
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > self::MAX_VALUE_LENGTH) {
                throw new \InvalidArgumentException(sprintf('Search %s contain an invalid value.', $label));
            }
        }
    }
}
