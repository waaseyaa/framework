<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tools;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Api\ResourceSerializer;

abstract class McpTool
{
    public function __construct(
        protected readonly EntityTypeManagerInterface $entityTypeManager,
        protected readonly ResourceSerializer $serializer,
        protected readonly EntityAccessHandler $accessHandler,
        protected readonly AccountInterface $account,
    ) {}

    protected function loadEntityByTypeAndId(string $entityType, string $entityId): ?EntityInterface
    {
        if (!$this->entityTypeManager->hasDefinition($entityType)) {
            return null;
        }

        $storage = $this->entityTypeManager->getStorage($entityType);
        $resolvedId = ctype_digit($entityId) ? (int) $entityId : $entityId;
        $entity = $storage->load($resolvedId);

        return $entity instanceof EntityInterface ? $entity : null;
    }

    protected function assertTraversalSourceVisible(string $entityType, string $entityId): void
    {
        $entity = $this->loadEntityByTypeAndId($entityType, $entityId);
        if (!$entity instanceof EntityInterface) {
            throw new \InvalidArgumentException(sprintf('Traversal source entity not found: %s:%s', $entityType, $entityId));
        }

        if (!$this->accessHandler->check($entity, 'view', $this->account)->isAllowed()) {
            throw new \RuntimeException(sprintf('Traversal source entity is not visible: %s:%s', $entityType, $entityId));
        }
    }
}
