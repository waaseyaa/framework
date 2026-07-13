<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel;

use Waaseyaa\AI\Vector\EmbeddingProviderFactory;
use Waaseyaa\AI\Vector\EntityEmbeddingCleanupListener;
use Waaseyaa\AI\Vector\EntityEmbeddingListener;
use Waaseyaa\AI\Vector\SqliteEmbeddingStorage;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Cache\CacheBackendInterface;
use Waaseyaa\Cache\TagAwareCacheInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\EntityStorage\Event\RevisionPointerMovedEvent;
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

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

    public function registerDiscoveryCacheListeners(CacheBackendInterface $cache): void
    {
        $this->registerEntityCacheInvalidationListeners(
            $cache,
            'discovery',
            'discovery cache',
            static function (string $entityType): array {
                // Relationship and node updates can influence many discovery reads.
                return in_array($entityType, ['relationship', 'node'], true)
                    ? ['discovery:surface:discovery_api']
                    : [];
            },
        );
    }

    public function registerMcpReadCacheListeners(CacheBackendInterface $cache): void
    {
        $this->registerEntityCacheInvalidationListeners($cache, 'mcp_read', 'MCP read cache');
    }

    /**
     * Shared implementation for entity-event-driven cache invalidation listeners.
     *
     * Registers POST_SAVE and POST_DELETE listeners that invalidate the given
     * cache by tag (when the backend is tag-aware) or flush all (fallback).
     * Tags follow the pattern: `$tagPrefix`, `$tagPrefix:entity:<type>`,
     * and optionally `$tagPrefix:entity:<type>:<id>`. An optional `$extraTags`
     * closure receives the entity type and returns additional tags to include.
     *
     * @param \Closure(string): list<string>|null $extraTags callable(entityType): extra tag list; null for none
     */
    private function registerEntityCacheInvalidationListeners(
        CacheBackendInterface $cache,
        string $tagPrefix,
        string $errorLabel,
        ?\Closure $extraTags = null,
    ): void {
        $logger = $this->logger;
        $invalidate = static function (EntityEvent $event) use ($cache, $logger, $tagPrefix, $errorLabel, $extraTags): void {
            try {
                if ($cache instanceof TagAwareCacheInterface) {
                    $entityType = strtolower($event->entity->getEntityTypeId());
                    $entityId = $event->entity->id();
                    $tags = [
                        $tagPrefix,
                        $tagPrefix . ':entity:' . $entityType,
                    ];
                    if ($entityId !== null && $entityId !== '') {
                        $tags[] = sprintf('%s:entity:%s:%s', $tagPrefix, $entityType, (string) $entityId);
                    }
                    if ($extraTags !== null) {
                        foreach ($extraTags($entityType) as $tag) {
                            $tags[] = $tag;
                        }
                    }
                    $cache->invalidateByTags(array_values(array_unique($tags)));
                    return;
                }

                $cache->deleteAll();
            } catch (\Throwable $e) {
                $logger->warning(sprintf('Failed to clear %s: %s', $errorLabel, $e->getMessage()));
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
    public function registerEmbeddingLifecycleListeners(
        SqliteEmbeddingStorage $embeddingStorage,
        array $config,
        ?EntityTypeManagerInterface $entityTypeManager = null,
    ): void {
        $embeddingProvider = EmbeddingProviderFactory::fromConfig($config);
        $embeddingListener = new EntityEmbeddingListener(
            storage: $embeddingStorage,
            embeddingProvider: $embeddingProvider,
            entityTypeManager: $entityTypeManager,
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
        // CW-v1 option-1 (#1920 PR-2, design §3.3): a standalone pointer
        // move (rollback/revert/promote with no accompanying save()) now
        // changes served content with no POST_SAVE of its own — mirrors
        // Waaseyaa\Cache\Listener\EntityCacheSubscriber's identical pattern.
        $this->dispatcher->addListener(
            RevisionPointerMovedEvent::class,
            [$embeddingListener, 'onRevisionPointerMoved'],
        );
        $this->dispatcher->addListener(
            EntityEvents::REVISION_REVERTED->value,
            [$embeddingListener, 'onRevisionReverted'],
        );
    }
}
