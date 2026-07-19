<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

/**
 * Definition-level canonical scalar representations shared by sealed entities
 * and repository array-write boundaries.
 *
 * @api
 */
final class FieldValueCanonicalizer
{
    private function __construct() {}

    /**
     * Canonicalize a value for a resolved field type.
     *
     * Nullable values and invalid/unrecognized inputs pass through so the
     * field's derived validation constraints retain responsibility for the
     * rejection and its normal property-path diagnostics.
     */
    public static function forType(string $type, mixed $value): mixed
    {
        if ($value === null || !\in_array(\strtolower($type), ['bool', 'boolean'], true)) {
            return $value;
        }

        return match (true) {
            \is_bool($value) => $value,
            $value === 1, $value === '1' => true,
            $value === 0, $value === '0' => false,
            default => $value,
        };
    }
}
