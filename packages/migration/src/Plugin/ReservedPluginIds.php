<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Plugin;

/**
 * Canonical plugin ids reserved for the framework's built-in process plugins.
 *
 * Concrete built-in processors use these ids for stable manifest references.
 *
 * @api
 */
final class ReservedPluginIds
{
    public const string PASS_THROUGH = 'pass_through';
    public const string HTML_SANITIZE = 'html_sanitize';
    public const string LOOKUP = 'lookup';
    public const string CONCAT = 'concat';
    public const string TYPE_COERCE = 'type_coerce';
    public const string DEFAULT_VALUE = 'default_value';
    public const string PARTITIONED_LOOKUP = 'partitioned_lookup';

    /**
     * Every reserved id, in source order.
     *
     * @var list<string>
     */
    public const array ALL = [
        self::PASS_THROUGH,
        self::HTML_SANITIZE,
        self::LOOKUP,
        self::CONCAT,
        self::TYPE_COERCE,
        self::DEFAULT_VALUE,
        self::PARTITIONED_LOOKUP,
    ];

    /**
     * Private constructor — this is a constants-only holder, not an
     * instantiable value object.
     */
    private function __construct() {}

    /**
     * True when $id is one of the framework-reserved plugin ids.
     */
    public static function isReserved(string $id): bool
    {
        return in_array($id, self::ALL, true);
    }
}
