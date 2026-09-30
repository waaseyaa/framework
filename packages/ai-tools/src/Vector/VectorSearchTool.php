<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Vector;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\EntityValues;

/**
 * Semantic search via {@see \Waaseyaa\AI\Vector\EmbeddingStorageInterface}
 * and {@see \Waaseyaa\AI\Vector\EmbeddingProviderInterface}.
 *
 * Both dependencies are resolved lazily from the kernel container at
 * the call site so this tool can declare a clean Layer-5 dependency
 * envelope without forcing waaseyaa/ai-vector on every consumer.
 *
 * @api
 */
#[AsAgentTool(
    name: 'vector.search',
    capability: 'tool.vector.search',
    destructive: false,
    dryRunSupported: true,
    category: 'vector',
)]
final class VectorSearchTool extends AbstractAgentTool
{
    /**
     * @param \Closure(): ?object $embeddingProviderResolver Returns EmbeddingProviderInterface|null
     * @param \Closure(): ?object $vectorStorageResolver Returns EmbeddingStorageInterface|null
     */
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly \Closure $embeddingProviderResolver,
        private readonly \Closure $vectorStorageResolver,
    ) {}

    public function description(): string
    {
        return 'Semantic vector search across embedded entities.';
    }

    public function inputSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'minLength' => 1],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                'entity_type' => ['type' => 'string'],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ];
    }

    /** @param \Waaseyaa\Access\AuthorizationPrincipalInterface $account */

    public function execute(array $arguments, AccountInterface $account): AgentToolResult
    {
        $denied = $this->requireCapability('tool.vector.search', $account);
        if ($denied !== null) {
            return $denied;
        }

        $query = $arguments['query'] ?? null;
        if (!is_string($query) || trim($query) === '') {
            return AgentToolResult::error('vector.search: missing required argument query.');
        }
        $query = trim($query);
        $limit = $arguments['limit'] ?? 10;
        $type = $arguments['entity_type'] ?? null;
        if (!is_int($limit) || $limit < 1 || $limit > 50
            || ($type !== null && (!is_string($type) || trim($type) === ''))
            || array_diff(array_keys($arguments), ['query', 'limit', 'entity_type']) !== []) {
            return AgentToolResult::error('vector.search: invalid arguments.');
        }

        // Use duck-typed calls so this stock tool does not import L5 siblings.
        try {
            $provider = ($this->embeddingProviderResolver)();
            $storage = ($this->vectorStorageResolver)();
            if ($provider === null || $storage === null) {
                return AgentToolResult::error(
                    message: 'vector_search_unavailable',
                    summary: 'Vector search requires waaseyaa/ai-vector with an EmbeddingProvider configured.',
                );
            }
            if (!method_exists($provider, 'embed') || !method_exists($storage, 'findSimilar')) {
                throw new \UnexpectedValueException('Invalid embedding storage contract.');
            }
            if ($type !== null && !$this->entityTypeManager->hasDefinition($type)) {
                return AgentToolResult::error('vector.search: unknown entity type.');
            }
            $embedding = $provider->embed($query);
            $types = $type !== null ? [$type] : array_keys($this->entityTypeManager->getDefinitions());
            $results = [];
            foreach ($types as $entityType) {
                $matches = $storage->findSimilar($embedding, $entityType, $limit);
                if (!is_array($matches) || !array_is_list($matches) || count($matches) > $limit) {
                    throw new \UnexpectedValueException('Invalid embedding result list.');
                }
                $seen = [];
                $previous = null;
                foreach ($matches as $match) {
                    if (!is_array($match) || count($match) !== 2
                        || !is_string($match['id'] ?? null) || $match['id'] === ''
                        || !is_float($match['score'] ?? null) || !is_finite($match['score'])
                        || $match['score'] < -1.0 || $match['score'] > 1.0
                        || isset($seen[$match['id']])) {
                        throw new \UnexpectedValueException('Invalid embedding result contract.');
                    }
                    if ($previous !== null && ($match['score'] > $previous['score']
                        || ($match['score'] === $previous['score'] && strcmp($match['id'], $previous['id']) < 0))) {
                        throw new \UnexpectedValueException('Invalid embedding result ordering.');
                    }
                    $seen[$match['id']] = true;
                    $previous = $match;
                    $results[] = ['entity_type' => $entityType, ...$match];
                }
            }
            usort($results, static function (array $a, array $b): int {
                $order = $b['score'] <=> $a['score'];
                if ($order !== 0) {
                    return $order;
                }
                $order = strcmp($a['entity_type'], $b['entity_type']);
                return $order !== 0 ? $order : strcmp($a['id'], $b['id']);
            });
            $filtered = array_slice($this->applyAccessGate($results, $account), 0, $limit);
        } catch (\Throwable $e) {
            return $this->internalError('vector.search', $e);
        }

        // MCP conformance (#2520): `json` is not an MCP content type.
        $data = ['results' => $filtered];

        try {
            $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            return $this->internalError('vector.search', $e);
        }

        return AgentToolResult::success(
            content: [['type' => 'text', 'text' => $json]],
            summary: sprintf('Vector search for "%s"', $query),
            structuredContent: $data,
        );
    }

    /**
     * Apply the per-entity `view` gate (and field-access filter) to each raw
     * similarity hit, so this tool never discloses an entity id / metadata for
     * an entity the initiating account may not view — the same contract
     * EntityReadTool/EntityListTool/EntitySearchTool honor, which this tool was
     * missing. A vector hit carries only a type+id (+ metadata), not a hydrated
     * entity, so the gate must first LOAD the backing entity before it can be
     * checked.
     *
     * Drop rules:
     *  - hit whose backing entity no longer loads: always dropped. Storage
     *    has no metadata to use as a fallback.
     *  - hit the account may not `view`: always dropped.
     * Surviving hits are reshaped to `{entity_type, id, score, metadata}` with
     * metadata run through {@see applyFieldAccessFilter()} — and the raw
     * embedding vector is no longer echoed back.
     *
     * @param list<array{entity_type: string, id: string, score: float}> $results
     * @return list<array{entity_type: string, id: string, score: float, metadata: object}>
     */
    /** @param \Waaseyaa\Access\AuthorizationPrincipalInterface $account */
    private function applyAccessGate(array $results, AccountInterface $account): array
    {
        $filtered = [];
        foreach ($results as $result) {
            $entityTypeId = $result['entity_type'];
            $entityId = $result['id'];
            $entity = $this->loadEntity($entityTypeId, (string) $entityId);
            if ($entity === null) {
                continue;
            }
            if (!$this->canViewEntity($entity, $account)) {
                continue;
            }
            // Choose the permitted ordinary field names before reading values.
            // Protected fields cannot be read and then filtered away.
            $fieldNames = EntityValues::ordinaryFieldNames($entity);
            $projection = $this->applyFieldAccessFilter($entity, array_fill_keys($fieldNames, true), $account);
            $permittedFields = array_values(array_filter($fieldNames, static fn(string $name): bool => array_key_exists($name, $projection)));
            $metadata = EntityValues::toCastAwareMap($entity, $permittedFields);

            $filtered[] = [
                'entity_type' => $entityTypeId,
                'id' => $entityId,
                'score' => $result['score'],
                'metadata' => (object) EntityValues::normalizeValueForJson($metadata),
            ];
        }

        return $filtered;
    }

    /**
     * Load the entity backing a hit. Returns null for an unknown type or a hit
     * that no longer resolves (the caller fails closed under enforcement).
     */
    private function loadEntity(string $entityTypeId, string $entityId): ?EntityInterface
    {
        if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
            return null;
        }
        return $this->entityTypeManager->getRepository($entityTypeId)->find($entityId);
    }

    /** @param \Waaseyaa\Access\AuthorizationPrincipalInterface $account */

    public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
    {
        return $this->execute($arguments, $account);
    }
}
