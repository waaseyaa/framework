<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Resource;

final readonly class ContentResourceTemplate
{
    public function __construct(
        public string $uriTemplate,
        public string $name,
        public string $title,
        public string $description,
        public string $mimeType = 'text/plain',
    ) {
        foreach ([$uriTemplate, $name, $title, $description, $mimeType] as $value) {
            if ($value === ''
                || !mb_check_encoding($value, 'UTF-8')
                || mb_strlen($value, 'UTF-8') > 2_048
                || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1
            ) {
                throw new \InvalidArgumentException('Content resource template value is invalid.');
            }
        }
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'uriTemplate' => $this->uriTemplate,
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description,
            'mimeType' => $this->mimeType,
        ];
    }
}
