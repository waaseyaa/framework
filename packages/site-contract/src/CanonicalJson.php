<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract;

final class CanonicalJson
{
    /** @param array<mixed> $value */
    public static function encode(array $value): string
    {
        return json_encode(
            self::normalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /** @param array<mixed> $value
     *  @return array<mixed>
     */
    private static function normalize(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(
                static fn(mixed $item): mixed => self::normalizeValue($item),
                $value,
            );
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::normalizeValue($item);
        }

        return $value;
    }

    private static function normalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return self::normalize($value);
        }

        if ($value instanceof \stdClass) {
            return self::normalizeObject($value);
        }

        return $value;
    }

    private static function normalizeObject(\stdClass $object): \stdClass
    {
        $properties = (array) $object;
        ksort($properties, SORT_STRING);
        $normalized = new \stdClass();
        foreach ($properties as $key => $item) {
            $normalized->{$key} = self::normalizeValue($item);
        }

        return $normalized;
    }
}
