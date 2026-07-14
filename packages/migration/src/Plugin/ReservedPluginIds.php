<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Plugin;

/**
 * Canonical plugin ids reserved for the framework's built-in process plugins.
 *
 * Third-party providers MUST NOT register a plugin whose id matches one of
 * these constants — the {@see \Waaseyaa\Migration\Discovery\PluginRegistry}
 * raises {@see \Waaseyaa\Migration\Exception\MigrationPluginCollisionException}
 * if they do.
 *
 * The concrete implementations of these ids land in WP03; this WP only owns
 * the reservation surface so collision checks work from day one.
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
