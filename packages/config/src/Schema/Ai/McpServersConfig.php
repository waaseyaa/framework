<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Schema\Ai;

use Waaseyaa\Config\Schema\ConfigSchemaRegistry;

/**
 * Schema for `config.ai.mcp_servers` — the list of remote MCP servers
 * whose tools should be merged into the local agent catalogue.
 *
 * Per the agent-executor data-model § "config.ai.mcp_servers" each row has:
 *
 * | Field                  | Type    | Notes                                                |
 * |------------------------|---------|------------------------------------------------------|
 * | `alias`                | string  | Stable handle used in the tool-name prefix.          |
 * | `url`                  | string  | Streamable-HTTP MCP server URL (C-008).              |
 * | `auth_header_env_var`  | string  | Env-var **name** carrying the `Authorization` value. |
 * | `enabled`              | bool    | Allows toggling without removing the row.            |
 * | `capability_prefix`    | string  | e.g. `tool.mcp.github` → grant `tool.mcp.github.X`.  |
 *
 * C-010 enforcement: `auth_header_env_var` is a *name*, never the secret
 * itself. {@see \Waaseyaa\AI\Agent\Mcp\McpClientToolSource} reads
 * `getenv($name)` at call time. `bin/check-no-secrets` lints config sync
 * payloads for accidentally pasted secret values.
 *
 * Alias uniqueness is part of the contract — two rows with the same
 * alias would collide on tool names. {@see hasDuplicateAliases()} surfaces
 * the violation; the consuming source may either fail-fast or skip
 * duplicates per host policy.
 *
 * @api
 */
final class McpServersConfig
{
    public const CONFIG_NAME = 'ai.mcp_servers';
    public const SCHEMA_VERSION = 1;
    public const OWNER_PACKAGE = 'waaseyaa/config';
    public const OWNER_CONFIG_CONTRACT_VERSION = 1;

    /**
     * The `items` payload always lives under the `items` key of the config
     * object, mirroring the CMI shape used by other list configs.
     */
    public const ITEMS_KEY = 'items';

    /**
     * JSON-Schema-like definition consumed by {@see ConfigSchemaValidator}.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'dialect' => ConfigSchemaRegistry::DIALECT_V1,
            'type' => 'object',
            'properties' => [
                self::ITEMS_KEY => [
                    'type' => 'array',
                    'items' => self::rowSchema(),
                    'default' => [],
                ],
            ],
            'required' => [self::ITEMS_KEY],
        ];
    }

    /**
     * Per-row schema kept separately because {@see ConfigSchemaValidator}
     * does not yet support `array.items` validation. Callers that need
     * per-row validation should iterate themselves.
     *
     * @return array<string, mixed>
     */
    public static function rowSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'alias' => ['type' => 'string'],
                'url' => ['type' => 'string'],
                'auth_header_env_var' => ['type' => 'string', 'default' => ''],
                'enabled' => ['type' => 'boolean', 'default' => true],
                'capability_prefix' => ['type' => 'string'],
            ],
            'required' => ['alias', 'url', 'capability_prefix'],
        ];
    }

    /**
     * Register this schema with the closed authority registry.
     */
    public static function register(ConfigSchemaRegistry $registry): void
    {
        $registry->register(
            self::CONFIG_NAME,
            self::SCHEMA_VERSION,
            self::OWNER_PACKAGE,
            self::OWNER_CONFIG_CONTRACT_VERSION,
            self::schema(),
        );
    }

    /**
     * Empty defaults for this config. Used when bootstrapping a fresh
     * install where `defaults/ai.yaml` does not yet ship a `mcp_servers`
     * entry (WP04 introduces that file; WP07 ships only this schema).
     *
     * @return array{items: list<array<string, mixed>>}
     */
    public static function emptyDefault(): array
    {
        return [self::ITEMS_KEY => []];
    }

    /**
     * Normalise + filter a raw stored config payload into validated rows
     * suitable for {@see \Waaseyaa\AI\Agent\Mcp\McpClientToolSource}.
     *
     * Rows missing required keys are dropped (with a warning surfaced by
     * the caller's logger if desired). Disabled rows are dropped silently.
     *
     * @param array<string, mixed>|false $raw Output of `StorageInterface::read()`.
     *
     * @return list<array{
     *     alias: string,
     *     url: string,
     *     auth_header_env_var: string,
     *     enabled: bool,
     *     capability_prefix: string,
     * }>
     */
    public static function normalise(array|false $raw): array
    {
        if ($raw === false) {
            return [];
        }
        $items = $raw[self::ITEMS_KEY] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $rows = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $alias = $item['alias'] ?? null;
            $url = $item['url'] ?? null;
            $capabilityPrefix = $item['capability_prefix'] ?? null;
            if (!is_string($alias) || $alias === '') {
                continue;
            }
            if (!is_string($url) || $url === '') {
                continue;
            }
            if (!is_string($capabilityPrefix) || $capabilityPrefix === '') {
                continue;
            }

            $rows[] = [
                'alias' => $alias,
                'url' => $url,
                'auth_header_env_var' => isset($item['auth_header_env_var']) && is_string($item['auth_header_env_var'])
                    ? $item['auth_header_env_var']
                    : '',
                'enabled' => isset($item['enabled']) ? (bool) $item['enabled'] : true,
                'capability_prefix' => $capabilityPrefix,
            ];
        }

        return $rows;
    }

    /**
     * Returns aliases that appear more than once in the given rows.
     *
     * @param list<array{alias: string}> $rows
     *
     * @return list<string>
     */
    public static function hasDuplicateAliases(array $rows): array
    {
        $seen = [];
        $duplicates = [];
        foreach ($rows as $row) {
            $alias = $row['alias'];
            if (isset($seen[$alias])) {
                $duplicates[$alias] = true;
            }
            $seen[$alias] = true;
        }

        return array_keys($duplicates);
    }
}
