<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SSR\Http\CanonicalPublicOrigin;

#[CoversClass(CanonicalPublicOrigin::class)]
final class CanonicalPublicOriginTest extends TestCase
{
    #[Test]
    public function accepts_a_canonical_https_origin_and_optional_base_path(): void
    {
        $apex = CanonicalPublicOrigin::tryFrom('https://example.com');
        $prefixed = CanonicalPublicOrigin::tryFrom('https://example.com/blog/');

        self::assertNotNull($apex);
        self::assertNotNull($prefixed);
        self::assertSame('https://example.com/sitemap.xml', $apex->sitemapUrl());
        self::assertSame('https://example.com/blog/sitemap.xml', $prefixed->sitemapUrl());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidOrigins(): iterable
    {
        yield 'relative path' => ['/sitemap.xml'];
        yield 'insecure origin' => ['http://example.com'];
        yield 'scheme relative' => ['//example.com'];
        yield 'query' => ['https://example.com/base?x=1'];
        yield 'fragment' => ['https://example.com/base#x'];
        yield 'credentials' => ['https://user:password@example.com'];
        yield 'dot segment' => ['https://example.com/base/../admin'];
        yield 'control character' => ["https://example.com/base\nforged"];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'empty' => ['   '];
    }

    #[Test]
    #[DataProvider('invalidOrigins')]
    public function rejects_invalid_or_non_absolute_origins(string $invalid): void
    {
        self::assertNull(CanonicalPublicOrigin::tryFrom($invalid));
    }

    #[Test]
    public function trusted_config_prefers_api_catalog_base_url(): void
    {
        $this->withAppUrl('https://env.example', function (): void {
            $origin = CanonicalPublicOrigin::tryFromTrustedConfig([
                'api_catalog' => ['base_url' => 'https://example.com/cms/'],
                'app' => ['url' => 'https://app.example'],
            ]);
            self::assertNotNull($origin);
            self::assertSame('https://example.com/cms/sitemap.xml', $origin->sitemapUrl());
        });
    }

    #[Test]
    public function trusted_config_falls_back_to_app_url_when_catalog_base_is_absent(): void
    {
        $this->withAppUrl('https://env.example', function (): void {
            $origin = CanonicalPublicOrigin::tryFromTrustedConfig([]);
            self::assertNotNull($origin);
            self::assertSame('https://env.example/sitemap.xml', $origin->sitemapUrl());
        });
    }

    #[Test]
    public function trusted_config_falls_back_to_app_url_config_when_env_is_absent(): void
    {
        $this->withAppUrl(null, function (): void {
            $origin = CanonicalPublicOrigin::tryFromTrustedConfig([
                'app' => ['url' => 'https://app.example/'],
            ]);
            self::assertNotNull($origin);
            self::assertSame('https://app.example/sitemap.xml', $origin->sitemapUrl());
        });
    }

    #[Test]
    public function trusted_config_fails_closed_on_invalid_catalog_base_url_without_falling_back(): void
    {
        $this->withAppUrl('https://env.example', function (): void {
            self::assertNull(CanonicalPublicOrigin::tryFromTrustedConfig([
                'api_catalog' => ['base_url' => 'http://evil.example'],
                'app' => ['url' => 'https://app.example'],
            ]));
        });
    }

    #[Test]
    public function trusted_config_fails_closed_on_non_string_catalog_base_url(): void
    {
        $this->withAppUrl('https://env.example', function (): void {
            self::assertNull(CanonicalPublicOrigin::tryFromTrustedConfig([
                'api_catalog' => ['base_url' => true],
            ]));
        });
    }

    #[Test]
    public function trusted_config_fails_closed_when_no_absolute_origin_is_configured(): void
    {
        $this->withAppUrl(null, function (): void {
            self::assertNull(CanonicalPublicOrigin::tryFromTrustedConfig([
                'app' => ['url' => 'http://localhost'],
            ]));
        });
    }

    #[Test]
    public function request_like_host_values_are_not_a_trusted_config_source(): void
    {
        $this->withAppUrl(null, function (): void {
            self::assertNull(CanonicalPublicOrigin::tryFromTrustedConfig([
                'HTTP_HOST' => 'evil.example',
                'HTTP_X_FORWARDED_HOST' => 'attacker.example',
                'host' => 'evil.example',
            ]));
        });
    }

    private function withAppUrl(?string $value, callable $callback): void
    {
        $previous = getenv('APP_URL');
        $previousEnv = $_ENV['APP_URL'] ?? null;
        $previousServer = $_SERVER['APP_URL'] ?? null;
        if ($value === null) {
            putenv('APP_URL');
            unset($_ENV['APP_URL'], $_SERVER['APP_URL']);
        } else {
            putenv('APP_URL=' . $value);
            $_ENV['APP_URL'] = $_SERVER['APP_URL'] = $value;
        }

        try {
            $callback();
        } finally {
            if ($previous === false) {
                putenv('APP_URL');
            } else {
                putenv('APP_URL=' . $previous);
            }
            if ($previousEnv === null) {
                unset($_ENV['APP_URL']);
            } else {
                $_ENV['APP_URL'] = $previousEnv;
            }
            if ($previousServer === null) {
                unset($_SERVER['APP_URL']);
            } else {
                $_SERVER['APP_URL'] = $previousServer;
            }
        }
    }
}
