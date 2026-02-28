<?php

declare(strict_types=1);

namespace Aurora\Cache\Listener;

use Aurora\Cache\CacheTagsInvalidatorInterface;

/**
 * Invalidates translation-related caches.
 *
 * Provides methods to invalidate caches when translation strings or
 * language configuration changes. Can be wired to events or invoked
 * directly.
 */
final class TranslationCacheInvalidator
{
    public function __construct(
        private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    ) {}

    /**
     * Invalidate all translation caches.
     */
    public function invalidateAll(): void
    {
        $this->cacheTagsInvalidator->invalidateTags(['translations']);
    }

    /**
     * Invalidate translation caches for a specific language.
     */
    public function invalidateLanguage(string $langcode): void
    {
        $this->cacheTagsInvalidator->invalidateTags([
            'translations',
            "translations:{$langcode}",
        ]);
    }

    /**
     * Invalidate translation caches for a specific translation context/group.
     */
    public function invalidateContext(string $context): void
    {
        $this->cacheTagsInvalidator->invalidateTags([
            'translations',
            "translations:context:{$context}",
        ]);
    }
}
