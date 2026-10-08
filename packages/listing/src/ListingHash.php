<?php

declare(strict_types=1);

namespace Waaseyaa\Listing;

/** Internal cache identity policy: preserve float identity and PHP numeric key order. */
final class ListingHash
{
    public static function of(mixed $value): string
    {
        $json = json_encode(self::canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return substr(hash('sha256', $json), 0, 16);
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonicalize(...), $value);
    }
}
