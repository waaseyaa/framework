<?php

declare(strict_types=1);

namespace Aurora\Cache\Listener;

use Aurora\Cache\CacheTagsInvalidatorInterface;
use Aurora\Config\Event\ConfigEvent;

/**
 * Listens for config save/delete events and invalidates related cache tags.
 *
 * Invalidates a specific config tag (config:{name}) and the general
 * config list tag (config) to ensure both individual lookups and
 * listing queries are properly cache-busted.
 */
final class ConfigCacheInvalidator
{
    public function __construct(
        private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    ) {}

    /**
     * Handles config post-save events.
     */
    public function onPostSave(ConfigEvent $event): void
    {
        $this->invalidateConfig($event);
    }

    /**
     * Handles config post-delete events.
     */
    public function onPostDelete(ConfigEvent $event): void
    {
        $this->invalidateConfig($event);
    }

    private function invalidateConfig(ConfigEvent $event): void
    {
        $configName = $event->getConfigName();

        $this->cacheTagsInvalidator->invalidateTags([
            'config',
            "config:{$configName}",
        ]);
    }
}
