<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Cache;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Cache\CacheBackendInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

final class ReadCache
{
    private const string CONTRACT_VERSION = 'v1.0';
    private const int MAX_AGE = 120;
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly AccountInterface $account,
        private readonly ?CacheBackendInterface $backend = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function buildKeyForTool(string $tool, array $arguments): ?string
    {
        if ($this->backend === null || !$this->isCacheableTool($tool)) {
            return null;
        }

        $keyPayload = [
            'contract_version' => self::CONTRACT_VERSION,
            'tool' => $tool,
            'arguments' => $this->normalizeForCacheKey($arguments),
            'account' => $this->accountContext(),
        ];

        try {
            $serialized = json_encode($keyPayload, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return 'mcp_read:v1:' . hash('sha256', $serialized);
    }

    public function isCacheableTool(string $tool): bool
    {
        return in_array($tool, [
            'search_entities',
            'ai_discover',
            'traverse_relationships',
            'get_related_entities',
            'get_knowledge_graph',
        ], true);
    }

    /**
     * @return array{
     *   authenticated: bool,
     *   account_id: string,
     *   roles: list<string>
     * }
     */
    public function accountContext(): array
    {
        $roles = array_values(array_unique(array_map(
            static fn(string $role): string => strtolower(trim($role)),
            $this->account->getRoles(),
        )));
        sort($roles);

        return [
            'authenticated' => $this->account->isAuthenticated(),
            'account_id' => (string) $this->account->id(),
            'roles' => $roles,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $cacheKey): ?array
    {
        if ($this->backend === null) {
            return null;
        }

        $item = $this->backend->get($cacheKey);
        if ($item === false || !$item->valid || !is_array($item->data)) {
            return null;
        }

        return $item->data;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $result
     */
    public function set(string $cacheKey, string $tool, array $arguments, array $result): void
    {
        if ($this->backend === null) {
            return;
        }

        $expire = time() + self::MAX_AGE;
        $tags = $this->buildTags($tool, $arguments, $result);

        try {
            $this->backend->set($cacheKey, $result, $expire, $tags);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('Failed to write MCP read cache: %s', $e->getMessage()));
        }
    }

    public function isEnabled(): bool
    {
        return $this->backend !== null;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $result
     * @return list<string>
     */
    private function buildTags(string $tool, array $arguments, array $result): array
    {
        $tags = [
            'mcp_read',
            'mcp_read:contract:' . self::CONTRACT_VERSION,
            'mcp_read:tool:' . strtolower($tool),
            $this->account->isAuthenticated() ? 'mcp_read:scope:authenticated' : 'mcp_read:scope:anonymous',
        ];

        $sourceType = is_string($arguments['type'] ?? null) ? strtolower(trim($arguments['type'])) : '';
        $sourceId = is_scalar($arguments['id'] ?? null) ? trim((string) $arguments['id']) : '';
        if ($sourceType !== '' && $sourceId !== '') {
            $this->appendEntityTags($tags, $sourceType, $sourceId);
        }

        $anchorType = is_string($arguments['anchor_type'] ?? null) ? strtolower(trim($arguments['anchor_type'])) : '';
        $anchorId = is_scalar($arguments['anchor_id'] ?? null) ? trim((string) $arguments['anchor_id']) : '';
        if ($anchorType !== '' && $anchorId !== '') {
            $this->appendEntityTags($tags, $anchorType, $anchorId);
        }

        $this->collectEntityTagsFromPayload($result, $tags);

        return array_values(array_unique($tags));
    }

    /**
     * @param list<string> $tags
     */
    private function appendEntityTags(array &$tags, string $entityType, string $entityId): void
    {
        $tags[] = 'mcp_read:entity:' . $entityType;
        $tags[] = 'mcp_read:entity:' . $entityType . ':' . $entityId;
    }

    /**
     * @param list<string> $tags
     */
    private function collectEntityTagsFromPayload(mixed $value, array &$tags): void
    {
        if (!is_array($value)) {
            return;
        }

        $type = is_string($value['type'] ?? null) ? strtolower(trim($value['type'])) : '';
        $id = is_scalar($value['id'] ?? null) ? trim((string) $value['id']) : '';
        if ($type !== '' && $id !== '') {
            $this->appendEntityTags($tags, $type, $id);
        }

        $relatedType = is_string($value['related_entity_type'] ?? null) ? strtolower(trim($value['related_entity_type'])) : '';
        $relatedId = is_scalar($value['related_entity_id'] ?? null) ? trim((string) $value['related_entity_id']) : '';
        if ($relatedType !== '' && $relatedId !== '') {
            $this->appendEntityTags($tags, $relatedType, $relatedId);
        }

        foreach ($value as $item) {
            $this->collectEntityTagsFromPayload($item, $tags);
        }
    }

    private function normalizeForCacheKey(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->normalizeForCacheKey($item), $value);
        }

        $normalized = [];
        ksort($value);
        foreach ($value as $key => $item) {
            $normalized[$key] = $this->normalizeForCacheKey($item);
        }

        return $normalized;
    }
}
