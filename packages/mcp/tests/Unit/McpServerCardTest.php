<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
            'name' => 'minoo.ca',
            'description' => 'Minoo civic workspace',
            'version' => '2.1.0',
            'endpoint' => '/api/mcp',
            'url' => 'https://minoo.ca',
            'auth_type' => 'bearer',
        ]);
        $card = new McpServerCard($config)->toArray();

        self::assertSame('minoo.ca', $card['name']);
        self::assertSame('Minoo civic workspace', $card['description']);
        self::assertSame('2.1.0', $card['version']);
        self::assertSame('/api/mcp', $card['endpoint']);
        self::assertSame('https://minoo.ca', $card['url']);
        self::assertSame('bearer', $card['authentication']['type']);
    }

    #[Test]
    public function invalid_auth_type_falls_back_to_none(): void
    {
        $card = new McpServerCard(McpServerCardConfig::fromArray(['auth_type' => 'magic']))->toArray();
        self::assertSame('none', $card['authentication']['type']);
    }

    #[Test]
    public function registry_fields_are_emitted_when_configured(): void
    {
        $config = McpServerCardConfig::fromArray([
            'registry_name' => 'io.github.waaseyaa/server',
            'repository_url' => 'https://github.com/waaseyaa/framework',
            'website_url' => 'https://waaseyaa.dev',
            'schema_url' => 'https://static.modelcontextprotocol.io/schemas/server.json',
        ]);
        $card = new McpServerCard($config)->toArray();

        self::assertArrayHasKey('registry', $card);
        self::assertSame('io.github.waaseyaa/server', $card['registry']['name']);
        self::assertSame('https://github.com/waaseyaa/framework', $card['registry']['repository']['url']);
        self::assertSame('github', $card['registry']['repository']['source']);
        self::assertSame('https://waaseyaa.dev', $card['registry']['websiteUrl']);
        self::assertArrayHasKey('$schema', $card['registry']);
    }

    #[Test]
    public function to_json_is_valid_json(): void
    {
        $decoded = json_decode(new McpServerCard()->toJson(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('Waaseyaa', $decoded['name']);
    }
}
