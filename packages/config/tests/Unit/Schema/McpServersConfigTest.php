<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Schema\Ai\McpAuthMode;
use Waaseyaa\Config\Schema\Ai\McpAvailability;
use Waaseyaa\Config\Schema\Ai\McpServersConfig;
use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretReference;

#[CoversClass(McpServersConfig::class)]
final class McpServersConfigTest extends TestCase
{
    #[Test]
    public function explicit_none_is_typed_and_cannot_carry_a_reference(): void
    {
        $row = $this->normaliseOne([
            'auth_mode' => 'none',
            'availability' => 'optional',
        ]);

        self::assertSame(McpAuthMode::None, $row['auth_mode']);
        self::assertSame(McpAvailability::Optional, $row['availability']);
        self::assertNull($row['credential_reference']);

        $this->expectException(\InvalidArgumentException::class);
        McpServersConfig::normalise($this->payload([
            'auth_mode' => 'none',
            'availability' => 'optional',
            'credential_reference' => $this->referenceFields(),
        ]));
    }

    #[Test]
    public function secret_reference_mode_requires_an_exact_typed_integration_reference(): void
    {
        $row = $this->normaliseOne([
            'auth_mode' => 'secret-reference',
            'availability' => 'required',
            'credential_reference' => $this->referenceFields(),
        ]);

        self::assertSame(McpAuthMode::SecretReference, $row['auth_mode']);
        self::assertSame(McpAvailability::Required, $row['availability']);
        self::assertInstanceOf(SecretReference::class, $row['credential_reference']);
        self::assertSame(SecretClass::IntegrationCredential, $row['credential_reference']->secretClass());
        self::assertSame(McpServersConfig::AUTHORIZATION_PURPOSE, $row['credential_reference']->purpose());

        $this->expectException(\InvalidArgumentException::class);
        McpServersConfig::normalise($this->payload([
            'auth_mode' => 'secret-reference',
            'availability' => 'required',
        ]));
    }

    #[Test]
    public function wrong_class_or_purpose_is_refused_during_normalisation(): void
    {
        foreach ([
            ['secret_class' => 'provider-credential'],
            ['purpose' => 'waaseyaa.ai.embedding.v1'],
        ] as $override) {
            try {
                McpServersConfig::normalise($this->payload([
                    'auth_mode' => 'secret-reference',
                    'availability' => 'required',
                    'credential_reference' => array_replace($this->referenceFields(), $override),
                ]));
                self::fail('Wrong-class or wrong-purpose MCP references must be refused.');
            } catch (\InvalidArgumentException) {
            }
        }

        self::assertTrue(true);
    }

    #[Test]
    public function legacy_empty_auth_name_migrates_only_to_explicit_none(): void
    {
        $row = $this->normaliseOne(['auth_header_env_var' => '']);

        self::assertSame(McpAuthMode::None, $row['auth_mode']);
        self::assertSame(McpAvailability::Optional, $row['availability']);
        self::assertNull($row['credential_reference']);
    }

    #[Test]
    public function legacy_configured_auth_name_remains_required_when_unresolved(): void
    {
        $row = $this->normaliseOne(['auth_header_env_var' => 'MCP_AUTHORIZATION']);

        self::assertSame(McpAuthMode::SecretReference, $row['auth_mode']);
        self::assertSame(McpAvailability::Required, $row['availability']);
        self::assertInstanceOf(SecretReference::class, $row['credential_reference']);
        self::assertSame([
            'provider' => McpServersConfig::LEGACY_ENVIRONMENT_PROVIDER,
            'identifier' => 'MCP_AUTHORIZATION',
            'secret_class' => 'integration-credential',
            'purpose' => McpServersConfig::AUTHORIZATION_PURPOSE,
        ], $row['credential_reference']->toArray());
    }

    /** @param array<string, mixed> $authFields */
    private function normaliseOne(array $authFields): array
    {
        $rows = McpServersConfig::normalise($this->payload($authFields));
        self::assertCount(1, $rows);

        return $rows[0];
    }

    /** @param array<string, mixed> $authFields */
    private function payload(array $authFields): array
    {
        return [
            McpServersConfig::ITEMS_KEY => [array_merge([
                'alias' => 'stub',
                'url' => 'https://stub.invalid/mcp',
                'enabled' => true,
                'capability_prefix' => 'tool.mcp.stub',
            ], $authFields)],
        ];
    }

    /** @return array{provider: string, identifier: string, secret_class: string, purpose: string} */
    private function referenceFields(): array
    {
        return [
            'provider' => 'synthetic-vault',
            'identifier' => 'tenant/stub/mcp-authorization',
            'secret_class' => 'integration-credential',
            'purpose' => 'waaseyaa.mcp.authorization.v1',
        ];
    }
}
