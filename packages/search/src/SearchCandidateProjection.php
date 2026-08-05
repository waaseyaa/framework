<?php

declare(strict_types=1);

namespace Waaseyaa\Search;

/**
 * Principal-safe, canonical content used to derive every observable search value.
 *
 * This object is scoped to the principal that resolved it. Do not share or
 * cache it across principals or claims generations.
 *
 * @api
 */
final readonly class SearchCandidateProjection
{
    public const int MAX_TITLE_LENGTH = 512;
    public const int MAX_BODY_LENGTH = 65_536;
    public const int MAX_METADATA_LENGTH = 2_048;
    public const int MAX_TOPICS = 64;
    public const int MAX_TOPIC_LENGTH = 128;

    /** @param list<string> $topics */
    public function __construct(
        public string $id,
        public string $entityType,
        public string $title,
        public string $body,
        public string $url = '',
        public string $sourceName = '',
        public string $crawledAt = '',
        public int $qualityScore = 0,
        public string $contentType = '',
        public array $topics = [],
        public string $ogImage = '',
    ) {
        if ($id === '') {
            throw new \InvalidArgumentException('Search projection id cannot be empty.');
        }
        self::assertLength($entityType, self::MAX_METADATA_LENGTH, 'entity type');
        self::assertLength($title, self::MAX_TITLE_LENGTH, 'title');
        self::assertLength($body, self::MAX_BODY_LENGTH, 'body');
        foreach ([$url, $sourceName, $crawledAt, $contentType, $ogImage] as $value) {
            self::assertLength($value, self::MAX_METADATA_LENGTH, 'metadata value');
        }
        if (count($topics) > self::MAX_TOPICS) {
            throw new \InvalidArgumentException('Search projection has too many topics.');
        }
        foreach ($topics as $topic) {
            // @phpstan-ignore function.alreadyNarrowedType (runtime API boundary)
            if (!is_string($topic)) {
                throw new \InvalidArgumentException('Search projection topics must be strings.');
            }
            self::assertLength($topic, self::MAX_TOPIC_LENGTH, 'topic');
        }
    }

    private static function assertLength(string $value, int $maximum, string $label): void
    {
        if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $maximum) {
            throw new \InvalidArgumentException(sprintf('Search projection %s exceeds its maximum length.', $label));
        }
    }
}
