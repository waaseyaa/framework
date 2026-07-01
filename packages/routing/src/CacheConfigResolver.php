<?php

declare(strict_types=1);

namespace Waaseyaa\Routing;

use Waaseyaa\Access\AccountInterface;

/**
 * Resolves cache-related configuration values from the application config.
 *
 * Centralizes the logic for reading SSR cache max-age, shared max-age,
 * stale-while-revalidate, and stale-if-error directives from the config
 * array, with sensible defaults when values are absent.
 */
final class CacheConfigResolver
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config = [],
    ) {}

    public function resolveRenderCacheMaxAge(): int
    {
        $ssrConfig = $this->config['ssr'] ?? null;
        if (is_array($ssrConfig) && isset($ssrConfig['cache_max_age']) && is_numeric($ssrConfig['cache_max_age'])) {
            return max(0, (int) $ssrConfig['cache_max_age']);
        }

        return 300;
    }

    public function resolveRenderSharedCacheMaxAge(int $defaultMaxAge): int
    {
        $ssrConfig = $this->config['ssr'] ?? null;
        if (is_array($ssrConfig) && isset($ssrConfig['cache_shared_max_age']) && is_numeric($ssrConfig['cache_shared_max_age'])) {
            return max(0, (int) $ssrConfig['cache_shared_max_age']);
        }

        return max(0, $defaultMaxAge);
    }

    public function resolveRenderStaleWhileRevalidate(): int
    {
        $ssrConfig = $this->config['ssr'] ?? null;
        if (is_array($ssrConfig) && isset($ssrConfig['cache_stale_while_revalidate']) && is_numeric($ssrConfig['cache_stale_while_revalidate'])) {
            return max(0, (int) $ssrConfig['cache_stale_while_revalidate']);
        }

        return 60;
    }

    public function resolveRenderStaleIfError(): int
    {
        $ssrConfig = $this->config['ssr'] ?? null;
        if (is_array($ssrConfig) && isset($ssrConfig['cache_stale_if_error']) && is_numeric($ssrConfig['cache_stale_if_error'])) {
            return max(0, (int) $ssrConfig['cache_stale_if_error']);
        }

        return 600;
    }

    public function cacheControlHeaderForRender(AccountInterface $account, int $maxAge): string
    {
        if ($account->isAuthenticated()) {
            return 'private, no-store';
        }

        $safeMaxAge = max(0, $maxAge);
        $sharedMaxAge = $this->resolveRenderSharedCacheMaxAge($safeMaxAge);
        $staleWhileRevalidate = $this->resolveRenderStaleWhileRevalidate();
        $staleIfError = $this->resolveRenderStaleIfError();

        return sprintf(
            'public, max-age=%d, s-maxage=%d, stale-while-revalidate=%d, stale-if-error=%d',
            $safeMaxAge,
            $sharedMaxAge,
            $staleWhileRevalidate,
            $staleIfError,
        );
    }
}
