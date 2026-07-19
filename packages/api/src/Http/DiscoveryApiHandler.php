<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Http;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Cache\CacheBackendInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityValues;
use Waaseyaa\Foundation\Cache\DiscoveryCachePrimitives;
use Waaseyaa\Relationship\RelationshipDiscoveryService;
use Waaseyaa\Relationship\RelationshipTraversalService;
use Waaseyaa\Workflows\WorkflowVisibility;
use Waaseyaa\Workflows\WorkflowVisibilityFilter;

/**
 * Handles discovery API endpoint logic: topic hubs, clusters,
 * timelines, and entity endpoint pages.
 *
 * Encapsulates discovery cache primitives, relationship type parsing,
 * entity visibility checks, and cache key building.
 */
final class DiscoveryApiHandler
{
    /**
     * @param ?EntityAccessHandler $accessHandler Threaded into
     *        RelationshipTraversalService (via createDiscoveryService()) and
     *        into isDiscoveryEntityPublic()/isDiscoveryEndpointPairPublic() so
     *        the discovery/browse API path gates disclosed endpoint identities
     *        on per-account 'view' access, not publish status alone (audit R5
     *        residual #1, R7 WP2). When null (e.g. legacy/unwired test
     *        construction), the access gate is OFF and behavior matches
     *        pre-fix publish-status-only filtering — production wiring
     *        (HttpKernel::finalizeBoot()) always passes the real handler.
     */
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly DatabaseInterface $database,
        private readonly ?CacheBackendInterface $discoveryCache = null,
        private readonly ?EntityAccessHandler $accessHandler = null,
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

    /** @param \Waaseyaa\Access\AuthorizationPrincipalInterface|null $account */
    public function isDiscoveryEndpointPairPublic(
        string $fromType,
        string $fromId,
        string $toType,
        string $toId,
        ?AccountInterface $account = null,
    ): bool {
        $from = $this->loadDiscoveryEntity($fromType, $fromId);
        $to = $this->loadDiscoveryEntity($toType, $toId);

        if ($from === null || $to === null) {
            return false;
        }

        return $this->isDiscoveryEntityPublic($from, $account)
            && $this->isDiscoveryEntityPublic($to, $account);
    }

    public function loadDiscoveryEntity(string $entityType, string $entityId): ?EntityInterface
    {
        if (!$this->entityTypeManager->hasDefinition($entityType)) {
            return null;
        }

        try {
            // C-22 WP3: read path now goes through the canonical repository.
            $entity = $this->entityTypeManager->getRepository($entityType)->find($entityId);
            if ($entity instanceof EntityInterface) {
                return $entity;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Is $entity allowed to anchor a discovery/browse response — publicly
     * (workflow status) AND, when $account is supplied and an access handler
     * is wired, viewable by that account.
     *
     * Gates the discovery "endpoint" route's OWN primary entity (and, via
     * isDiscoveryEndpointPairPublic(), a relationship entity's own from/to
     * endpoint pair) — this is a source-entity/identity-disclosure check, the
     * same disclosure class RelationshipTraversalService's endpoint-visibility
     * gate closes for RELATED entities (audit R5 residual #1, R7 WP2): a
     * published-but-access-restricted entity must not be discoverable just
     * because it is published.
     */
    /** @param \Waaseyaa\Access\AuthorizationPrincipalInterface|null $account */
    public function isDiscoveryEntityPublic(EntityInterface $entity, ?AccountInterface $account = null): bool
    {
        if (!new WorkflowVisibility()->isEntityPublic($entity->getEntityTypeId(), EntityValues::toCastAwareMap($entity))) {
            return false;
        }

        if ($account === null || $this->accessHandler === null) {
            return true;
        }

        return $this->accessHandler->check($entity, 'view', $account)->isAllowed();
    }

    /** @param \Waaseyaa\Access\AuthorizationPrincipalInterface $account */
    public function createDiscoveryService(AccountInterface $account): RelationshipDiscoveryService
    {
        return new RelationshipDiscoveryService(
            // Pass the visibility filter so related entities are gated on
            // publication state — without it the traversal service fails closed
            // and would withhold every related label/path. $accessHandler +
            // $account layer a per-account 'view' gate ON TOP of the publish-
            // status gate (audit R5 residual #1, R7 WP2): a published-but-
            // access-restricted related entity must still be withheld.
            new RelationshipTraversalService(
                $this->entityTypeManager,
                $this->database,
                new WorkflowVisibilityFilter(),
                $this->accessHandler,
                $account,
            ),
        );
    }

    private function discoveryCachePrimitives(): DiscoveryCachePrimitives
    {
        return new DiscoveryCachePrimitives();
    }
}
