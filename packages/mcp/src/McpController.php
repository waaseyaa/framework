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
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\FieldableInterface;
use Waaseyaa\Relationship\RelationshipTraversalService;
use Waaseyaa\Workflows\EditorialTransitionAccessResolver;
use Waaseyaa\Workflows\EditorialWorkflowService;
use Waaseyaa\Workflows\EditorialWorkflowStateMachine;

final class McpController
{
    private const string CONTRACT_VERSION = 'v1.0';
    private const string CONTRACT_STABILITY = 'stable';

    private readonly EditorialWorkflowStateMachine $editorialStateMachine;
    private readonly EditorialTransitionAccessResolver $editorialTransitionResolver;

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly ResourceSerializer $serializer,
        private readonly EntityAccessHandler $accessHandler,
        private readonly AccountInterface $account,
        private readonly EmbeddingStorageInterface $embeddingStorage,
        private readonly ?EmbeddingProviderInterface $embeddingProvider = null,
        private readonly ?RelationshipTraversalService $relationshipTraversal = null,
    ) {
        $this->editorialStateMachine = new EditorialWorkflowStateMachine();
        $this->editorialTransitionResolver = new EditorialTransitionAccessResolver($this->editorialStateMachine);
    }

    /**
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        return [
            'protocolVersion' => '2024-11-05',
            'server' => [
                'name' => 'Waaseyaa MCP',
                'version' => self::CONTRACT_VERSION,
            ],
            'tools' => [
                ['name' => 'search_entities', 'description' => 'Stable semantic/keyword search contract for entities'],
                ['name' => 'search_teachings', 'description' => 'Deprecated alias of search_entities (kept for backward compatibility)'],
                ['name' => 'ai_discover', 'description' => 'Blend semantic search and graph context for deterministic discovery recommendations'],
                ['name' => 'get_entity', 'description' => 'Fetch a single entity by type and ID'],
                ['name' => 'list_entity_types', 'description' => 'List available entity types and schemas'],
                ['name' => 'traverse_relationships', 'description' => 'Traverse relationship entities for a source entity with direction/type/temporal filters'],
                ['name' => 'get_related_entities', 'description' => 'Resolve related entities via relationship traversal with optional edge payloads'],
                ['name' => 'get_knowledge_graph', 'description' => 'Return directional relationship graph surface for an entity'],
                ['name' => 'editorial_transition', 'description' => 'Apply an editorial workflow transition to a node entity'],
                ['name' => 'editorial_validate', 'description' => 'Validate editorial workflow transition eligibility without mutating state'],
                ['name' => 'editorial_publish', 'description' => 'Publish a node through editorial workflow rules'],
                ['name' => 'editorial_archive', 'description' => 'Archive a node through editorial workflow rules'],
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
                'search_entities' => $this->toolSearchEntities($arguments),
                'search_teachings' => $this->toolSearchTeachings($arguments),
                'ai_discover' => $this->toolAiDiscover($arguments),
                'get_entity' => $this->toolGetEntity($arguments),
                'list_entity_types' => $this->toolListEntityTypes(),
                'traverse_relationships' => $this->toolTraverseRelationships($arguments),
                'get_related_entities' => $this->toolGetRelatedEntities($arguments),
                'get_knowledge_graph' => $this->toolGetKnowledgeGraph($arguments),
                'editorial_transition' => $this->toolEditorialTransition($arguments),
                'editorial_validate' => $this->toolEditorialValidate($arguments),
                'editorial_publish' => $this->toolEditorialPublish($arguments),
                'editorial_archive' => $this->toolEditorialArchive($arguments),
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
        $result = $this->withStableContractMeta($result, $tool);

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
        $result = $this->toolSearchEntities($arguments);
        $result['meta']['deprecated_alias'] = 'search_teachings';

        return $result;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function toolSearchEntities(array $arguments): array
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

        $result = $controller->search($query, $entityType, $limit)->toArray();
        if (!isset($result['meta']) || !is_array($result['meta'])) {
            $result['meta'] = [];
        }
        $result['meta']['tool'] = 'search_entities';

        return $result;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function toolAiDiscover(array $arguments): array
    {
        $query = is_string($arguments['query'] ?? null) ? trim($arguments['query']) : '';
        if ($query === '') {
            throw new \InvalidArgumentException('AI discovery requires a non-empty "query" argument.');
        }

        $entityType = is_string($arguments['type'] ?? null) ? trim($arguments['type']) : 'node';
        if ($entityType === '') {
            throw new \InvalidArgumentException('AI discovery requires a non-empty "type" argument.');
        }

        $limit = is_numeric($arguments['limit'] ?? null) ? (int) $arguments['limit'] : 10;
        $limit = max(1, min(100, $limit));

        $controller = new SearchController(
            entityTypeManager: $this->entityTypeManager,
            serializer: $this->serializer,
            embeddingStorage: $this->embeddingStorage,
            embeddingProvider: $this->embeddingProvider,
            accessHandler: $this->accessHandler,
            account: $this->account,
        );

        $search = $controller->search($query, $entityType, $limit)->toArray();
        if (isset($search['errors'])) {
            $detail = is_string($search['errors'][0]['detail'] ?? null)
                ? $search['errors'][0]['detail']
                : 'AI discovery search failed.';
            throw new \InvalidArgumentException($detail);
        }

        $searchMeta = is_array($search['meta'] ?? null) ? $search['meta'] : [];
        $scoreBreakdown = is_array($searchMeta['score_breakdown'] ?? null) ? $searchMeta['score_breakdown'] : [];
        $breakdownByBaseRank = $this->sortScoreBreakdownByBaseRank($scoreBreakdown);
        $recommendations = [];
        $resultRows = is_array($search['data'] ?? null) ? $search['data'] : [];

        foreach ($resultRows as $index => $resource) {
            if (!is_array($resource)) {
                continue;
            }

            $entityId = is_string($resource['id'] ?? null) ? $resource['id'] : '';
            $breakdown = is_array($scoreBreakdown[$entityId] ?? null)
                ? $scoreBreakdown[$entityId]
                : ($breakdownByBaseRank[$index] ?? null);

            $recommendations[] = [
                'entity' => $resource,
                'explanation' => [
                    'semantic_score' => is_numeric($breakdown['semantic'] ?? null) ? (float) $breakdown['semantic'] : null,
                    'graph_context_score' => is_numeric($breakdown['graph_context'] ?? null) ? (float) $breakdown['graph_context'] : null,
                    'combined_score' => is_numeric($breakdown['combined'] ?? null) ? (float) $breakdown['combined'] : null,
                    'base_rank' => is_numeric($breakdown['base_rank'] ?? null) ? (int) $breakdown['base_rank'] : (int) $index,
                    'visibility_contract' => 'published_only',
                ],
            ];
        }

        $anchor = $this->resolveDiscoveryAnchor($arguments);
        $graphContext = $anchor === null ? null : $this->discoveryGraphContext($anchor['type'], $anchor['id']);

        return [
            'data' => [
                'recommendations' => $recommendations,
                'graph_context' => $graphContext,
            ],
            'meta' => [
                'tool' => 'ai_discover',
                'query' => $query,
                'type' => $entityType,
                'mode' => is_string($searchMeta['mode'] ?? null) ? $searchMeta['mode'] : 'unknown',
                'requested_limit' => $limit,
                'count' => count($recommendations),
                'anchor' => $anchor,
            ],
        ];
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
        $this->assertTraversalSourceVisible($parsed['entity_type'], $parsed['entity_id']);
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
        $this->assertTraversalSourceVisible($parsed['entity_type'], $parsed['entity_id']);
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
        $this->assertTraversalSourceVisible($parsed['entity_type'], $parsed['entity_id']);

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
     * @return array<string, mixed>
     */
    private function toolEditorialTransition(array $arguments): array
    {
        $targetState = $this->requiredStateArgument($arguments, 'to_state');
        $resolved = $this->loadEditorialNode($arguments);
        $validation = $this->editorialValidationResult($resolved['entity'], $resolved['bundle'], $targetState);
        if (!$validation['is_valid']) {
            throw new \RuntimeException($validation['violations'][0] ?? 'Editorial transition validation failed.');
        }

        $service = $this->editorialWorkflowServiceForBundle($resolved['bundle']);
        $service->transitionNode($resolved['entity'], $targetState, $this->account);
        $resolved['storage']->save($resolved['entity']);

        return [
            'data' => $this->editorialNodeSnapshot(
                $resolved['entity'],
                $resolved['bundle'],
                $service->getAvailableTransitionMetadata($resolved['entity']),
            ),
            'meta' => [
                'tool' => 'editorial_transition',
                'requested_state' => $targetState,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function toolEditorialValidate(array $arguments): array
    {
        $resolved = $this->loadEditorialNode($arguments);
        $requestedState = null;
        if (array_key_exists('to_state', $arguments)) {
            $requestedState = $this->requiredStateArgument($arguments, 'to_state');
        }

        $validation = $this->editorialValidationResult($resolved['entity'], $resolved['bundle'], $requestedState);
        $service = $this->editorialWorkflowServiceForBundle($resolved['bundle']);

        return [
            'data' => $this->editorialNodeSnapshot(
                $resolved['entity'],
                $resolved['bundle'],
                $service->getAvailableTransitionMetadata($resolved['entity']),
            ) + [
                'requested_state' => $requestedState,
                'is_valid' => $validation['is_valid'],
                'violations' => $validation['violations'],
            ],
            'meta' => [
                'tool' => 'editorial_validate',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function toolEditorialPublish(array $arguments): array
    {
        $arguments['to_state'] = EditorialWorkflowStateMachine::STATE_PUBLISHED;

        return $this->toolEditorialTransition($arguments);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function toolEditorialArchive(array $arguments): array
    {
        $arguments['to_state'] = EditorialWorkflowStateMachine::STATE_ARCHIVED;

        return $this->toolEditorialTransition($arguments);
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
        $entityType = strtolower($entityType);
        if (!$this->entityTypeManager->hasDefinition($entityType)) {
            throw new \InvalidArgumentException(sprintf('Unknown traversal entity type: "%s".', $entityType));
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
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'direction' => $direction,
            'status' => $status,
            'relationship_types' => $relationshipTypes,
            'at' => $at,
            'limit' => $limit,
        ];
    }

    private function requiredStateArgument(array $arguments, string $name): string
    {
        $state = is_string($arguments[$name] ?? null) ? strtolower(trim($arguments[$name])) : '';
        if ($state === '') {
            throw new \InvalidArgumentException(sprintf('Editorial tool requires non-empty "%s".', $name));
        }
        if (!$this->editorialStateMachine->isKnownState($state)) {
            throw new \InvalidArgumentException(sprintf('Unknown editorial workflow state: "%s".', $state));
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{
     *   entity: EntityInterface&FieldableInterface,
     *   storage: \Waaseyaa\Entity\Storage\EntityStorageInterface,
     *   bundle: string
     * }
     */
    private function loadEditorialNode(array $arguments): array
    {
        $entityType = is_string($arguments['type'] ?? null) ? strtolower(trim($arguments['type'])) : '';
        if ($entityType === '') {
            throw new \InvalidArgumentException('Editorial tools require non-empty "type".');
        }
        if ($entityType !== 'node') {
            throw new \InvalidArgumentException('Editorial tools only support "node" entities.');
        }
        if (!$this->entityTypeManager->hasDefinition($entityType)) {
            throw new \InvalidArgumentException(sprintf('Unknown entity type: "%s".', $entityType));
        }

        $idRaw = $arguments['id'] ?? null;
        if (!is_scalar($idRaw) || trim((string) $idRaw) === '') {
            throw new \InvalidArgumentException('Editorial tools require non-empty "id".');
        }
        $resolvedId = ctype_digit((string) $idRaw) ? (int) $idRaw : (string) $idRaw;

        $storage = $this->entityTypeManager->getStorage($entityType);
        $entity = $storage->load($resolvedId);
        if ($entity === null) {
            throw new \InvalidArgumentException(sprintf('Entity not found: %s:%s', $entityType, (string) $idRaw));
        }
        if (!$entity instanceof FieldableInterface) {
            throw new \RuntimeException(sprintf('Entity %s:%s is not fieldable.', $entityType, (string) $idRaw));
        }

        $bundle = strtolower(trim((string) ($entity->bundle() !== '' ? $entity->bundle() : $entity->get('type'))));
        if ($bundle === '') {
            throw new \RuntimeException('Editorial workflow requires a non-empty node bundle.');
        }

        return [
            'entity' => $entity,
            'storage' => $storage,
            'bundle' => $bundle,
        ];
    }

    /**
     * @param EntityInterface&FieldableInterface $entity
     * @return array{is_valid: bool, violations: list<string>}
     */
    private function editorialValidationResult(FieldableInterface $entity, string $bundle, ?string $targetState): array
    {
        $violations = [];

        $updateAccess = $this->accessHandler->check($entity, 'update', $this->account);
        if (!$updateAccess->isAllowed()) {
            $violations[] = $updateAccess->reason !== ''
                ? $updateAccess->reason
                : 'Update access denied for editorial operation.';
        }

        $currentState = $this->editorialStateMachine->normalizeState(
            workflowState: $entity->get('workflow_state'),
            status: $entity->get('status'),
        );
        if (!$this->editorialStateMachine->isKnownState($currentState)) {
            $violations[] = sprintf('Unknown current workflow state: "%s".', $currentState);
        }

        if ($targetState !== null) {
            $transitionAccess = $this->editorialTransitionResolver->canTransition($bundle, $currentState, $targetState, $this->account);
            if (!$transitionAccess->isAllowed()) {
                $violations[] = $transitionAccess->reason !== ''
                    ? $transitionAccess->reason
                    : sprintf('Workflow transition "%s" -> "%s" is not authorized.', $currentState, $targetState);
            }
        }

        return [
            'is_valid' => $violations === [],
            'violations' => $violations,
        ];
    }

    /**
     * @param EntityInterface&FieldableInterface $entity
     * @param list<array{id: string, label: string, from: list<string>, to: string, required_permission: string}> $availableTransitions
     * @return array<string, mixed>
     */
    private function editorialNodeSnapshot(FieldableInterface $entity, string $bundle, array $availableTransitions): array
    {
        return [
            'type' => $entity->getEntityTypeId(),
            'id' => (string) $entity->id(),
            'bundle' => $bundle,
            'workflow_state' => $this->editorialStateMachine->normalizeState(
                workflowState: $entity->get('workflow_state'),
                status: $entity->get('status'),
            ),
            'status' => (int) ($entity->get('status') ?? 0),
            'workflow_last_transition' => $entity->get('workflow_last_transition'),
            'available_transitions' => $availableTransitions,
        ];
    }

    private function editorialWorkflowServiceForBundle(string $bundle): EditorialWorkflowService
    {
        return new EditorialWorkflowService(
            coreBundles: [$bundle],
            stateMachine: $this->editorialStateMachine,
            transitionAccessResolver: $this->editorialTransitionResolver,
        );
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
     * @param array<string, array{semantic?: float, graph_context?: float, combined?: float, base_rank?: int}> $scoreBreakdown
     * @return list<array{semantic?: float, graph_context?: float, combined?: float, base_rank?: int}>
     */
    private function sortScoreBreakdownByBaseRank(array $scoreBreakdown): array
    {
        $rows = array_values($scoreBreakdown);
        usort($rows, static function (array $a, array $b): int {
            $aRank = is_numeric($a['base_rank'] ?? null) ? (int) $a['base_rank'] : PHP_INT_MAX;
            $bRank = is_numeric($b['base_rank'] ?? null) ? (int) $b['base_rank'] : PHP_INT_MAX;
            return $aRank <=> $bRank;
        });

        return $rows;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{type: string, id: string}|null
     */
    private function resolveDiscoveryAnchor(array $arguments): ?array
    {
        $anchorType = is_string($arguments['anchor_type'] ?? null) ? strtolower(trim($arguments['anchor_type'])) : '';
        $anchorIdRaw = $arguments['anchor_id'] ?? null;
        $anchorId = is_scalar($anchorIdRaw) ? trim((string) $anchorIdRaw) : '';

        if ($anchorType === '' && $anchorId === '') {
            return null;
        }
        if ($anchorType === '' || $anchorId === '') {
            throw new \InvalidArgumentException('AI discovery anchor requires both non-empty "anchor_type" and "anchor_id".');
        }
        if (!$this->entityTypeManager->hasDefinition($anchorType)) {
            throw new \InvalidArgumentException(sprintf('Unknown anchor entity type: "%s".', $anchorType));
        }

        $entity = $this->loadEntityByTypeAndId($anchorType, $anchorId);
        if (!$entity instanceof EntityInterface) {
            throw new \InvalidArgumentException(sprintf('Anchor entity not found: %s:%s', $anchorType, $anchorId));
        }
        if (!$this->accessHandler->check($entity, 'view', $this->account)->isAllowed()) {
            throw new \RuntimeException(sprintf('Anchor entity is not visible: %s:%s', $anchorType, $anchorId));
        }
        if ($anchorType === 'node' && !$this->isNodePublicForDiscovery($entity)) {
            throw new \RuntimeException(sprintf('Anchor entity is not public: %s:%s', $anchorType, $anchorId));
        }

        return [
            'type' => $anchorType,
            'id' => $anchorId,
        ];
    }

    /**
     * @return array{
     *   source: array{type: string, id: string},
     *   counts: array{outbound: int, inbound: int, total: int},
     *   relationship_types: array<string, int>
     * }
     */
    private function discoveryGraphContext(string $anchorType, string $anchorId): array
    {
        $rows = $this->collectTraversalRows([
            'entity_type' => $anchorType,
            'entity_id' => $anchorId,
            'direction' => 'both',
            'status' => 'published',
            'relationship_types' => [],
            'at' => null,
            'limit' => 100,
        ]);

        $counts = ['outbound' => 0, 'inbound' => 0, 'total' => 0];
        $types = [];
        foreach ($rows as $row) {
            $direction = $row['direction'] === 'inbound' ? 'inbound' : 'outbound';
            $counts[$direction]++;
            $counts['total']++;
            $relationshipType = strtolower((string) ($row['relationship']->get('relationship_type') ?? ''));
            if ($relationshipType === '') {
                continue;
            }
            $types[$relationshipType] = ($types[$relationshipType] ?? 0) + 1;
        }
        ksort($types);

        return [
            'source' => [
                'type' => $anchorType,
                'id' => $anchorId,
            ],
            'counts' => $counts,
            'relationship_types' => $types,
        ];
    }

    private function isNodePublicForDiscovery(EntityInterface $entity): bool
    {
        $values = $entity->toArray();
        $workflowState = is_string($values['workflow_state'] ?? null)
            ? strtolower(trim((string) $values['workflow_state']))
            : '';
        if ($workflowState !== '') {
            return $workflowState === 'published';
        }

        $status = $values['status'] ?? 0;
        if (is_bool($status)) {
            return $status;
        }
        if (is_numeric($status)) {
            return (int) $status === 1;
        }
        if (is_string($status)) {
            return in_array(strtolower(trim($status)), ['1', 'true', 'published'], true);
        }

        return false;
    }

    private function assertTraversalSourceVisible(string $entityType, string $entityId): void
    {
        $entity = $this->loadEntityByTypeAndId($entityType, $entityId);
        if (!$entity instanceof EntityInterface) {
            throw new \InvalidArgumentException(sprintf('Traversal source entity not found: %s:%s', $entityType, $entityId));
        }

        if (!$this->accessHandler->check($entity, 'view', $this->account)->isAllowed()) {
            throw new \RuntimeException(sprintf('Traversal source entity is not visible: %s:%s', $entityType, $entityId));
        }
    }

    private function loadEntityByTypeAndId(string $entityType, string $entityId): ?EntityInterface
    {
        if (!$this->entityTypeManager->hasDefinition($entityType)) {
            return null;
        }

        $storage = $this->entityTypeManager->getStorage($entityType);
        $resolvedId = ctype_digit($entityId) ? (int) $entityId : $entityId;
        $entity = $storage->load($resolvedId);

        return $entity instanceof EntityInterface ? $entity : null;
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

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function withStableContractMeta(array $result, string $invokedTool): array
    {
        if (!isset($result['meta']) || !is_array($result['meta'])) {
            $result['meta'] = [];
        }

        $canonicalTool = $invokedTool === 'search_teachings' ? 'search_entities' : $invokedTool;
        $result['meta']['contract_version'] = self::CONTRACT_VERSION;
        $result['meta']['contract_stability'] = self::CONTRACT_STABILITY;
        $result['meta']['tool_invoked'] = $invokedTool;
        if (!is_string($result['meta']['tool'] ?? null) || trim($result['meta']['tool']) === '') {
            $result['meta']['tool'] = $canonicalTool;
        }

        return $result;
    }
}
