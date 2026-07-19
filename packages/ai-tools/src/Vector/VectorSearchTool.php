<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Vector;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Semantic search via {@see \Waaseyaa\AI\Vector\VectorStoreInterface}
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
        if (!is_string($query) || $query === '') {
            return AgentToolResult::error('vector.search: missing required argument query.');
        }

        $provider = ($this->embeddingProviderResolver)();
        $storage = ($this->vectorStorageResolver)();
        if ($provider === null || $storage === null) {
            return AgentToolResult::error(
                message: 'vector_search_unavailable',
                summary: 'Vector search requires waaseyaa/ai-vector with an EmbeddingProvider configured.',
            );
        }

        // Use duck-typed calls so this stock tool does not import L5 siblings.
        try {
            if (!method_exists($provider, 'embed') || !method_exists($storage, 'search')) {
                return AgentToolResult::error('vector.search: configured embedding/storage do not expose embed()/search().');
            }
            $embedding = $provider->embed($query);
            $limit = isset($arguments['limit']) && is_int($arguments['limit']) ? $arguments['limit'] : 10;
            $results = $storage->search($embedding, $limit);
        } catch (\Throwable $e) {
            return AgentToolResult::error(sprintf('vector.search: %s', $e->getMessage()));
        }

        $filtered = $this->applyAccessGate(is_array($results) ? $results : [], $account);

        return AgentToolResult::success(
            content: [['type' => 'json', 'data' => ['results' => $filtered]]],
            summary: sprintf('Vector search for "%s"', $query),
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
     *  - hit whose backing (type,id) cannot be determined, or whose entity no
     *    longer loads (stale embedding): dropped under enforcement (a hit that
     *    cannot be gated must never be disclosed); in capability-only mode the
     *    raw hit is preserved unchanged (no gate was ever applied there).
     *  - hit the account may not `view`: always dropped.
     * Surviving hits are reshaped to `{entity_type, id, score, metadata}` with
     * metadata run through {@see applyFieldAccessFilter()} — and the raw
     * embedding vector is no longer echoed back.
     *
     * @param array<int, mixed> $results
     * @return list<array<string, mixed>|object>
     */
    /** @param \Waaseyaa\Access\AuthorizationPrincipalInterface $account */
    private function applyAccessGate(array $results, AccountInterface $account): array
    {
        $enforced = $this->isAccessEnforced();
        $filtered = [];
        foreach ($results as $result) {
            $ref = $this->describeResult($result);
            if ($ref === null) {
                if ($enforced) {
                    continue;
                }
                $filtered[] = $result;
                continue;
            }
            [$entityTypeId, $entityId, $metadata, $score] = $ref;

            $entity = $this->loadEntity($entityTypeId, (string) $entityId);
            if ($entity === null) {
                if ($enforced) {
                    continue;
                }
                $filtered[] = $result;
                continue;
            }
            if (!$this->canViewEntity($entity, $account)) {
                continue;
            }

            $filtered[] = [
                'entity_type' => $entityTypeId,
                'id' => $entityId,
                'score' => $score,
                'metadata' => $this->applyFieldAccessFilter($entity, $metadata, $account),
            ];
        }

        return $filtered;
    }

    /**
     * Duck-type the (entityTypeId, entityId, metadata, score) out of a similarity
     * hit without importing the L5 `SimilarityResult`/`EntityEmbedding` value
     * objects (this tool keeps a clean Layer-5 dependency envelope). Returns null
     * when the hit's backing entity type+id cannot be determined.
     *
     * @return array{0: string, 1: int|string, 2: array<string, mixed>, 3: float}|null
     */
    private function describeResult(mixed $result): ?array
    {
        if (!is_object($result) || !isset($result->embedding) || !is_object($result->embedding)) {
            return null;
        }
        $embedding = $result->embedding;
        $type = $embedding->entityTypeId ?? null;
        $id = $embedding->entityId ?? null;
        if (!is_string($type) || $type === '' || (!is_string($id) && !is_int($id)) || $id === '') {
            return null;
        }
        $metadata = isset($embedding->metadata) && is_array($embedding->metadata) ? $embedding->metadata : [];
        $score = (isset($result->score) && (is_float($result->score) || is_int($result->score))) ? (float) $result->score : 0.0;

        return [$type, $id, $metadata, $score];
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
        try {
            return $this->entityTypeManager->getRepository($entityTypeId)->find($entityId);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param \Waaseyaa\Access\AuthorizationPrincipalInterface $account */

    public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
    {
        return $this->execute($arguments, $account);
    }
}
