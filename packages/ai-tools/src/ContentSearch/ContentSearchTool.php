<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\ContentSearch;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Schema\ToolInputSchemaValidator;

/** @api */
final class ContentSearchTool extends AbstractAgentTool
{
    /** @param \Closure(): object $providerResolver */
    public function __construct(private readonly \Closure $providerResolver) {}

    public function description(): string
    {
        return 'Search accessible CMS content with ranked excerpts, metadata, and optional facets.';
    }

    public function inputSchema(): array
    {
        $boundedStrings = ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string', 'maxLength' => 128]];

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 256],
                'topics' => $boundedStrings,
                'content_type' => ['type' => 'string', 'maxLength' => 128],
                'sources' => $boundedStrings,
                'min_quality' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                'sort_field' => ['type' => 'string', 'enum' => ['relevance', 'created_at', 'quality_score', 'entity_type', 'content_type']],
                'sort_order' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
                'page_size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                'include_facets' => ['type' => 'boolean'],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ];
    }

    public static function outputSchema(): array
    {
        $hit = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'maxLength' => 256],
                'title' => ['type' => 'string', 'maxLength' => 512],
                'url' => ['type' => 'string', 'maxLength' => 2048],
                'highlight' => ['type' => 'string', 'maxLength' => 4096],
                'score' => ['type' => 'number'],
                'source' => ['type' => 'string', 'maxLength' => 128],
                'content_type' => ['type' => 'string', 'maxLength' => 128],
                'quality_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                'crawled_at' => ['type' => 'string', 'maxLength' => 64],
                'topics' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string', 'maxLength' => 128]],
                'image' => ['type' => 'string', 'maxLength' => 2048],
            ],
            'required' => ['id', 'title', 'url', 'highlight', 'score', 'source', 'content_type', 'quality_score', 'crawled_at', 'topics', 'image'],
            'additionalProperties' => false,
        ];
        $bucket = [
            'type' => 'object',
            'properties' => ['key' => ['type' => 'string', 'maxLength' => 128], 'count' => ['type' => 'integer', 'minimum' => 0]],
            'required' => ['key', 'count'],
            'additionalProperties' => false,
        ];
        $facet = [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'maxLength' => 128],
                'buckets' => ['type' => 'array', 'maxItems' => 100, 'items' => $bucket],
            ],
            'required' => ['name', 'buckets'],
            'additionalProperties' => false,
        ];

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'total_hits' => ['type' => 'integer', 'minimum' => 0],
                'total_pages' => ['type' => 'integer', 'minimum' => 0],
                'current_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
                'page_size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                'is_complete' => ['type' => 'boolean'],
                'hits' => ['type' => 'array', 'maxItems' => 100, 'items' => $hit],
                'facets' => ['type' => 'array', 'maxItems' => 20, 'items' => $facet],
            ],
            'required' => ['total_hits', 'total_pages', 'current_page', 'page_size', 'is_complete', 'hits', 'facets'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments, AuthorizationPrincipalInterface $account): AgentToolResult
    {
        $denied = $this->requireCapability('tool.content.search', $account);
        if ($denied !== null) {
            return $denied;
        }

        if (ToolInputSchemaValidator::validate($this->inputSchema(), $arguments) !== [] || !$this->inputIsSafe($arguments)) {
            return AgentToolResult::error(
                'content.search: arguments do not satisfy the declared input schema.',
                'validation_failed',
            );
        }

        try {
            $provider = ($this->providerResolver)();
        } catch (\Throwable $e) {
            return $this->unavailableError('content.search', $e);
        }

        try {
            $data = new SearchPackageContentSearchAdapter($provider)->search($arguments, $account);
            $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return $this->internalError('content.search', $e);
        }

        return AgentToolResult::success(
            content: [['type' => 'text', 'text' => $json]],
            summary: sprintf('Found %d accessible content results.', $data['total_hits']),
            structuredContent: $data,
        );
    }

    public function argumentsForAudit(array $arguments): array
    {
        $safe = $arguments;
        $query = $safe['query'] ?? null;
        $safe['query'] = [
            'redacted' => true,
            'length' => is_string($query) && mb_check_encoding($query, 'UTF-8') ? mb_strlen($query, 'UTF-8') : null,
        ];
        foreach (['topics', 'sources'] as $key) {
            if (\array_key_exists($key, $safe)) {
                $safe[$key] = [
                    'redacted' => true,
                    'count' => \is_array($safe[$key]) ? \count($safe[$key]) : null,
                ];
            }
        }
        if (\array_key_exists('content_type', $safe)) {
            $contentType = $safe['content_type'];
            $safe['content_type'] = [
                'redacted' => true,
                'length' => \is_string($contentType) && \mb_check_encoding($contentType, 'UTF-8')
                    ? \mb_strlen($contentType, 'UTF-8')
                    : null,
            ];
        }

        return $safe;
    }

    /** @param array<string, mixed> $arguments */
    private function inputIsSafe(array $arguments): bool
    {
        $query = $arguments['query'] ?? null;
        if (!\is_string($query)
            || !\mb_check_encoding($query, 'UTF-8')
            || \preg_match('/[\x00-\x1F\x7F]/u', $query) === 1
        ) {
            return false;
        }

        $page = $arguments['page'] ?? 1;
        $pageSize = $arguments['page_size'] ?? 20;

        return \is_int($page) && \is_int($pageSize) && $page * $pageSize <= 1_000;
    }
}
