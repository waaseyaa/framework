<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Http;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Cache\CacheBackendInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Cache\DiscoveryCachePrimitives;
use Waaseyaa\Relationship\RelationshipDiscoveryService;
use Waaseyaa\Relationship\RelationshipTraversalService;
use Waaseyaa\Workflows\WorkflowVisibility;

/**
 * Handles discovery API endpoint logic: topic hubs, clusters,
 * timelines, and entity endpoint pages.
 *
 * Encapsulates discovery cache primitives, relationship type parsing,
 * entity visibility checks, and cache key building.
 */
final class DiscoveryApiHandler
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly DatabaseInterface $database,
        private readonly ?CacheBackendInterface $discoveryCache = null,
    ) {}

    /**
     * @return list<string>
     */
    public function parseRelationshipTypesQuery(mixed $value): array
    {
        if (is_string($value)) {
            $parts = array_map('trim', explode(',', $value));
            return array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
        }

        if (is_array($value)) {
            $types = [];
            foreach ($value as $candidate) {
                if (!is_string($candidate)) {
                    continue;
                }
                $normalized = trim($candidate);
                if ($normalized === '') {
                    continue;
                }
                $types[] = $normalized;
            }

            return array_values(array_unique($types));
        }

        return [];
    }

    /**
     * @param array<string, mixed> $options
     */
    public function buildDiscoveryCacheKey(string $surface, string $entityType, string $entityId, array $options): string
    {
        return $this->discoveryCachePrimitives()->buildKey($surface, $entityType, $entityId, $options);
    }

    public function normalizeForCacheKey(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->normalizeForCacheKey($item), $value);
        }

        ksort($value);
        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = $this->normalizeForCacheKey($item);
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDiscoveryCachedResponse(string $cacheKey, AccountInterface $account): ?array
    {
        if ($account->isAuthenticated() || $this->discoveryCache === null) {
            return null;
        }

        $item = $this->discoveryCache->get($cacheKey);
        if ($item === false || !is_array($item->data)) {
            return null;
        }

        return $this->withDiscoveryContractMeta($item->data);
    }

    /**
     * Prepare a discovery response payload with caching metadata.
     *
     * Returns [payload, headers] tuple for the caller to send.
     *
     * @param array<string, mixed> $payload
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public function prepareDiscoveryResponse(int $status, array $payload, string $cacheKey, AccountInterface $account): array
    {
        $payload = $this->withDiscoveryContractMeta($payload);
        $headers = [];
        if ($account->isAuthenticated()) {
            $headers['Cache-Control'] = 'private, no-store';
        } else {
            $headers['Cache-Control'] = 'public, max-age=120';
            if ($this->discoveryCache !== null) {
                $this->discoveryCache->set(
                    $cacheKey,
                    $payload,
                    time() + 120,
                    $this->buildDiscoveryCacheTags($payload),
                );
                $headers['X-Waaseyaa-Discovery-Cache'] = 'MISS';
            }
        }

        return [$payload, $headers];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function withDiscoveryContractMeta(array $payload): array
    {
        return $this->discoveryCachePrimitives()->withContractMeta($payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    public function buildDiscoveryCacheTags(array $payload): array
    {
        return $this->discoveryCachePrimitives()->buildTags($payload);
    }

    public function isDiscoveryEndpointPairPublic(string $fromType, string $fromId, string $toType, string $toId): bool
    {
        $from = $this->loadDiscoveryEntity($fromType, $fromId);
        $to = $this->loadDiscoveryEntity($toType, $toId);

        if ($from === null || $to === null) {
            return false;
        }

        return $this->isDiscoveryEntityPublic($fromType, $from->toArray())
            && $this->isDiscoveryEntityPublic($toType, $to->toArray());
    }

    public function loadDiscoveryEntity(string $entityType, string $entityId): ?EntityInterface
    {
        if (!$this->entityTypeManager->hasDefinition($entityType)) {
            return null;
        }

        try {
            $storage = $this->entityTypeManager->getStorage($entityType);
            $resolvedId = ctype_digit($entityId) ? (int) $entityId : $entityId;
            $entity = $storage->load($resolvedId);
            if ($entity instanceof EntityInterface) {
                return $entity;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function isDiscoveryEntityPublic(string $entityType, array $values): bool
    {
        return (new WorkflowVisibility())->isEntityPublic($entityType, $values);
    }

    public function createDiscoveryService(): RelationshipDiscoveryService
    {
        return new RelationshipDiscoveryService(
            new RelationshipTraversalService($this->entityTypeManager, $this->database),
        );
    }

    private function discoveryCachePrimitives(): DiscoveryCachePrimitives
    {
        return new DiscoveryCachePrimitives();
    }
}
