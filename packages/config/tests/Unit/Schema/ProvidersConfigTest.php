<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Schema\Ai\ProvidersConfig;
use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretReference;

#[CoversClass(ProvidersConfig::class)]
final class ProvidersConfigTest extends TestCase
{
    #[Test]
    public function provider_schema_uses_typed_references_and_retains_legacy_v1(): void
    {
        self::assertSame(2, ProvidersConfig::SCHEMA_VERSION);
        self::assertSame(1, ProvidersConfig::LEGACY_SCHEMA_VERSION);
        $schema = ProvidersConfig::schema();
        $properties = $schema['properties']['providers']['items']['properties'];

        self::assertArrayHasKey('credential_reference', $properties);
        self::assertArrayNotHasKey('api_key_env_var', $properties);
    }

    #[Test]
    public function explicit_provider_reference_is_typed_for_the_exact_provider_purpose(): void
    {
        $rows = ProvidersConfig::normalise(['providers' => [[
            'id' => 'primary-anthropic',
            'type' => 'anthropic',
            'model_default' => 'claude-sonnet-4-6',
            'timeout_ms' => 120000,
            'rate_limit_per_min' => 30,
            'credential_reference' => [
                'provider' => 'synthetic-vault',
                'identifier' => 'tenant/anthropic/credential',
                'secret_class' => 'provider-credential',
                'purpose' => ProvidersConfig::ANTHROPIC_PURPOSE,
            ],
        ]]]);

        self::assertCount(1, $rows);
        self::assertInstanceOf(SecretReference::class, $rows[0]['credential_reference']);
        self::assertSame(SecretClass::ProviderCredential, $rows[0]['credential_reference']->secretClass());
        self::assertSame(ProvidersConfig::ANTHROPIC_PURPOSE, $rows[0]['credential_reference']->purpose());
    }

    #[Test]
    public function legacy_configured_name_migrates_to_a_required_environment_reference(): void
    {
        $rows = ProvidersConfig::normalise(['providers' => [[
            'id' => 'primary-openai',
            'type' => 'openai',
            'model_default' => 'gpt-4o-mini',
            'timeout_ms' => 120000,
            'rate_limit_per_min' => 30,
            'api_key_env_var' => 'OPENAI_API_KEY',
        ]]]);

        self::assertSame([
            'provider' => ProvidersConfig::LEGACY_ENVIRONMENT_PROVIDER,
            'identifier' => 'OPENAI_API_KEY',
            'secret_class' => 'provider-credential',
            'purpose' => ProvidersConfig::OPENAI_CHAT_PURPOSE,
        ], $rows[0]['credential_reference']->toArray());
    }

    #[Test]
    public function missing_wrong_or_raw_provider_credentials_are_refused(): void
    {
        foreach ([
            [],
            ['credential_reference' => [
                'provider' => 'synthetic-vault',
                'identifier' => 'tenant/openai/credential',
                'secret_class' => 'integration-credential',
                'purpose' => ProvidersConfig::OPENAI_CHAT_PURPOSE,
            ]],
            ['credential_reference' => 'CFG04-RAW-PROVIDER-CANARY'],
        ] as $credentialFields) {
            try {
                ProvidersConfig::normalise(['providers' => [array_merge([
                    'id' => 'primary-openai',
                    'type' => 'openai',
                    'model_default' => 'gpt-4o-mini',
                    'timeout_ms' => 120000,
                    'rate_limit_per_min' => 30,
                ], $credentialFields)]]);
                self::fail('Provider credentials must be complete typed references.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringNotContainsString('CFG04-RAW-PROVIDER-CANARY', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function null_provider_carries_no_credential_reference(): void
    {
        $rows = ProvidersConfig::normalise(['providers' => [[
            'id' => 'disabled',
            'type' => 'null',
            'model_default' => 'none',
            'timeout_ms' => 1,
            'rate_limit_per_min' => 0,
        ]]]);

        self::assertNull($rows[0]['credential_reference']);
    }
}
