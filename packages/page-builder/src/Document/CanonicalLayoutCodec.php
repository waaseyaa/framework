<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Document;

use Waaseyaa\PageBuilder\Document\Exception\InvalidLayoutDocumentException;

/** @api */
final class CanonicalLayoutCodec
{
    public function encode(LayoutDocument $document): string
    {
        return json_encode(
            $this->canonicalize($document->toArray()),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    public function decode(string $encoded): LayoutDocument
    {
        try {
            $payload = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidLayoutDocumentException('Layout document is not valid JSON', previous: $exception);
        }

        if (!is_array($payload)) {
            throw new InvalidLayoutDocumentException('Layout document JSON root must be an object');
        }

        return LayoutDocument::fromArray($payload);
    }

    private function canonicalize(mixed $value, ?string $field = null): mixed
    {
        if ('config' === $field && [] === $value) {
            return new \stdClass();
        }
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item, (string) $key);
        }

        return $value;
    }
}
