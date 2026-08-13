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
                static fn(mixed $item): mixed => is_array($item) ? self::normalize($item) : $item,
                $value,
            );
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::normalize($item);
            }
        }

        return $value;
    }
}
