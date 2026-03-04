<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Support;

/**
 * Deterministic workflow + discovery fixture corpus for v0.8/v0.9 tests.
 */
final class WorkflowFixturePack
{
    public const int FIXED_TIMESTAMP = 1735689600; // 2025-01-01T00:00:00Z

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function editorialNodesForSsr(): array
    {
        return [
            'published_water' => [
                'title' => 'Water Is Life',
                'type' => 'article',
                'uid' => 7,
                'created' => self::FIXED_TIMESTAMP,
                'changed' => self::FIXED_TIMESTAMP,
                'status' => 1,
                'workflow_state' => 'published',
            ],
            'draft_story' => [
                'title' => 'Draft Node',
                'type' => 'article',
                'uid' => 7,
                'created' => self::FIXED_TIMESTAMP,
                'changed' => self::FIXED_TIMESTAMP,
                'status' => 0,
                'workflow_state' => 'draft',
            ],
            'review_story' => [
                'title' => 'Review Node',
                'type' => 'article',
                'uid' => 7,
                'created' => self::FIXED_TIMESTAMP,
                'changed' => self::FIXED_TIMESTAMP,
                'status' => 0,
                'workflow_state' => 'review',
            ],
            'archived_story' => [
                'title' => 'Archived Node',
                'type' => 'article',
                'uid' => 7,
                'created' => self::FIXED_TIMESTAMP,
                'changed' => self::FIXED_TIMESTAMP,
                'status' => 0,
                'workflow_state' => 'archived',
            ],
        ];
    }

    /**
     * @return list<array{alias: string, path: string, langcode: string, status: int}>
     */
    public static function pathAliasesForSsr(): array
    {
        return [
            ['alias' => '/node/1', 'path' => '/node/1', 'langcode' => 'en', 'status' => 1],
            ['alias' => '/teaching/water-is-life', 'path' => '/node/1', 'langcode' => 'en', 'status' => 1],
            ['alias' => '/node/2', 'path' => '/node/2', 'langcode' => 'en', 'status' => 1],
            ['alias' => '/node/3', 'path' => '/node/3', 'langcode' => 'en', 'status' => 1],
            ['alias' => '/node/4', 'path' => '/node/4', 'langcode' => 'en', 'status' => 1],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function aiMcpNodes(): array
    {
        return [
            'teaching_published' => [
                'title' => 'teaching A',
                'body' => 'water wisdom',
                'type' => 'teaching',
                'status' => 1,
                'workflow_state' => 'published',
            ],
            'teaching_draft' => [
                'title' => 'teaching B',
                'body' => 'fire wisdom',
                'type' => 'teaching',
                'status' => 0,
                'workflow_state' => 'draft',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function discoveryNodes(): array
    {
        return [
            'anchor_water' => [
                'title' => 'Water Teaching Anchor',
                'body' => 'anchor water context',
                'type' => 'teaching',
                'uid' => 9,
                'created' => self::FIXED_TIMESTAMP,
                'changed' => self::FIXED_TIMESTAMP,
                'status' => 1,
                'workflow_state' => 'published',
            ],
            'river_memory' => [
                'title' => 'River Memory',
                'body' => 'water lineage and kinship',
                'type' => 'story',
                'uid' => 9,
                'created' => self::FIXED_TIMESTAMP + 60,
                'changed' => self::FIXED_TIMESTAMP + 60,
                'status' => 1,
                'workflow_state' => 'published',
            ],
            'salmon_cycle' => [
                'title' => 'Salmon Cycle',
                'body' => 'water migration and seasonal return',
                'type' => 'teaching',
                'uid' => 9,
                'created' => self::FIXED_TIMESTAMP + 120,
                'changed' => self::FIXED_TIMESTAMP + 120,
                'status' => 1,
                'workflow_state' => 'published',
            ],
            'seasonal_calendar' => [
                'title' => 'Seasonal Calendar',
                'body' => 'timeline of spring and autumn gatherings',
                'type' => 'guide',
                'uid' => 9,
                'created' => self::FIXED_TIMESTAMP + 180,
                'changed' => self::FIXED_TIMESTAMP + 180,
                'status' => 1,
                'workflow_state' => 'published',
            ],
            'governance_draft' => [
                'title' => 'Governance Draft',
                'body' => 'internal draft guidance',
                'type' => 'article',
                'uid' => 9,
                'created' => self::FIXED_TIMESTAMP + 240,
                'changed' => self::FIXED_TIMESTAMP + 240,
                'status' => 0,
                'workflow_state' => 'draft',
            ],
            'archive_song' => [
                'title' => 'Archive Song',
                'body' => 'archived ceremonial text',
                'type' => 'story',
                'uid' => 9,
                'created' => self::FIXED_TIMESTAMP + 300,
                'changed' => self::FIXED_TIMESTAMP + 300,
                'status' => 0,
                'workflow_state' => 'archived',
            ],
        ];
    }

    /**
     * Deterministic relationship edges keyed by source/target fixture IDs.
     *
     * @return list<array{
     *   key: string,
     *   relationship_type: string,
     *   from: string,
     *   to: string,
     *   status: int,
     *   start_date: int,
     *   end_date: ?int
     * }>
     */
    public static function discoveryRelationships(): array
    {
        return [
            [
                'key' => 'anchor_to_river_related',
                'relationship_type' => 'related',
                'from' => 'anchor_water',
                'to' => 'river_memory',
                'status' => 1,
                'start_date' => self::FIXED_TIMESTAMP - 86400,
                'end_date' => null,
            ],
            [
                'key' => 'anchor_to_salmon_related',
                'relationship_type' => 'related',
                'from' => 'anchor_water',
                'to' => 'salmon_cycle',
                'status' => 1,
                'start_date' => self::FIXED_TIMESTAMP - 43200,
                'end_date' => null,
            ],
            [
                'key' => 'anchor_to_calendar_temporal',
                'relationship_type' => 'temporal',
                'from' => 'anchor_water',
                'to' => 'seasonal_calendar',
                'status' => 1,
                'start_date' => self::FIXED_TIMESTAMP - 3600,
                'end_date' => self::FIXED_TIMESTAMP + 3600,
            ],
            [
                'key' => 'salmon_to_anchor_supports',
                'relationship_type' => 'supports',
                'from' => 'salmon_cycle',
                'to' => 'anchor_water',
                'status' => 1,
                'start_date' => self::FIXED_TIMESTAMP - 7200,
                'end_date' => null,
            ],
            [
                'key' => 'anchor_to_draft_private',
                'relationship_type' => 'related',
                'from' => 'anchor_water',
                'to' => 'governance_draft',
                'status' => 0,
                'start_date' => self::FIXED_TIMESTAMP - 1000,
                'end_date' => null,
            ],
            [
                'key' => 'river_to_archived_related',
                'relationship_type' => 'related',
                'from' => 'river_memory',
                'to' => 'archive_song',
                'status' => 1,
                'start_date' => self::FIXED_TIMESTAMP - 2000,
                'end_date' => null,
            ],
        ];
    }

    /**
     * @return list<array{
     *   name: string,
     *   query: string,
     *   expected_visible_keys: list<string>
     * }>
     */
    public static function discoverySearchScenarios(): array
    {
        return [
            [
                'name' => 'water query keeps public keyword matches and excludes draft and archived',
                'query' => 'water',
                'expected_visible_keys' => ['anchor_water'],
            ],
            [
                'name' => 'seasonal query returns calendar',
                'query' => 'seasonal',
                'expected_visible_keys' => ['seasonal_calendar'],
            ],
        ];
    }

    /**
     * Deterministic larger graph corpus for traversal fanout/perf-focused suites.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function performanceNodesLargeGraph(): array
    {
        $states = ['published', 'review', 'draft', 'archived'];
        $types = ['teaching', 'story', 'guide', 'article'];
        $nodes = [];

        for ($i = 1; $i <= 48; $i++) {
            $key = sprintf('perf_%03d', $i);
            $state = $states[($i - 1) % count($states)];
            $nodes[$key] = [
                'title' => sprintf('Performance Node %03d', $i),
                'body' => sprintf('deterministic performance corpus body %03d', $i),
                'type' => $types[($i - 1) % count($types)],
                'uid' => 11,
                'created' => self::FIXED_TIMESTAMP + (600 + ($i * 30)),
                'changed' => self::FIXED_TIMESTAMP + (600 + ($i * 30)),
                'status' => $state === 'published' ? 1 : 0,
                'workflow_state' => $state,
            ];
        }

        ksort($nodes);

        return $nodes;
    }

    /**
     * @return list<array{
     *   key: string,
     *   relationship_type: string,
     *   from: string,
     *   to: string,
     *   status: int,
     *   start_date: int,
     *   end_date: ?int
     * }>
     */
    public static function performanceRelationshipsLargeGraph(): array
    {
        $relationships = [];
        $relationshipTypes = ['related', 'supports', 'temporal'];
        $anchor = 'perf_001';

        for ($i = 2; $i <= 40; $i++) {
            $toKey = sprintf('perf_%03d', $i);
            $relationshipType = $relationshipTypes[($i - 2) % count($relationshipTypes)];
            $relationships[] = [
                'key' => sprintf('perf_anchor_to_%03d', $i),
                'relationship_type' => $relationshipType,
                'from' => $anchor,
                'to' => $toKey,
                'status' => ($i % 5 === 0) ? 0 : 1,
                'start_date' => self::FIXED_TIMESTAMP - ($i * 120),
                'end_date' => $relationshipType === 'temporal'
                    ? self::FIXED_TIMESTAMP + ($i * 120)
                    : null,
            ];
        }

        for ($i = 2; $i <= 47; $i++) {
            $fromKey = sprintf('perf_%03d', $i);
            $toKey = sprintf('perf_%03d', $i + 1);
            $relationships[] = [
                'key' => sprintf('perf_chain_%03d_to_%03d', $i, $i + 1),
                'relationship_type' => 'related',
                'from' => $fromKey,
                'to' => $toKey,
                'status' => 1,
                'start_date' => self::FIXED_TIMESTAMP - (10_000 + ($i * 60)),
                'end_date' => null,
            ];
        }

        usort($relationships, static fn(array $a, array $b): int => strcmp((string) $a['key'], (string) $b['key']));

        return $relationships;
    }

    /**
     * @return list<array{
     *   name: string,
     *   anchor_key: string,
     *   status: string,
     *   limit: int,
     *   expected_min_total: int
     * }>
     */
    public static function performanceTraversalScenarios(): array
    {
        return [
            [
                'name' => 'published fanout keeps high-volume public edges',
                'anchor_key' => 'perf_001',
                'status' => 'published',
                'limit' => 64,
                'expected_min_total' => 7,
            ],
            [
                'name' => 'all-status fanout includes unpublished edges',
                'anchor_key' => 'perf_001',
                'status' => 'all',
                'limit' => 96,
                'expected_min_total' => 39,
            ],
        ];
    }

    /**
     * Deterministic mutation scenarios for cache invalidation coverage.
     *
     * @return list<array{
     *   name: string,
     *   mutate_entity_type: string,
     *   mutate_key: string,
     *   expected_affected_anchor_keys: list<string>
     * }>
     */
    public static function performanceCacheInvalidationScenarios(): array
    {
        return [
            [
                'name' => 'anchor node mutation invalidates broad fanout surfaces',
                'mutate_entity_type' => 'node',
                'mutate_key' => 'perf_001',
                'expected_affected_anchor_keys' => ['perf_001'],
            ],
            [
                'name' => 'high-degree relationship mutation invalidates anchor surfaces',
                'mutate_entity_type' => 'relationship',
                'mutate_key' => 'perf_anchor_to_020',
                'expected_affected_anchor_keys' => ['perf_001'],
            ],
        ];
    }

    /**
     * @return array{
     *   timestamp: int,
     *   ssr_nodes: array<string, array<string, mixed>>,
     *   ssr_aliases: list<array{alias: string, path: string, langcode: string, status: int}>,
     *   ai_mcp_nodes: array<string, array<string, mixed>>,
     *   discovery_nodes: array<string, array<string, mixed>>,
     *   discovery_relationships: list<array{
     *     key: string,
     *     relationship_type: string,
     *     from: string,
     *     to: string,
     *     status: int,
     *     start_date: int,
     *     end_date: ?int
     *   }>,
     *   discovery_search: list<array{name: string, query: string, expected_visible_keys: list<string>}>,
     *   performance_nodes: array<string, array<string, mixed>>,
     *   performance_relationships: list<array{
     *     key: string,
     *     relationship_type: string,
     *     from: string,
     *     to: string,
     *     status: int,
     *     start_date: int,
     *     end_date: ?int
     *   }>,
     *   performance_traversal: list<array{
     *     name: string,
     *     anchor_key: string,
     *     status: string,
     *     limit: int,
     *     expected_min_total: int
     *   }>,
     *   performance_cache_invalidation: list<array{
     *     name: string,
     *     mutate_entity_type: string,
     *     mutate_key: string,
     *     expected_affected_anchor_keys: list<string>
     *   }>,
     *   transition_access: list<array{
     *     name: string,
     *     bundle: string,
     *     from: string,
     *     to: string,
     *     permissions: list<string>,
     *     roles: list<string>,
     *     expected_allowed: bool
     *   }>,
     *   invalid_transitions: list<array{name: string, from: string, to: string}>
     * }
     */
    public static function corpusSnapshot(): array
    {
        return [
            'timestamp' => self::FIXED_TIMESTAMP,
            'ssr_nodes' => self::editorialNodesForSsr(),
            'ssr_aliases' => self::pathAliasesForSsr(),
            'ai_mcp_nodes' => self::aiMcpNodes(),
            'discovery_nodes' => self::discoveryNodes(),
            'discovery_relationships' => self::discoveryRelationships(),
            'discovery_search' => self::discoverySearchScenarios(),
            'performance_nodes' => self::performanceNodesLargeGraph(),
            'performance_relationships' => self::performanceRelationshipsLargeGraph(),
            'performance_traversal' => self::performanceTraversalScenarios(),
            'performance_cache_invalidation' => self::performanceCacheInvalidationScenarios(),
            'transition_access' => self::transitionAccessScenarios(),
            'invalid_transitions' => self::invalidTransitionScenarios(),
        ];
    }

    public static function corpusHash(): string
    {
        return sha1((string) json_encode(self::corpusSnapshot(), JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<array{
     *   name: string,
     *   bundle: string,
     *   from: string,
     *   to: string,
     *   permissions: list<string>,
     *   roles: list<string>,
     *   expected_allowed: bool
     * }>
     */
    public static function transitionAccessScenarios(): array
    {
        return [
            [
                'name' => 'contributor submit for review',
                'bundle' => 'article',
                'from' => 'draft',
                'to' => 'review',
                'permissions' => ['submit article for review'],
                'roles' => ['contributor'],
                'expected_allowed' => true,
            ],
            [
                'name' => 'reviewer publish from review',
                'bundle' => 'article',
                'from' => 'review',
                'to' => 'published',
                'permissions' => ['publish article content'],
                'roles' => ['reviewer'],
                'expected_allowed' => true,
            ],
            [
                'name' => 'editor archive from published',
                'bundle' => 'article',
                'from' => 'published',
                'to' => 'archived',
                'permissions' => ['archive article content'],
                'roles' => ['editor'],
                'expected_allowed' => true,
            ],
            [
                'name' => 'contributor cannot publish',
                'bundle' => 'article',
                'from' => 'review',
                'to' => 'published',
                'permissions' => ['publish article content'],
                'roles' => ['contributor'],
                'expected_allowed' => false,
            ],
            [
                'name' => 'reviewer cannot archive',
                'bundle' => 'article',
                'from' => 'published',
                'to' => 'archived',
                'permissions' => ['archive article content'],
                'roles' => ['reviewer'],
                'expected_allowed' => false,
            ],
        ];
    }

    /**
     * @return list<array{name: string, from: string, to: string}>
     */
    public static function invalidTransitionScenarios(): array
    {
        return [
            ['name' => 'draft cannot archive directly', 'from' => 'draft', 'to' => 'archived'],
            ['name' => 'published cannot go to review directly', 'from' => 'published', 'to' => 'review'],
        ];
    }
}
