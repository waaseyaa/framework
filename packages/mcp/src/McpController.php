<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Vector\EmbeddingProviderInterface;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\AI\Vector\SearchController;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Relationship\RelationshipTraversalService;

final class McpController
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly ResourceSerializer $serializer,
        private readonly EntityAccessHandler $accessHandler,
        private readonly AccountInterface $account,
        private readonly EmbeddingStorageInterface $embeddingStorage,
        private readonly ?EmbeddingProviderInterface $embeddingProvider = null,
        private readonly ?RelationshipTraversalService $relationshipTraversal = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        return [
            'protocolVersion' => '2024-11-05',
            'server' => [
                'name' => 'Waaseyaa MCP',
                'version' => '0.4.0',
            ],
            'tools' => [
                ['name' => 'search_teachings', 'description' => 'Semantic search for teachings'],
                ['name' => 'get_entity', 'description' => 'Fetch a single entity by type and ID'],
                ['name' => 'list_entity_types', 'description' => 'List available entity types and schemas'],
                ['name' => 'traverse_relationships', 'description' => 'Traverse relationship entities for a source entity with direction/type/temporal filters'],
                ['name' => 'get_related_entities', 'description' => 'Resolve related entities via relationship traversal with optional edge payloads'],
                ['name' => 'get_knowledge_graph', 'description' => 'Return directional relationship graph surface for an entity'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $rpc
     * @return array<string, mixed>
     */
    public function handleRpc(array $rpc): array
    {
        $id = $rpc['id'] ?? null;
        $method = is_string($rpc['method'] ?? null) ? $rpc['method'] : '';
        $params = is_array($rpc['params'] ?? null) ? $rpc['params'] : [];

        return match ($method) {
            'tools/list' => $this->result($id, ['tools' => $this->manifest()['tools']]),
            'tools/call' => $this->handleToolCall($id, $params),
            default => $this->error($id, -32601, "Method not found: {$method}"),
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function handleToolCall(mixed $id, array $params): array
    {
        $tool = is_string($params['name'] ?? null) ? $params['name'] : '';
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if ($tool === '') {
            return $this->error($id, -32602, 'Missing tool name.');
        }

        try {
            $result = match ($tool) {
                'search_teachings' => $this->toolSearchTeachings($arguments),
                'get_entity' => $this->toolGetEntity($arguments),
                'list_entity_types' => $this->toolListEntityTypes(),
                'traverse_relationships' => $this->toolTraverseRelationships($arguments),
                'get_related_entities' => $this->toolGetRelatedEntities($arguments),
                'get_knowledge_graph' => $this->toolGetKnowledgeGraph($arguments),
                default => null,
            };
        } catch (\InvalidArgumentException $e) {
            return $this->error($id, -32602, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->error($id, -32000, $e->getMessage());
        }

        if ($result === null) {
            return $this->error($id, -32602, "Unknown tool: {$tool}");
        }

        return $this->result($id, [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            ]],
        ]);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function toolSearchTeachings(array $arguments): array
    {
        $query = is_string($arguments['query'] ?? null) ? trim($arguments['query']) : '';
        $entityType = is_string($arguments['type'] ?? null) ? trim($arguments['type']) : 'node';
        $limit = is_numeric($arguments['limit'] ?? null) ? (int) $arguments['limit'] : 10;

        $controller = new SearchController(
            entityTypeManager: $this->entityTypeManager,
            serializer: $this->serializer,
            embeddingStorage: $this->embeddingStorage,
            embeddingProvider: $this->embeddingProvider,
            accessHandler: $this->accessHandler,
            account: $this->account,
        );

        return $controller->search($query, $entityType, $limit)->toArray();
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function toolGetEntity(array $arguments): array
    {
        $entityType = is_string($arguments['type'] ?? null) ? trim($arguments['type']) : '';
        $id = $arguments['id'] ?? null;

        $controller = new JsonApiController(
            entityTypeManager: $this->entityTypeManager,
            serializer: $this->serializer,
            accessHandler: $this->accessHandler,
            account: $this->account,
        );

        return $controller->show($entityType, is_numeric((string) $id) ? (int) $id : (string) $id)->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function toolListEntityTypes(): array
    {
        $types = [];
        foreach ($this->entityTypeManager->getDefinitions() as $id => $definition) {
            $types[] = [
                'id' => $id,
                'label' => $definition->getLabel(),
                'keys' => $definition->getKeys(),
                'fields' => $definition->getFieldDefinitions(),
            ];
        }

        return ['data' => $types];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function toolTraverseRelationships(array $arguments): array
    {
        $parsed = $this->parseTraversalArguments($arguments);
        $rows = $this->collectTraversalRows($parsed);

        $data = [];
        foreach ($rows as $row) {
            $resource = $this->serializer
                ->serialize($row['relationship'], $this->accessHandler, $this->account)
                ->toArray();
            $resource['meta'] = [
                'source' => [
                    'type' => $parsed['entity_type'],
                    'id' => $parsed['entity_id'],
                ],
                'related' => [
                    'type' => $row['related_entity_type'],
                    'id' => $row['related_entity_id'],
                ],
                'direction' => $row['direction'],
                'inverse' => $row['inverse'],
            ];
            $data[] = $resource;
        }

        return [
            'data' => $data,
            'meta' => [
                'filters' => [
                    'entity_type' => $parsed['entity_type'],
                    'entity_id' => $parsed['entity_id'],
                    'direction' => $parsed['direction'],
                    'status' => $parsed['status'],
                    'relationship_types' => $parsed['relationship_types'],
                    'at' => $parsed['at'],
                    'limit' => $parsed['limit'],
                ],
                'count' => count($data),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function toolGetRelatedEntities(array $arguments): array
    {
        $parsed = $this->parseTraversalArguments($arguments);
        $includeRelationships = filter_var($arguments['include_relationships'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $rows = $this->collectTraversalRows($parsed);

        /** @var array<string, array<string, mixed>> $entitiesByKey */
        $entitiesByKey = [];
        $relationshipData = [];
        foreach ($rows as $row) {
            $relatedType = $row['related_entity_type'];
            $relatedId = $row['related_entity_id'];

            if (!$this->entityTypeManager->hasDefinition($relatedType)) {
                continue;
            }

            $storage = $this->entityTypeManager->getStorage($relatedType);
            $resolvedId = ctype_digit($relatedId) ? (int) $relatedId : $relatedId;
            $entity = $storage->load($resolvedId);
            if ($entity === null) {
                continue;
            }

            $access = $this->accessHandler->check($entity, 'view', $this->account);
            if (!$access->isAllowed()) {
                continue;
            }

            $serialized = $this->serializer->serialize($entity, $this->accessHandler, $this->account)->toArray();
            $serialized['meta'] = [
                'via_relationship_id' => (string) $row['relationship']->id(),
                'via_relationship_type' => (string) ($row['relationship']->get('relationship_type') ?? ''),
                'direction' => $row['direction'],
                'inverse' => $row['inverse'],
            ];

            $resourceKey = $serialized['type'] . ':' . $serialized['id'];
            if (!isset($entitiesByKey[$resourceKey])) {
                $entitiesByKey[$resourceKey] = $serialized;
            }

            if ($includeRelationships) {
                $relationshipData[] = $this->serializer
                    ->serialize($row['relationship'], $this->accessHandler, $this->account)
                    ->toArray();
            }
        }

        $payload = [
            'data' => array_values($entitiesByKey),
            'meta' => [
                'filters' => [
                    'entity_type' => $parsed['entity_type'],
                    'entity_id' => $parsed['entity_id'],
                    'direction' => $parsed['direction'],
                    'status' => $parsed['status'],
                    'relationship_types' => $parsed['relationship_types'],
                    'at' => $parsed['at'],
                    'limit' => $parsed['limit'],
                ],
                'count' => count($entitiesByKey),
            ],
        ];

        if ($includeRelationships) {
            $payload['relationships'] = $relationshipData;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function toolGetKnowledgeGraph(array $arguments): array
    {
        $parsed = $this->parseTraversalArguments($arguments);

        if ($this->relationshipTraversal !== null) {
            $graph = $this->relationshipTraversal->browse($parsed['entity_type'], $parsed['entity_id'], [
                'relationship_types' => $parsed['relationship_types'],
                'status' => $parsed['status'],
                'at' => $parsed['at'],
                'limit' => $parsed['limit'],
            ]);
        } else {
            $rows = $this->collectTraversalRows($parsed + ['direction' => 'both']);
            $graph = [
                'source' => [
                    'type' => $parsed['entity_type'],
                    'id' => $parsed['entity_id'],
                ],
                'outbound' => [],
                'inbound' => [],
                'counts' => ['outbound' => 0, 'inbound' => 0, 'total' => 0],
            ];

            foreach ($rows as $row) {
                $edge = [
                    'relationship_id' => (string) $row['relationship']->id(),
                    'relationship_type' => (string) ($row['relationship']->get('relationship_type') ?? ''),
                    'direction' => $row['direction'],
                    'inverse' => (bool) ($row['inverse'] ?? false),
                    'directionality' => (string) ($row['relationship']->get('directionality') ?? 'directed'),
                    'related_entity_type' => $row['related_entity_type'],
                    'related_entity_id' => $row['related_entity_id'],
                    'related_entity_label' => $row['related_entity_type'] . ':' . $row['related_entity_id'],
                    'related_entity_path' => '/' . $row['related_entity_type'] . '/' . $row['related_entity_id'],
                    'status' => (int) ($row['relationship']->get('status') ?? 0),
                    'weight' => is_numeric($row['relationship']->get('weight')) ? (float) $row['relationship']->get('weight') : null,
                    'confidence' => is_numeric($row['relationship']->get('confidence')) ? (float) $row['relationship']->get('confidence') : null,
                    'start_date' => $this->normalizeTemporal($row['relationship']->get('start_date')),
                    'end_date' => $this->normalizeTemporal($row['relationship']->get('end_date')),
                ];

                if ($row['direction'] === 'inbound') {
                    $graph['inbound'][] = $edge;
                } else {
                    $graph['outbound'][] = $edge;
                }
            }

            $graph['counts']['outbound'] = count($graph['outbound']);
            $graph['counts']['inbound'] = count($graph['inbound']);
            $graph['counts']['total'] = $graph['counts']['outbound'] + $graph['counts']['inbound'];
        }

        return [
            'data' => $graph,
            'meta' => [
                'filters' => [
                    'entity_type' => $parsed['entity_type'],
                    'entity_id' => $parsed['entity_id'],
                    'status' => $parsed['status'],
                    'relationship_types' => $parsed['relationship_types'],
                    'at' => $parsed['at'],
                    'limit' => $parsed['limit'],
                ],
                'count' => (int) ($graph['counts']['total'] ?? 0),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{
     *   entity_type: string,
     *   entity_id: string,
     *   direction: string,
     *   status: string,
     *   relationship_types: list<string>,
     *   at: ?int,
     *   limit: int
     * }
     */
    private function parseTraversalArguments(array $arguments): array
    {
        $entityType = is_string($arguments['type'] ?? null) ? trim($arguments['type']) : '';
        $entityIdRaw = $arguments['id'] ?? null;
        $entityId = is_scalar($entityIdRaw) ? trim((string) $entityIdRaw) : '';
        if ($entityType === '' || $entityId === '') {
            throw new \InvalidArgumentException('Traversal requires non-empty "type" and "id" arguments.');
        }

        $direction = is_string($arguments['direction'] ?? null) ? strtolower(trim($arguments['direction'])) : 'both';
        if (!in_array($direction, ['outbound', 'inbound', 'both'], true)) {
            throw new \InvalidArgumentException('Traversal "direction" must be one of: outbound, inbound, both.');
        }

        $status = is_string($arguments['status'] ?? null) ? strtolower(trim($arguments['status'])) : 'published';
        if (!in_array($status, ['published', 'unpublished', 'all'], true)) {
            throw new \InvalidArgumentException('Traversal "status" must be one of: published, unpublished, all.');
        }

        $relationshipTypes = [];
        $rawRelationshipTypes = $arguments['relationship_types'] ?? [];
        if (is_array($rawRelationshipTypes)) {
            foreach ($rawRelationshipTypes as $value) {
                if (!is_string($value)) {
                    continue;
                }
                $normalized = trim(strtolower($value));
                if ($normalized === '') {
                    continue;
                }
                $relationshipTypes[] = $normalized;
            }
        }
        $relationshipTypes = array_values(array_unique($relationshipTypes));

        $at = null;
        if (array_key_exists('at', $arguments)) {
            $at = $this->normalizeTemporal($arguments['at']);
            if ($at === null) {
                throw new \InvalidArgumentException('Traversal "at" must be a unix timestamp or parseable datetime string.');
            }
        }

        $limit = is_numeric($arguments['limit'] ?? null) ? (int) $arguments['limit'] : 25;
        $limit = max(1, min(100, $limit));

        return [
            'entity_type' => strtolower($entityType),
            'entity_id' => $entityId,
            'direction' => $direction,
            'status' => $status,
            'relationship_types' => $relationshipTypes,
            'at' => $at,
            'limit' => $limit,
        ];
    }

    /**
     * @param array{
     *   entity_type: string,
     *   entity_id: string,
     *   direction: string,
     *   status: string,
     *   relationship_types: list<string>,
     *   at: ?int,
     *   limit: int
     * } $parsed
     * @return list<array{
     *   relationship: \Waaseyaa\Entity\EntityInterface,
     *   related_entity_type: string,
     *   related_entity_id: string,
     *   direction: string,
     *   inverse: bool
     * }>
     */
    private function collectTraversalRows(array $parsed): array
    {
        if (!$this->entityTypeManager->hasDefinition('relationship')) {
            return [];
        }

        $relationshipStorage = $this->entityTypeManager->getStorage('relationship');
        $ids = $relationshipStorage->getQuery()->accessCheck(false)->execute();
        $rows = [];
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
                $rows[] = [
                    'relationship' => $relationship,
                    'related_entity_type' => $toType,
                    'related_entity_id' => $toId,
                    'direction' => 'outbound',
                    'inverse' => false,
                ];
            }
            if (in_array($parsed['direction'], ['inbound', 'both'], true) && $matchesInbound) {
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
    private function isRelationshipActiveAt(array $relationshipValues, int $at): bool
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

    private function normalizeTemporal(mixed $value): ?int
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

        try {
            return (new \DateTimeImmutable((string) $value))->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function result(mixed $id, mixed $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function error(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }
}
