<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Routing\Metadata;

/** Validates and detaches only the data allowed in route declarations. @internal */
final class ScalarRouteMetadata
{
    public static function copy(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 32) {
            throw new \InvalidArgumentException('Route metadata exceeds its nesting limit.');
        }
        if (is_array($value)) {
            // Recognize class-method callable syntax without is_callable(),
            // which could autoload execution classes during inspection.
            if (array_is_list($value) && count($value) === 2 && is_string($value[0]) && is_string($value[1])
                && preg_match('/^\\\\?[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*$/D', $value[0]) === 1
                && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $value[1]) === 1
            ) {
                throw new \InvalidArgumentException('Callable arrays are not route metadata.');
            }
            $copy = [];
            foreach ($value as $key => $entry) {
                self::copy($key, $depth + 1);
                $copy[$key] = self::copy($entry, $depth + 1);
            }
            return $copy;
        }
        if (is_string($value)) {
            if (preg_match('//u', $value) !== 1) {
                throw new \InvalidArgumentException('Route metadata must be valid UTF-8.');
            }
            return $value;
        }
        if ($value === null || is_bool($value) || is_int($value) || (is_float($value) && is_finite($value))) {
            return $value;
        }
        throw new \InvalidArgumentException('Route metadata must contain only finite scalar values and arrays.');
    }

    public static function identifier(string $value): void
    {
        if ($value === '' || preg_match('/^[a-zA-Z0-9_\\\\][a-zA-Z0-9_.:\\\\-]*$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid route metadata identifier.');
        }
    }

    /** Encode identity with an explicit list/map tag before canonical sorting. */
    public static function identityValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return ['list', array_map(self::identityValue(...), $value)];
        }
        $entries = [];
        foreach ($value as $key => $entry) {
            // Tagged keys cannot become PHP numeric keys after sorting.
            $entries[(is_int($key) ? 'int:' : 'string:') . $key] = self::identityValue($entry);
        }
        return ['map', $entries];
    }
}
