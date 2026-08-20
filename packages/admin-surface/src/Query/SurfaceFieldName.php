<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Query;

/**
 * The one grammar for a host-declared list field name.
 *
 * `ListMetadata` decides which fields a list may declare, and
 * {@see \Waaseyaa\AdminSurface\AdminDestinationPaths::filteredList()} encodes
 * query keys for those same fields. Two copies of the rule would drift, and a
 * destination generator that accepted names the metadata rejects would emit
 * links the list can never honour — so both read the grammar from here.
 *
 * The pattern is anchored with `\A`/`\z` rather than `^`/`$`: PCRE's `$` also
 * matches before a trailing newline, which would admit `"state\n"` as a field
 * name and let a control character reach a query key.
 *
 * @internal
 */
final class SurfaceFieldName
{
    /** Canonical field grammar: an identifier, optionally dotted. */
    public const PATTERN = '/\A[A-Za-z_][A-Za-z0-9_.]*\z/';

    private function __construct() {}

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * The name when it is canonical, otherwise null.
     */
    public static function normalize(mixed $value): ?string
    {
        return self::isValid($value) ? $value : null;
    }
}
