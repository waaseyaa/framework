<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Cache;

final class DiscoveryCachePrimitives
{
    public const string CONTRACT_VERSION = 'v1.0';
    public const string CONTRACT_STABILITY = 'stable';

    /**
     * @param array<string, mixed> $options
     */
    public function buildKey(string $surface, string $entityType, string $entityId, array $options): string
    {
        $serialized = json_encode([
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
