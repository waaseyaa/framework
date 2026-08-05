<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Resource;

final readonly class ContentResourceContent
{
    public const int MAX_TEXT_BYTES = 262_144;

    public function __construct(
        public string $uri,
        public string $text,
        public string $mimeType = 'text/plain',
    ) {
        if ($uri === ''
            || $mimeType === ''
            || !mb_check_encoding($text, 'UTF-8')
            || strlen($text) > self::MAX_TEXT_BYTES
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $text) === 1
        ) {
            throw new \InvalidArgumentException('Content resource payload is invalid or oversized.');
        }
    }

    /** @return array{uri: string, mimeType: string, text: string} */
    public function toArray(): array
    {
        return ['uri' => $this->uri, 'mimeType' => $this->mimeType, 'text' => $this->text];
    }
}
