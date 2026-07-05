<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Cache;

final class DiscoveryCachePrimitives
{
    public const string CONTRACT_VERSION = 'v1.0';
    public const string CONTRACT_STABILITY = 'stable';

    /**
     * Internal cache-KEY generation, distinct from CONTRACT_VERSION (which is
     * the public response-shape contract). Never appears in a response
     * payload or cache tag — only its VALUE changing matters: any change
     * alters every hashed key in buildKey(), so every pre-bump cache entry
     * becomes an orphaned miss instead of being read back.
     *
     * Bumped 1 -> 2 for R7 WP2 (audit R5 residual #1): the discovery/browse
     * endpoint-visibility gate became per-account-access-aware, not just
     * publish-status-aware (see RelationshipTraversalService's
     * $accessHandler/$account constructor params and
     * DiscoveryApiHandler::createDiscoveryService()). A response cached
     * under generation 1 could have been computed while a published-but-
     * access-restricted endpoint was still disclosed. The anonymous
     * discovery cache (populated only for unauthenticated callers — see
     * getDiscoveryCachedResponse()) has a short 120s TTL regardless, but
     * this bump makes the fix effective immediately on deploy instead of
     * waiting out that window.
     *
     * Bumped 2 -> 3 for R8 WP2 (audit R8-c): DiscoveryRouter::handleTopicHub/
     * handleCluster/handleTimeline did not gate the SOURCE entity's own view
     * access before this fix (see DiscoveryRouter's source-entity gate,
     * mirroring handleEndpoint's pre-existing one) — a restricted-but-existing
     * source could be cached under generation 2 with a 200 hub/cluster/
     * timeline payload, an existence/access oracle that would keep being
     * served for up to the 120s TTL. The new source-entity gate runs before
     * the cache read going forward, so this bump only needs to orphan the
     * pre-fix backlog.
     */
    private const string CACHE_KEY_GENERATION = '3';

    /**
     * @param array<string, mixed> $options
     */
    public function buildKey(string $surface, string $entityType, string $entityId, array $options): string
    {
        $serialized = json_encode([
            'generation' => self::CACHE_KEY_GENERATION,
            'surface' => $surface,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'options' => $this->normalizeForCacheKey($options),
        ], JSON_THROW_ON_ERROR);

        return 'discovery:' . sha1((string) $serialized);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function withContractMeta(array $payload): array
    {
        if (!isset($payload['meta']) || !is_array($payload['meta'])) {
            $payload['meta'] = [];
        }

        $payload['meta']['contract_version'] = self::CONTRACT_VERSION;
        $payload['meta']['contract_stability'] = self::CONTRACT_STABILITY;
        if (!is_string($payload['meta']['surface'] ?? null) || trim((string) $payload['meta']['surface']) === '') {
            $payload['meta']['surface'] = 'discovery_api';
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    public function buildTags(array $payload): array
    {
        $tags = [
            'discovery',
            'discovery:contract:' . self::CONTRACT_VERSION,
        ];

        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $surface = is_string($meta['surface'] ?? null) ? trim((string) $meta['surface']) : '';
        if ($surface !== '') {
            $tags[] = 'discovery:surface:' . strtolower($surface);
        }

        foreach ($this->extractEntityPairs($payload) as $pair) {
            $tags[] = 'discovery:entity:' . $pair['type'];
            $tags[] = sprintf('discovery:entity:%s:%s', $pair['type'], $pair['id']);
        }

        $filters = is_array($meta['filters'] ?? null) ? $meta['filters'] : [];
        $status = is_string($filters['status'] ?? null) ? strtolower(trim((string) $filters['status'])) : '';
        if ($status !== '') {
            $tags[] = 'discovery:status:' . $status;
        }
        $direction = is_string($filters['direction'] ?? null) ? strtolower(trim((string) $filters['direction'])) : '';
        if ($direction !== '') {
            $tags[] = 'discovery:direction:' . $direction;
        }

        return array_values(array_unique($tags));
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
     * @param array<string, mixed> $payload
     * @return list<array{type: string, id: string}>
     */
    private function extractEntityPairs(array $payload): array
    {
        $pairs = [];

        $collector = function (string $type, string $id) use (&$pairs): void {
            $normalizedType = strtolower(trim($type));
            $normalizedId = trim($id);
            if ($normalizedType === '' || $normalizedId === '') {
                return;
            }

            $pairs[$normalizedType . ':' . $normalizedId] = [
                'type' => $normalizedType,
                'id' => $normalizedId,
            ];
        };

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $source = is_array($data['source'] ?? null) ? $data['source'] : [];
        if ($source === [] && is_array($data['data'] ?? null)) {
            $source = is_array($data['data']['source'] ?? null) ? $data['data']['source'] : [];
        }

        if (is_string($source['type'] ?? null) && is_scalar($source['id'] ?? null)) {
            $collector($source['type'], (string) $source['id']);
        }

        foreach ($this->extractRelatedPairsFromData($data) as $pair) {
            $collector($pair['type'], $pair['id']);
        }

        return array_values($pairs);
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array{type: string, id: string}>
     */
    private function extractRelatedPairsFromData(array $data): array
    {
        $pairs = [];

        $collectEdge = static function (array $edge, array &$pairs): void {
            $type = is_string($edge['related_entity_type'] ?? null) ? $edge['related_entity_type'] : '';
            $id = is_scalar($edge['related_entity_id'] ?? null) ? (string) $edge['related_entity_id'] : '';
            if ($type !== '' && $id !== '') {
                $pairs[] = ['type' => $type, 'id' => $id];
            }
        };

        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        foreach ($items as $edge) {
            if (is_array($edge)) {
                $collectEdge($edge, $pairs);
            }
        }

        $browse = is_array($data['browse'] ?? null) ? $data['browse'] : [];
        foreach (['outbound', 'inbound'] as $directionKey) {
            $edges = is_array($browse[$directionKey] ?? null) ? $browse[$directionKey] : [];
            foreach ($edges as $edge) {
                if (is_array($edge)) {
                    $collectEdge($edge, $pairs);
                }
            }
        }

        $clusters = is_array($data['clusters'] ?? null) ? $data['clusters'] : [];
        foreach ($clusters as $cluster) {
            if (!is_array($cluster)) {
                continue;
            }
            $relatedEntities = is_array($cluster['related_entities'] ?? null) ? $cluster['related_entities'] : [];
            foreach ($relatedEntities as $entity) {
                if (!is_array($entity)) {
                    continue;
                }
                $type = is_string($entity['type'] ?? null) ? $entity['type'] : '';
                $id = is_scalar($entity['id'] ?? null) ? (string) $entity['id'] : '';
                if ($type !== '' && $id !== '') {
                    $pairs[] = ['type' => $type, 'id' => $id];
                }
            }
        }

        return $pairs;
    }
}
