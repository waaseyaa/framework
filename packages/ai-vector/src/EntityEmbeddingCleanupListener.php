<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector;

use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Removes an entity's vector after the entity is deleted.
 *
 * POST_DELETE runs after the delete has committed, so removal is best-effort
 * (FW-AIV-COMP-01): a storage failure is logged once as an error and never
 * surfaced as a failure of the committed delete.
 */
final class EntityEmbeddingCleanupListener
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly EmbeddingStorageInterface $storage,
        ?LoggerInterface $logger = null,
        private readonly ?EmbeddingExecutionGuardInterface $executionGuard = null,
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
            $token = $this->executionGuard->begin($entityType, (string) $entityId);
            $this->executionGuard->runIfCurrent(
                $entityType,
                (string) $entityId,
                $token,
                fn() => $this->storage->delete($entityType, (string) $entityId),
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
