<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\AI\Vector\EmbeddingProviderFactory;
use Waaseyaa\AI\Vector\EntityEmbeddingCleanupListener;
use Waaseyaa\AI\Vector\EntityEmbeddingListener;
use Waaseyaa\AI\Vector\SqliteEmbeddingStorage;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Cache\CacheBackendInterface;
use Waaseyaa\Cache\TagAwareCacheInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\SSR\RenderCache;

/**
 * Registers all event listeners used by the HTTP kernel.
 *
 * Handles broadcast, render cache, discovery cache, MCP read cache,
 * and embedding lifecycle listeners.
 */
final class EventListenerRegistrar
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function registerBroadcastListeners(BroadcastStorage $broadcastStorage): void
    {
        $logger = $this->logger;
        $this->dispatcher->addListener('waaseyaa.entity.post_save', static function (object $event) use ($broadcastStorage, $logger): void {
            try {
                $entity = $event->entity;
                $broadcastStorage->push(
                    'admin',
                    'entity.saved',
                    ['entityType' => $entity->getEntityTypeId(), 'id' => (string) ($entity->uuid() ?: $entity->id())],
                );
            } catch (\Throwable $e) {
                $logger->warning(sprintf('Failed to broadcast entity.saved: %s', $e->getMessage()));
            }
        });

        $this->dispatcher->addListener('waaseyaa.entity.post_delete', static function (object $event) use ($broadcastStorage, $logger): void {
            try {
                $entity = $event->entity;
                $broadcastStorage->push(
                    'admin',
                    'entity.deleted',
                    ['entityType' => $entity->getEntityTypeId(), 'id' => (string) ($entity->uuid() ?: $entity->id())],
                );
            } catch (\Throwable $e) {
                $logger->warning(sprintf('Failed to broadcast entity.deleted: %s', $e->getMessage()));
            }
        });
    }

    public function registerRenderCacheListeners(RenderCache $renderCache): void
    {
        $invalidate = function (object $event) use ($renderCache): void {
            if (!$event instanceof EntityEvent) {
                return;
            }

            $entityType = $event->entity->getEntityTypeId();
            $renderCache->invalidateEntity(
                $entityType,
                $event->entity->id(),
            );

            // Relationship and node updates can affect relationship-navigation SSR context across many pages.
            if (in_array($entityType, ['relationship', 'node'], true)) {
                $renderCache->invalidateEntity('node', null);
                $renderCache->invalidateEntity('relationship', null);
            }
        };

        $this->dispatcher->addListener(EntityEvents::POST_SAVE->value, $invalidate);
        $this->dispatcher->addListener(EntityEvents::POST_DELETE->value, $invalidate);
    }

    public function registerDiscoveryCacheListeners(CacheBackendInterface $cache): void
    {
        $logger = $this->logger;
        $invalidate = static function (EntityEvent $event) use ($cache, $logger): void {
            try {
                if ($cache instanceof TagAwareCacheInterface) {
                    $entityType = strtolower($event->entity->getEntityTypeId());
                    $entityId = $event->entity->id();
                    $tags = [
                        'discovery',
                        'discovery:entity:' . $entityType,
                    ];
                    if ($entityId !== null && $entityId !== '') {
                        $tags[] = sprintf('discovery:entity:%s:%s', $entityType, (string) $entityId);
                    }

                    // Relationship and node updates can influence many discovery reads.
                    if (in_array($entityType, ['relationship', 'node'], true)) {
                        $tags[] = 'discovery:surface:discovery_api';
                    }

                    $cache->invalidateByTags(array_values(array_unique($tags)));
                    return;
                }

                $cache->deleteAll();
            } catch (\Throwable $e) {
                $logger->warning(sprintf('Failed to clear discovery cache: %s', $e->getMessage()));
            }
        };

        $this->dispatcher->addListener(EntityEvents::POST_SAVE->value, static function (EntityEvent $event) use ($invalidate): void {
            $invalidate($event);
        });
        $this->dispatcher->addListener(EntityEvents::POST_DELETE->value, static function (EntityEvent $event) use ($invalidate): void {
            $invalidate($event);
        });
    }

    public function registerMcpReadCacheListeners(CacheBackendInterface $cache): void
    {
        $logger = $this->logger;
        $invalidate = static function (EntityEvent $event) use ($cache, $logger): void {
            try {
                if ($cache instanceof TagAwareCacheInterface) {
                    $entityType = strtolower($event->entity->getEntityTypeId());
                    $entityId = $event->entity->id();
                    $tags = [
                        'mcp_read',
                        'mcp_read:entity:' . $entityType,
                    ];
                    if ($entityId !== null && $entityId !== '') {
                        $tags[] = sprintf('mcp_read:entity:%s:%s', $entityType, (string) $entityId);
                    }
                    $cache->invalidateByTags(array_values(array_unique($tags)));
                    return;
                }

                $cache->deleteAll();
            } catch (\Throwable $e) {
                $logger->warning(sprintf('Failed to clear MCP read cache: %s', $e->getMessage()));
            }
        };

        $this->dispatcher->addListener(EntityEvents::POST_SAVE->value, static function (EntityEvent $event) use ($invalidate): void {
            $invalidate($event);
        });
        $this->dispatcher->addListener(EntityEvents::POST_DELETE->value, static function (EntityEvent $event) use ($invalidate): void {
            $invalidate($event);
        });
    }

    /**
     * @param array<string, mixed> $config
     */
    public function registerEmbeddingLifecycleListeners(SqliteEmbeddingStorage $embeddingStorage, array $config): void
    {
        $embeddingProvider = EmbeddingProviderFactory::fromConfig($config);
        $embeddingListener = new EntityEmbeddingListener(
            storage: $embeddingStorage,
            embeddingProvider: $embeddingProvider,
        );
        $cleanupListener = new EntityEmbeddingCleanupListener($embeddingStorage);
        $this->dispatcher->addListener(
            EntityEvents::POST_SAVE->value,
            [$embeddingListener, 'onPostSave'],
        );
        $this->dispatcher->addListener(
            EntityEvents::POST_DELETE->value,
            [$cleanupListener, 'onPostDelete'],
        );
    }
}
