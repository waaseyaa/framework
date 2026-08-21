<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Http;

/**
 * Trusted HTTPS origin used to build crawler-facing absolute URLs.
 *
 * The origin is taken only from application configuration or `APP_URL`.
 * Request `Host`, `Forwarded`, and `X-Forwarded-Host` headers are never
 * consulted. Invalid or non-absolute values fail closed rather than
 * producing a malformed URL.
 *
 * @api
 */
final readonly class CanonicalPublicOrigin
{
    private function __construct(private string $origin) {}

    public static function tryFrom(string $origin): ?self
    {
        $canonical = self::canonicalize($origin);
        if ($canonical === null) {
            return null;
        }

        return new self($canonical);
    }

    /**
     * Resolve the first present trusted candidate. An explicit invalid value
     * fails closed and does not fall through to a later source.
     *
     * @param array<string, mixed> $config
     */
    public static function tryFromTrustedConfig(array $config): ?self
    {
        $catalog = $config['api_catalog'] ?? null;
        if (is_array($catalog) && array_key_exists('base_url', $catalog)) {
            [$present, $origin] = self::inspectCandidate($catalog['base_url']);
            if ($present) {
                return $origin;
            }
        }

        $environmentUrl = getenv('APP_URL');
        if ($environmentUrl !== false) {
            [$present, $origin] = self::inspectCandidate($environmentUrl);
            if ($present) {
                return $origin;
            }
        }

        $app = $config['app'] ?? null;
        if (is_array($app) && array_key_exists('url', $app)) {
            [$present, $origin] = self::inspectCandidate($app['url']);
            if ($present) {
                return $origin;
            }
        }

        return null;
    }

    public function sitemapUrl(): string
    {
        return $this->origin . '/sitemap.xml';
    }

    /**
     * @return array{0: bool, 1: ?self}
     */
    private static function inspectCandidate(mixed $value): array
    {
        if (!is_string($value)) {
            return [true, null];
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return [false, null];
        }

        return [true, self::tryFrom($trimmed)];
    }

    private static function canonicalize(string $origin): ?string
    {
        $origin = rtrim(trim($origin), '/');
        $parts = parse_url($origin);
        if (
            $origin === ''
            || preg_match('/[\x00-\x20\x7f]/', $origin) === 1
            || $parts === false
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || !is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || preg_match('#(?:^|/)\.\.?($|/)#', $parts['path'] ?? '') === 1
        ) {
            return null;
        }

        return $origin;
    }
}
