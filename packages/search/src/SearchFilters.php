<?php

declare(strict_types=1);

namespace Waaseyaa\Search;

final readonly class SearchFilters
{
    /**
     * @param string[] $topics
     * @param string[] $sourceNames
     */
    public function __construct(
        public array $topics = [],
        public string $contentType = '',
        public array $sourceNames = [],
        public int $minQuality = 0,
        // Reserved for future sorting support.
        public string $sortField = 'relevance',
        public string $sortOrder = 'desc',
    ) {}

    public function isEmpty(): bool
    {
        return $this->topics === []
            && $this->contentType === ''
            && $this->sourceNames === []
            && $this->minQuality === 0;
    }
}
