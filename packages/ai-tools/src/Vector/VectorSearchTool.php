<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Vector;

use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolContext;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;

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

    public function execute(array $arguments, AgentToolContext $context): AgentToolResult
    {
        $denied = $this->requireCapability('tool.vector.search', $context);
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

        return AgentToolResult::success(
            content: [['type' => 'json', 'data' => ['results' => is_array($results) ? $results : []]]],
            summary: sprintf('Vector search for "%s"', $query),
        );
    }

    public function dryRun(array $arguments, AgentToolContext $context): AgentToolResult
    {
        return $this->execute($arguments, $context);
    }
}
