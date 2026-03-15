<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tools;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

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

    /**
     * @param array<string, mixed> $parsed
     * @return list<array{
     *   relationship: EntityInterface,
     *   related_entity_type: string,
     *   related_entity_id: string,
     *   direction: string,
     *   inverse: bool
     * }>
     */
    protected function collectTraversalRows(array $parsed): array
    {
        if (!$this->entityTypeManager->hasDefinition('relationship')) {
            return [];
        }

        $relationshipStorage = $this->entityTypeManager->getStorage('relationship');
        $ids = $relationshipStorage->getQuery()->accessCheck(false)->execute();
        $rows = [];
        /** @var array<string, bool> $visibilityCache */
        $visibilityCache = [];
        $isVisible = function (string $entityType, string $entityId) use (&$visibilityCache): bool {
            $cacheKey = $entityType . ':' . $entityId;
            if (array_key_exists($cacheKey, $visibilityCache)) {
                return $visibilityCache[$cacheKey];
            }

            if (!$this->entityTypeManager->hasDefinition($entityType)) {
                $visibilityCache[$cacheKey] = false;
                return false;
            }

            $entity = $this->loadEntityByTypeAndId($entityType, $entityId);
            if (!$entity instanceof EntityInterface) {
                $visibilityCache[$cacheKey] = false;
                return false;
            }

            $visibilityCache[$cacheKey] = $this->accessHandler->check($entity, 'view', $this->account)->isAllowed();
            return $visibilityCache[$cacheKey];
        };

        foreach ($relationshipStorage->loadMultiple($ids) as $relationship) {
            if (!$this->accessHandler->check($relationship, 'view', $this->account)->isAllowed()) {
                continue;
            }

            $values = $relationship->toArray();
            $fromType = strtolower((string) ($values['from_entity_type'] ?? ''));
            $fromId = (string) ($values['from_entity_id'] ?? '');
            $toType = strtolower((string) ($values['to_entity_type'] ?? ''));
            $toId = (string) ($values['to_entity_id'] ?? '');
            if ($fromType === '' || $fromId === '' || $toType === '' || $toId === '') {
                continue;
            }

            $status = (int) ($values['status'] ?? 0);
            if ($parsed['status'] === 'published' && $status !== 1) {
                continue;
            }
            if ($parsed['status'] === 'unpublished' && $status !== 0) {
                continue;
            }

            $relationshipType = strtolower((string) ($values['relationship_type'] ?? ''));
            if ($parsed['relationship_types'] !== [] && !in_array($relationshipType, $parsed['relationship_types'], true)) {
                continue;
            }

            if ($parsed['at'] !== null && !$this->isRelationshipActiveAt($values, $parsed['at'])) {
                continue;
            }

            $matchesOutbound = $fromType === $parsed['entity_type'] && $fromId === $parsed['entity_id'];
            $matchesInbound = $toType === $parsed['entity_type'] && $toId === $parsed['entity_id'];
            if (!$matchesOutbound && !$matchesInbound) {
                continue;
            }

            if (in_array($parsed['direction'], ['outbound', 'both'], true) && $matchesOutbound) {
                if (!$isVisible($toType, $toId)) {
                    continue;
                }
                $rows[] = [
                    'relationship' => $relationship,
                    'related_entity_type' => $toType,
                    'related_entity_id' => $toId,
                    'direction' => 'outbound',
                    'inverse' => false,
                ];
            }
            if (in_array($parsed['direction'], ['inbound', 'both'], true) && $matchesInbound) {
                if (!$isVisible($fromType, $fromId)) {
                    continue;
                }
                $rows[] = [
                    'relationship' => $relationship,
                    'related_entity_type' => $fromType,
                    'related_entity_id' => $fromId,
                    'direction' => 'inbound',
                    'inverse' => true,
                ];
            }
        }

        usort($rows, static function (array $a, array $b): int {
            $aDirectionRank = $a['direction'] === 'outbound' ? 0 : 1;
            $bDirectionRank = $b['direction'] === 'outbound' ? 0 : 1;
            if ($aDirectionRank !== $bDirectionRank) {
                return $aDirectionRank <=> $bDirectionRank;
            }

            $aType = (string) ($a['relationship']->get('relationship_type') ?? '');
            $bType = (string) ($b['relationship']->get('relationship_type') ?? '');
            $typeCompare = strcmp($aType, $bType);
            if ($typeCompare !== 0) {
                return $typeCompare;
            }

            return strcmp((string) $a['relationship']->id(), (string) $b['relationship']->id());
        });

        if (count($rows) > $parsed['limit']) {
            $rows = array_slice($rows, 0, $parsed['limit']);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $relationshipValues
     */
    protected function isRelationshipActiveAt(array $relationshipValues, int $at): bool
    {
        $start = $this->normalizeTemporal($relationshipValues['start_date'] ?? null);
        $end = $this->normalizeTemporal($relationshipValues['end_date'] ?? null);

        if ($start !== null && $at < $start) {
            return false;
        }
        if ($end !== null && $at > $end) {
            return false;
        }

        return true;
    }

    protected function normalizeTemporal(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        if (is_string($value)) {
            $ts = strtotime($value);
            return $ts !== false ? $ts : null;
        }

        return null;
    }
}
