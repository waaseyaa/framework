<?php

declare(strict_types=1);

namespace Waaseyaa\Routing\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Routing\CacheConfigResolver;
use Waaseyaa\User\AnonymousUser;
use Waaseyaa\User\DevAdminAccount;

#[CoversClass(CacheConfigResolver::class)]
final class CacheConfigResolverTest extends TestCase
{
    #[Test]
    public function resolves_render_cache_max_age_from_config(): void
    {
        $resolver = new CacheConfigResolver(['ssr' => ['cache_max_age' => 600]]);

        $this->assertSame(600, $resolver->resolveRenderCacheMaxAge());
    }

    #[Test]
    public function resolves_render_cache_max_age_defaults_to_300(): void
    {
        $resolver = new CacheConfigResolver([]);

        $this->assertSame(300, $resolver->resolveRenderCacheMaxAge());
    }

    #[Test]
    public function resolves_shared_max_age_from_config(): void
    {
        $resolver = new CacheConfigResolver(['ssr' => ['cache_shared_max_age' => 900]]);

        $this->assertSame(900, $resolver->resolveRenderSharedCacheMaxAge(300));
    }

    #[Test]
    public function shared_max_age_defaults_to_provided_default(): void
    {
        $resolver = new CacheConfigResolver([]);

        $this->assertSame(120, $resolver->resolveRenderSharedCacheMaxAge(120));
    }

    #[Test]
    public function resolves_stale_while_revalidate_from_config(): void
    {
        $resolver = new CacheConfigResolver(['ssr' => ['cache_stale_while_revalidate' => 180]]);

        $this->assertSame(180, $resolver->resolveRenderStaleWhileRevalidate());
    }

    #[Test]
    public function stale_while_revalidate_defaults_to_60(): void
    {
        $resolver = new CacheConfigResolver([]);

        $this->assertSame(60, $resolver->resolveRenderStaleWhileRevalidate());
    }

    #[Test]
    public function resolves_stale_if_error_from_config(): void
    {
        $resolver = new CacheConfigResolver(['ssr' => ['cache_stale_if_error' => 3600]]);

        $this->assertSame(3600, $resolver->resolveRenderStaleIfError());
    }

    #[Test]
    public function stale_if_error_defaults_to_600(): void
    {
        $resolver = new CacheConfigResolver([]);

        $this->assertSame(600, $resolver->resolveRenderStaleIfError());
    }

    #[Test]
    public function cache_control_header_is_private_for_authenticated_users(): void
    {
        $resolver = new CacheConfigResolver([]);

        $this->assertSame('private, no-store', $resolver->cacheControlHeaderForRender(new DevAdminAccount(), 120));
    }

    #[Test]
    public function cache_control_header_is_public_for_anonymous_users(): void
    {
        $resolver = new CacheConfigResolver([]);

        $this->assertSame(
            'public, max-age=120, s-maxage=120, stale-while-revalidate=60, stale-if-error=600',
            $resolver->cacheControlHeaderForRender(new AnonymousUser(), 120),
        );
    }

    #[Test]
    public function cache_control_header_honors_shared_and_stale_config(): void
    {
        $resolver = new CacheConfigResolver([
            'ssr' => [
                'cache_shared_max_age' => 900,
                'cache_stale_while_revalidate' => 180,
                'cache_stale_if_error' => 3600,
            ],
        ]);

        $this->assertSame(
            'public, max-age=300, s-maxage=900, stale-while-revalidate=180, stale-if-error=3600',
            $resolver->cacheControlHeaderForRender(new AnonymousUser(), 300),
        );
    }

    #[Test]
    public function negative_max_age_is_clamped_to_zero(): void
    {
        $resolver = new CacheConfigResolver(['ssr' => ['cache_max_age' => -10]]);

        $this->assertSame(0, $resolver->resolveRenderCacheMaxAge());
    }
}
