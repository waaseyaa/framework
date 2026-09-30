<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector;

use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Standalone guarded cleanup of an identity whose current source is absent.
 *
 * Default composition uses transactional source invalidation instead. Direct
 * callers require fresh repository reads and a participating source fence;
 * a delayed delete event cannot erase a recreated entity's vector. Failures
 * are logged and do not fail the already committed deletion.
 * @api
 */
final class EntityEmbeddingCleanupListener
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly EmbeddingStorageInterface $storage,
        ?LoggerInterface $logger = null,
        private readonly ?EmbeddingExecutionGuardInterface $executionGuard = null,
        private readonly ?EntityTypeManagerInterface $entityTypeManager = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function onPostDelete(EntityEvent $event): void
    {
        $entityId = $event->entity->id();
        if ($entityId === null || $entityId === '') {
            return;
        }

        $entityType = $event->entity->getEntityTypeId();
        try {
            if ($this->executionGuard === null) {
                throw new \LogicException('[AIV-EXECUTION-001] Cleanup requires a shared execution guard.');
            }
            if (!$this->executionGuard->supportsStorage($this->storage)) {
                throw new \LogicException('[AIV-EXECUTION-003] Cleanup requires compatible storage and guard.');
            }
            if ($this->entityTypeManager === null) {
                throw new \LogicException('[AIV-EXECUTION-005] Cleanup requires fresh served repository reads.');
            }
            $token = $this->executionGuard->begin($entityType, (string) $entityId);
            $this->executionGuard->runIfCurrent(
                $entityType,
                (string) $entityId,
                $token,
                function () use ($entityType, $entityId): void {
                    if ($this->entityTypeManager->getRepository($entityType)->find((string) $entityId) === null) {
                        $this->storage->delete($entityType, (string) $entityId);
                    }
                },
            );
        } catch (\Throwable $exception) {
            $this->logger->error(sprintf(
                'Embedding removal failed for %s:%s after delete: %s',
                $entityType,
                (string) $entityId,
                $exception->getMessage(),
            ));
        }
    }
}
