<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Schema\Ai;

use Waaseyaa\Config\Schema\ConfigSchemaRegistry;

/**
 * JSON-Schema definition for the `config.ai.providers` list.
 *
 * Authoritative shape: `kitty-specs/agent-executor-01KRWPK7/data-model.md`
 * § "Config entities > config.ai.providers". Each list item carries the
 * provider id, type (`anthropic`, `openai`, `null`), default model, HTTP
 * timeout, app-side rate limit, and an `api_key_env_var` — an env-var
 * NAME from which the worker resolves the credential at boot.
 *
 * Secrets MUST NOT be persisted in config rows (C-010): the canonical
 * `defaults/ai.yaml` and any tenant overrides carry only env-var names.
 * `bin/check-no-secrets` parses this surface to keep credentials out of
 * the repo.
 *
 * @api
 */
final class ProvidersConfig
{
    public const string CONFIG_NAME = 'config.ai.providers';
    public const int SCHEMA_VERSION = 1;
    public const string OWNER_PACKAGE = 'waaseyaa/config';
    public const int OWNER_CONFIG_CONTRACT_VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'dialect' => ConfigSchemaRegistry::DIALECT_V1,
            'type' => 'object',
            'properties' => [
                'providers' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['id', 'type', 'model_default', 'timeout_ms', 'rate_limit_per_min', 'api_key_env_var'],
                        'properties' => [
                            'id' => [
                                'type' => 'string',
                            ],
                            'type' => [
                                'type' => 'string',
                                'enum' => ['anthropic', 'openai', 'null'],
                            ],
                            'model_default' => [
                                'type' => 'string',
                            ],
                            'timeout_ms' => [
                                'type' => 'integer',
                                'minimum' => 1,
                            ],
                            'rate_limit_per_min' => [
                                'type' => 'integer',
                                'minimum' => 0,
                            ],
                            'api_key_env_var' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                    'default' => [],
                ],
            ],
            'required' => ['providers'],
        ];
    }

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
}
