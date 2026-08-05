<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Resource;

final readonly class ContentResourceDescriptor
{
    public function __construct(
        public string $uri,
        public string $name,
        public string $title,
        public string $description = '',
        public string $mimeType = 'text/plain',
    ) {
        self::bounded($uri, 2_048);
        self::bounded($name, 256);
        self::bounded($title, 512);
        self::bounded($description, 2_048, true);
        self::bounded($mimeType, 128);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'uri' => $this->uri,
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description !== '' ? $this->description : null,
            'mimeType' => $this->mimeType,
        ], static fn(mixed $value): bool => $value !== null);
    }

    private static function bounded(string $value, int $max, bool $allowEmpty = false): void
    {
        if ((!$allowEmpty && $value === '')
            || !mb_check_encoding($value, 'UTF-8')
            || mb_strlen($value, 'UTF-8') > $max
            || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1
        ) {
            throw new \InvalidArgumentException('Content resource descriptor value is invalid.');
        }
    }
}
