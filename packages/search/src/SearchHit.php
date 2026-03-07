<?php

declare(strict_types=1);

namespace Waaseyaa\Search;

final readonly class SearchHit
{
    /**
     * @param string[] $topics
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $url,
        public string $sourceName,
        public string $crawledAt,
        public int $qualityScore,
        public string $contentType,
        public array $topics,
        public float $score,
        public string $ogImage = '',
        public string $highlight = '',
    ) {}
}
