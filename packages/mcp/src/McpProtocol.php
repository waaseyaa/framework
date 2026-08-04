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
    public const string LATEST = '2025-11-25';
    public const string LEGACY_HTTP_DEFAULT = '2025-03-26';

    /** @var non-empty-list<string> */
    public const array SUPPORTED = [
        self::LATEST,
        '2025-06-18',
        self::LEGACY_HTTP_DEFAULT,
    ];

    public static function isSupported(string $version): bool
    {
        return \in_array($version, self::SUPPORTED, true);
    }

    public static function negotiate(string $requested): string
    {
        return self::isSupported($requested) ? $requested : self::LATEST;
    }
}
