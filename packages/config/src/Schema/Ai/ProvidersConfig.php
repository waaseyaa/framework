<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Schema\Ai;

use Waaseyaa\Config\Schema\ConfigSchemaRegistry;
use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretReference;

/**
 * JSON-Schema definition for the `config.ai.providers` list.
 *
 * Authoritative shape: `kitty-specs/agent-executor-01KRWPK7/data-model.md`
 * § "Config entities > config.ai.providers". Each list item carries the
 * provider id, type (`anthropic`, `openai`, `null`), default model, HTTP
 * timeout, app-side rate limit, and a typed non-secret credential reference.
 *
 * Legacy v1 environment-variable names migrate to references for the central
 * environment provider. Provider packages never read them directly.
 *
 * @api
 */
final class ProvidersConfig
{
    public const string CONFIG_NAME = 'config.ai.providers';
    public const int SCHEMA_VERSION = 2;
    public const int LEGACY_SCHEMA_VERSION = 1;
    public const string OWNER_PACKAGE = 'waaseyaa/config';
    public const int OWNER_CONFIG_CONTRACT_VERSION = 1;
    public const string LEGACY_ENVIRONMENT_PROVIDER = 'environment';
    public const string ANTHROPIC_PURPOSE = 'waaseyaa.ai.anthropic.v1';
    public const string OPENAI_CHAT_PURPOSE = 'waaseyaa.ai.openai-chat.v1';

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
                        'required' => ['id', 'type', 'model_default', 'timeout_ms', 'rate_limit_per_min'],
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
                            'credential_reference' => [
                                'type' => 'object',
                                'properties' => [
                                    'provider' => ['type' => 'string'],
                                    'identifier' => ['type' => 'string'],
                                    'secret_class' => ['type' => 'string', 'enum' => ['provider-credential']],
                                    'purpose' => ['type' => 'string', 'enum' => [self::ANTHROPIC_PURPOSE, self::OPENAI_CHAT_PURPOSE]],
                                ],
                                'required' => ['provider', 'identifier', 'secret_class', 'purpose'],
                            ],
                        ],
                    ],
                    'default' => [],
                ],
            ],
            'required' => ['providers'],
        ];
    }

    /** @return array<string, mixed> */
    public static function legacySchema(): array
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
                            'id' => ['type' => 'string'],
                            'type' => ['type' => 'string', 'enum' => ['anthropic', 'openai', 'null']],
                            'model_default' => ['type' => 'string'],
                            'timeout_ms' => ['type' => 'integer', 'minimum' => 1],
                            'rate_limit_per_min' => ['type' => 'integer', 'minimum' => 0],
                            'api_key_env_var' => ['type' => 'string'],
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
     * @param array<string, mixed>|false $raw
     * @return list<array{
     *     id: string,
     *     type: 'anthropic'|'openai'|'null',
     *     model_default: string,
     *     timeout_ms: int,
     *     rate_limit_per_min: int,
     *     credential_reference: SecretReference|null,
     * }>
     */
    public static function normalise(array|false $raw): array
    {
        if ($raw === false) {
            return [];
        }
        $providers = $raw['providers'] ?? [];
        if (!is_array($providers)) {
            throw new \InvalidArgumentException('AI providers configuration must contain a provider list.');
        }

        $rows = [];
        foreach ($providers as $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('AI provider rows must be objects.');
            }
            $id = $item['id'] ?? null;
            $type = $item['type'] ?? null;
            $model = $item['model_default'] ?? null;
            $timeout = $item['timeout_ms'] ?? null;
            $rateLimit = $item['rate_limit_per_min'] ?? null;
            if (!is_string($id) || $id === ''
                || !is_string($type) || !in_array($type, ['anthropic', 'openai', 'null'], true)
                || !is_string($model) || $model === ''
                || !is_int($timeout) || $timeout < 1
                || !is_int($rateLimit) || $rateLimit < 0) {
                throw new \InvalidArgumentException('AI provider rows require valid closed metadata.');
            }

            $rows[] = [
                'id' => $id,
                'type' => $type,
                'model_default' => $model,
                'timeout_ms' => $timeout,
                'rate_limit_per_min' => $rateLimit,
                'credential_reference' => self::normaliseCredential($item, $type),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $item
     * @param 'anthropic'|'openai'|'null' $type
     */
    private static function normaliseCredential(array $item, string $type): ?SecretReference
    {
        $rawReference = $item['credential_reference'] ?? null;
        $legacyName = $item['api_key_env_var'] ?? null;
        if ($type === 'null') {
            if ($rawReference !== null || (is_string($legacyName) && $legacyName !== '')) {
                throw new \InvalidArgumentException('Null AI providers cannot carry credential references.');
            }

            return null;
        }

        $purpose = $type === 'anthropic' ? self::ANTHROPIC_PURPOSE : self::OPENAI_CHAT_PURPOSE;
        if ($rawReference === null) {
            if (!is_string($legacyName) || $legacyName === '') {
                throw new \InvalidArgumentException('AI providers require a typed credential reference.');
            }

            return SecretReference::create(
                self::LEGACY_ENVIRONMENT_PROVIDER,
                $legacyName,
                SecretClass::ProviderCredential,
                $purpose,
            );
        }
        if ($legacyName !== null) {
            throw new \InvalidArgumentException('AI providers cannot combine legacy and typed credential configuration.');
        }
        if (!is_array($rawReference)) {
            throw new \InvalidArgumentException('AI provider credential references must be typed objects.');
        }
        $provider = $rawReference['provider'] ?? null;
        $identifier = $rawReference['identifier'] ?? null;
        $secretClass = $rawReference['secret_class'] ?? null;
        $referencePurpose = $rawReference['purpose'] ?? null;
        if (!is_string($provider) || !is_string($identifier) || !is_string($secretClass) || !is_string($referencePurpose)
            || $secretClass !== SecretClass::ProviderCredential->value
            || $referencePurpose !== $purpose) {
            throw new \InvalidArgumentException('AI provider credential reference fields are invalid.');
        }

        return SecretReference::create(
            $provider,
            $identifier,
            SecretClass::ProviderCredential,
            $referencePurpose,
        );
    }
}
