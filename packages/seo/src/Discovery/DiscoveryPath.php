<?php

declare(strict_types=1);

namespace Waaseyaa\Seo\Discovery;

/**
 * Strict validation for application-supplied crawler-surface paths.
 *
 * Every path that crosses an application boundary on its way into `sitemap.xml`
 * or `llms.txt` passes through here. The rules exist so a policy return value
 * can never become an off-site URL, forge authority, escape the origin, or break
 * the containing document:
 *
 *  - root-relative only: must begin with `/`, and never `//` (protocol-relative),
 *    so the framework-owned origin is always the authority;
 *  - no scheme, host, user, or password component (credentials are structurally
 *    unreachable once the two rules above hold, and this asserts it directly);
 *  - no fragment: `#` would truncate a sitemap `loc` and break an llms.txt link;
 *  - no backslash: several user agents normalise `\` to `/`, which would turn
 *    `/\evil.example` into a protocol-relative URL;
 *  - no control characters or spaces, which would break XML/Markdown structure;
 *  - no `.` or `..` traversal segment;
 *  - well-formed percent-encoding, so a truncated `%` cannot corrupt the emitted
 *    document or be re-interpreted downstream.
 *
 * @internal
 */
final class DiscoveryPath
{
    /** RFC 3986 query characters (pchar plus `/` and `?`). */
    private const string QUERY_CHARACTERS = '#^[A-Za-z0-9._~!$&\'()*+,;=:@/?%-]+$#D';

    /** Accept a root-relative path with no query string (sitemap `loc` shape). */
    public static function acceptsPath(string $value): bool
    {
        return !str_contains($value, '?') && self::pathIsValid($value);
    }

    /**
     * Accept a root-relative path, optionally followed by a query string
     * (llms.txt Markdown-representation shape, e.g. `/node/97?format=md`).
     */
    public static function acceptsPathWithQuery(string $value): bool
    {
        $separator = strpos($value, '?');
        if ($separator === false) {
            return self::pathIsValid($value);
        }

        return self::pathIsValid(substr($value, 0, $separator))
            && self::queryIsValid(substr($value, $separator + 1));
    }

    private static function pathIsValid(string $path): bool
    {
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')) {
            return false;
        }

        if (
            preg_match('/[\x00-\x20\x7F]/', $path) === 1
            || str_contains($path, '\\')
            || str_contains($path, '#')
            || preg_match('#(?:^|/)\.\.?(?:/|$)#', $path) === 1
            || !self::percentEncodingIsWellFormed($path)
        ) {
            return false;
        }

        $parts = parse_url($path);

        // A value that survived the rules above cannot carry an authority, but
        // assert it rather than infer it: this is the property the whole
        // contract rests on.
        return is_array($parts)
            && !isset($parts['scheme'])
            && !isset($parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['fragment'])
            && ($parts['path'] ?? null) === $path;
    }

    private static function queryIsValid(string $query): bool
    {
        // `/path?` is a malformed representation URL, not an empty-query one.
        return $query !== ''
            && preg_match('/[\x00-\x20\x7F]/', $query) !== 1
            && !str_contains($query, '#')
            && !str_contains($query, '\\')
            && self::percentEncodingIsWellFormed($query)
            && preg_match(self::QUERY_CHARACTERS, $query) === 1;
    }

    private static function percentEncodingIsWellFormed(string $value): bool
    {
        return preg_match('/%(?![0-9A-Fa-f]{2})/', $value) !== 1;
    }
}
