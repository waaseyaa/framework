<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Schema\Ai;

use Waaseyaa\Config\Schema\ConfigSchemaRegistry;
use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretReference;

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
 * | `auth_mode`            | enum    | Exactly `none` or `secret-reference`.                |
 * | `availability`         | enum    | Exactly `required` or `optional`.                    |
 * | `credential_reference`| object  | Typed non-secret reference in secret mode.           |
 * | `enabled`              | bool    | Allows toggling without removing the row.            |
 * | `capability_prefix`    | string  | e.g. `tool.mcp.github` → grant `tool.mcp.github.X`.  |
 *
 * Schema v2 removes direct environment access from the consumer. Legacy empty
 * `auth_header_env_var` values migrate only to explicit `none`; non-empty names
 * become required references to the central environment provider and never
 * collapse to unauthenticated mode when unresolved.
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
    public const SCHEMA_VERSION = 2;
    public const LEGACY_SCHEMA_VERSION = 1;
    public const OWNER_PACKAGE = 'waaseyaa/config';
    public const OWNER_CONFIG_CONTRACT_VERSION = 1;
    public const AUTHORIZATION_PURPOSE = 'waaseyaa.mcp.authorization.v1';
    public const LEGACY_ENVIRONMENT_PROVIDER = 'environment';

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
                'auth_mode' => ['type' => 'string', 'enum' => ['none', 'secret-reference']],
                'availability' => ['type' => 'string', 'enum' => ['required', 'optional']],
                'credential_reference' => [
                    'type' => 'object',
                    'properties' => [
                        'provider' => ['type' => 'string'],
                        'identifier' => ['type' => 'string'],
                        'secret_class' => ['type' => 'string', 'enum' => ['integration-credential']],
                        'purpose' => ['type' => 'string', 'enum' => [self::AUTHORIZATION_PURPOSE]],
                    ],
                    'required' => ['provider', 'identifier', 'secret_class', 'purpose'],
                ],
                'enabled' => ['type' => 'boolean', 'default' => true],
                'capability_prefix' => ['type' => 'string'],
            ],
            'required' => ['alias', 'url', 'auth_mode', 'availability', 'capability_prefix'],
        ];
    }

    /** @return array<string, mixed> */
    public static function legacySchema(): array
    {
        return [
            'dialect' => ConfigSchemaRegistry::DIALECT_V1,
            'type' => 'object',
            'properties' => [
                self::ITEMS_KEY => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'alias' => ['type' => 'string'],
                            'url' => ['type' => 'string'],
                            'auth_header_env_var' => ['type' => 'string', 'default' => ''],
                            'enabled' => ['type' => 'boolean', 'default' => true],
                            'capability_prefix' => ['type' => 'string'],
                        ],
                        'required' => ['alias', 'url', 'capability_prefix'],
                    ],
                    'default' => [],
                ],
            ],
            'required' => [self::ITEMS_KEY],
        ];
    }

    /**
     * Register this schema with the closed authority registry.
     */
    public static function register(ConfigSchemaRegistry $registry): void
    {
        $registry->register(
            self::CONFIG_NAME,
            self::LEGACY_SCHEMA_VERSION,
            self::OWNER_PACKAGE,
            self::OWNER_CONFIG_CONTRACT_VERSION,
            self::legacySchema(),
        );
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
     *     auth_mode: McpAuthMode,
     *     availability: McpAvailability,
     *     credential_reference: SecretReference|null,
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

            [$authMode, $availability, $credentialReference] = self::normaliseAuthentication($item);

            $rows[] = [
                'alias' => $alias,
                'url' => $url,
                'auth_mode' => $authMode,
                'availability' => $availability,
                'credential_reference' => $credentialReference,
                'enabled' => isset($item['enabled']) ? (bool) $item['enabled'] : true,
                'capability_prefix' => $capabilityPrefix,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $item
     * @return array{McpAuthMode, McpAvailability, SecretReference|null}
     */
    private static function normaliseAuthentication(array $item): array
    {
        if (!array_key_exists('auth_mode', $item)) {
            $legacyName = $item['auth_header_env_var'] ?? '';
            if (!is_string($legacyName)) {
                throw new \InvalidArgumentException('Legacy MCP auth_header_env_var must be a string.');
            }
            if ($legacyName === '') {
                return [McpAuthMode::None, McpAvailability::Optional, null];
            }

            return [
                McpAuthMode::SecretReference,
                McpAvailability::Required,
                SecretReference::create(
                    self::LEGACY_ENVIRONMENT_PROVIDER,
                    $legacyName,
                    SecretClass::IntegrationCredential,
                    self::AUTHORIZATION_PURPOSE,
                ),
            ];
        }

        $mode = is_string($item['auth_mode']) ? McpAuthMode::tryFrom($item['auth_mode']) : null;
        $availability = isset($item['availability']) && is_string($item['availability'])
            ? McpAvailability::tryFrom($item['availability'])
            : null;
        if ($mode === null || $availability === null) {
            throw new \InvalidArgumentException('MCP auth_mode and availability must use closed supported values.');
        }
        if (isset($item['auth_header_env_var']) && $item['auth_header_env_var'] !== '') {
            throw new \InvalidArgumentException('MCP schema v2 cannot combine auth_mode with legacy auth_header_env_var.');
        }

        $rawReference = $item['credential_reference'] ?? null;
        if ($mode === McpAuthMode::None) {
            if ($rawReference !== null) {
                throw new \InvalidArgumentException('MCP none auth mode cannot carry a credential reference.');
            }

            return [$mode, $availability, null];
        }
        if (!is_array($rawReference)) {
            throw new \InvalidArgumentException('MCP secret-reference mode requires one credential reference.');
        }

        $provider = $rawReference['provider'] ?? null;
        $identifier = $rawReference['identifier'] ?? null;
        $secretClass = $rawReference['secret_class'] ?? null;
        $purpose = $rawReference['purpose'] ?? null;
        if (!is_string($provider) || !is_string($identifier) || !is_string($secretClass) || !is_string($purpose)) {
            throw new \InvalidArgumentException('MCP credential references require complete string fields.');
        }
        if ($secretClass !== SecretClass::IntegrationCredential->value || $purpose !== self::AUTHORIZATION_PURPOSE) {
            throw new \InvalidArgumentException('MCP credential references require the integration class and authorization purpose.');
        }

        return [
            $mode,
            $availability,
            SecretReference::create($provider, $identifier, SecretClass::IntegrationCredential, $purpose),
        ];
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
