<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp;

/**
 * Protocol revisions this server can negotiate and validate on HTTP requests.
 *
 * @api
 */
final class McpProtocol
{
    public const string CURRENT = '2026-07-28';
    public const string LATEST = self::CURRENT;
    public const string LEGACY_HTTP_DEFAULT = '2025-03-26';
    public const string VERSION_META_KEY = 'io.modelcontextprotocol/protocolVersion';

    /** @var non-empty-list<string> */
    public const array SUPPORTED = [
        self::CURRENT,
        '2025-11-25',
        '2025-06-18',
        self::LEGACY_HTTP_DEFAULT,
    ];

    /** @var non-empty-list<string> */
    public const array LEGACY_SUPPORTED = [
        '2025-11-25',
        '2025-06-18',
        self::LEGACY_HTTP_DEFAULT,
    ];

    public static function isSupported(string $version): bool
    {
        return \in_array($version, self::SUPPORTED, true);
    }

    public static function negotiate(string $requested): string
    {
        return self::isLegacySupported($requested) ? $requested : self::LEGACY_SUPPORTED[0];
    }

    public static function isLegacySupported(string $version): bool
    {
        return \in_array($version, self::LEGACY_SUPPORTED, true);
    }
}
