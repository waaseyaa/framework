<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Exception\ConfigException;
use Waaseyaa\Mcp\McpImplementationInfo;
use Waaseyaa\Mcp\McpServerCard;
use Waaseyaa\Mcp\McpServerCardConfig;

#[CoversClass(McpServerCard::class)]
#[CoversClass(McpServerCardConfig::class)]
final class McpServerCardTest extends TestCase
{
    #[Test]
    public function default_card_declares_public_no_auth(): void
    {
        $card = new McpServerCard()->toArray();

        self::assertSame('Waaseyaa', $card['name']);
        self::assertSame('0.1.0', $card['version']);
        self::assertSame('/mcp', $card['endpoint']);
        self::assertSame('streamable-http', $card['transport']);
        self::assertSame(['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26'], $card['protocolVersions']);
        self::assertSame([
            'jsonResponse' => true,
            'sse' => false,
            'sessions' => false,
            'resumability' => false,
        ], $card['transportCapabilities']);
        self::assertTrue($card['capabilities']['tools']);
        self::assertFalse($card['capabilities']['resources']);
        self::assertFalse($card['capabilities']['prompts']);
        self::assertSame('none', $card['authentication']['type']);
        // No registry block unless configured.
        self::assertArrayNotHasKey('registry', $card);
    }

    #[Test]
    public function config_overrides_identity_and_auth(): void
    {
        $config = McpServerCardConfig::fromArray([
            'description' => 'Minoo civic workspace',
            'endpoint' => '/api/mcp',
            'url' => 'https://minoo.ca',
            'auth_type' => 'bearer',
        ]);
        $card = new McpServerCard(
            $config,
            new McpImplementationInfo('Minoo', '2.1.0'),
        )->toArray();

        self::assertSame('Minoo', $card['name']);
        self::assertSame('Minoo civic workspace', $card['description']);
        self::assertSame('2.1.0', $card['version']);
        self::assertSame('/api/mcp', $card['endpoint']);
        self::assertSame('https://minoo.ca', $card['url']);
        self::assertSame('bearer', $card['authentication']['type']);
    }

    #[Test]
    public function resources_are_advertised_only_when_the_complete_surface_is_enabled(): void
    {
        self::assertTrue(new McpServerCard(resources: true)->toArray()['capabilities']['resources']);
        self::assertFalse(new McpServerCard(resources: false)->toArray()['capabilities']['resources']);
    }

    #[Test]
    public function invalid_auth_type_fails_closed(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('mcp.server_card.auth_type');

        McpServerCardConfig::fromArray(['auth_type' => 'magic']);
    }

    #[Test]
    public function oauth2_advertisement_is_preserved_for_a_standard_resource_server(): void
    {
        $card = new McpServerCard(McpServerCardConfig::fromArray(['auth_type' => 'oauth2']))->toArray();

        self::assertSame('oauth2', $card['authentication']['type']);
    }

    #[Test]
    public function registry_fields_are_rejected_instead_of_embedded_in_the_card(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('mcp.registry');

        McpServerCardConfig::fromArray([
            'registry_name' => 'io.github.waaseyaa/server',
        ]);
    }

    #[Test]
    public function identity_fields_belong_to_the_shared_implementation_config(): void
    {
        foreach (['name', 'version'] as $legacyKey) {
            try {
                McpServerCardConfig::fromArray([$legacyKey => 'wrong-owner']);
                self::fail($legacyKey . ' must not remain a second identity authority.');
            } catch (ConfigException $e) {
                self::assertStringContainsString('mcp.implementation', $e->getMessage());
            }
        }
    }

    #[Test]
    public function configured_url_must_be_secure_and_absolute(): void
    {
        foreach ([
            'http://cms.example',
            'https://cms.example/mcp?token=secret',
            'https://cms.example/mcp#fragment',
            'https://user:password@cms.example/mcp',
        ] as $url) {
            try {
                McpServerCardConfig::fromArray(['url' => $url]);
                self::fail($url . ' must not be advertised.');
            } catch (ConfigException $e) {
                self::assertStringContainsString('mcp.server_card.url', $e->getMessage());
                self::assertStringNotContainsString($url, $e->getMessage());
            }
        }
    }

    #[Test]
    public function endpoint_cannot_escape_to_a_foreign_authority(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('mcp.server_card.endpoint');

        McpServerCardConfig::fromArray(['endpoint' => '//foreign.example/mcp']);
    }

    #[Test]
    public function direct_config_construction_cannot_bypass_the_same_invariants(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('mcp.server_card.endpoint');

        new McpServerCardConfig(endpoint: '//foreign.example/mcp');
    }

    #[Test]
    public function to_json_is_valid_json(): void
    {
        $decoded = json_decode(new McpServerCard()->toJson(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('Waaseyaa', $decoded['name']);
    }
}
