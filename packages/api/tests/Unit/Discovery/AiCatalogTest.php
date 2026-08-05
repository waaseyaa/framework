<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Discovery;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Api\Discovery\AiCatalog;
use Waaseyaa\Foundation\Discovery\AiCatalog\AiCatalogEntry;

final class AiCatalogTest extends TestCase
{
    private const string SCHEMA_SHA256 = 'c55238483a4738e08b250bdd6af1f4dc05a91afe882c649d224d09c19cd8fe09';
    private const string SCHEMA_URL = 'https://raw.githubusercontent.com/ards-project/ard-spec/4a8a6b8fdd3ac4a50dcb63213573159c1eed7856/spec/schemas/ai-catalog.schema.json';

    #[Test]
    public function it_emits_the_pinned_ard_extension_shape_from_public_entries(): void
    {
        $catalog = new AiCatalog(
            baseUrl: 'https://cms.example',
            entries: [
                new AiCatalogEntry(
                    'mcp:public',
                    'Waaseyaa public MCP',
                    'application/json',
                    '/.well-known/mcp.json',
                    'Public read-only Model Context Protocol discovery.',
                    ['ContentDiscovery'],
                ),
            ],
            representativeQueries: [
                'mcp:public' => ['Find public community information', 'Search published events'],
            ],
        );

        self::assertSame('1.0', AiCatalog::SPEC_VERSION);
        self::assertStringContainsString(
            '4a8a6b8fdd3ac4a50dcb63213573159c1eed7856',
            self::SCHEMA_URL,
        );
        self::assertSame([
            'specVersion' => '1.0',
            'entries' => [[
                'identifier' => 'urn:air:cms.example:mcp:public',
                'displayName' => 'Waaseyaa public MCP',
                'type' => 'application/json',
                'url' => 'https://cms.example/.well-known/mcp.json',
                'description' => 'Public read-only Model Context Protocol discovery.',
                'capabilities' => ['ContentDiscovery'],
                'representativeQueries' => ['Find public community information', 'Search published events'],
            ]],
        ], $catalog->toArray());
    }

    #[Test]
    public function an_overlay_key_without_an_installed_public_entry_fails_closed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('representative_queries');

        new AiCatalog(
            'https://cms.example',
            [new AiCatalogEntry('mcp:public', 'MCP', 'application/json', '/.well-known/mcp.json')],
            ['mcp:write' => ['Create private content', 'Delete a user']],
        );
    }

    #[Test]
    public function generated_document_validates_against_the_byte_pinned_ard_schema(): void
    {
        $schemaPath = dirname(__DIR__, 2) . '/Fixtures/ard-v0.9-ai-catalog.schema.json';
        self::assertSame(self::SCHEMA_SHA256, hash_file('sha256', $schemaPath));
        self::assertStringContainsString(
            '4a8a6b8fdd3ac4a50dcb63213573159c1eed7856',
            self::SCHEMA_URL,
        );

        $catalog = new AiCatalog('https://cms.example', [
            new AiCatalogEntry(
                'api:catalog',
                'Waaseyaa public API catalog',
                'application/linkset+json',
                '/.well-known/api-catalog',
            ),
        ], [
            'api:catalog' => ['List the public APIs', 'Find public machine-readable endpoints'],
        ]);
        $schema = json_decode((string) file_get_contents($schemaPath), false, 512, JSON_THROW_ON_ERROR);
        $document = json_decode($catalog->toJson(), false, 512, JSON_THROW_ON_ERROR);

        self::assertTrue((new Validator())->validate($document, $schema)->isValid());
    }

    #[Test]
    public function provider_order_and_exact_duplicates_do_not_change_bytes(): void
    {
        $api = new AiCatalogEntry('api:catalog', 'Public APIs', 'application/linkset+json', '/.well-known/api-catalog');
        $mcp = new AiCatalogEntry('mcp:public', 'Public MCP', 'application/json', '/.well-known/mcp.json');
        $queries = ['mcp:public' => ['Find public pages', 'Search public events']];

        $forward = new AiCatalog('https://cms.example', [$mcp, $api, $api], $queries);
        $reverse = new AiCatalog('https://cms.example', [$api, $mcp], $queries);

        self::assertSame($forward->toJson(), $reverse->toJson());
        self::assertSame([
            'urn:air:cms.example:api:catalog',
            'urn:air:cms.example:mcp:public',
        ], array_column($forward->toArray()['entries'], 'identifier'));
    }

    #[Test]
    public function conflicting_duplicate_entry_keys_fail_closed(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('mcp:public');

        new AiCatalog('https://cms.example', [
            new AiCatalogEntry('mcp:public', 'Public MCP', 'application/json', '/.well-known/mcp.json'),
            new AiCatalogEntry('mcp:public', 'Other MCP', 'application/json', '/other.json'),
        ]);
    }

    /** @return iterable<string, array{array<mixed, mixed>}> */
    public static function malformedQueries(): iterable
    {
        yield 'one query' => [['mcp:public' => ['Only one']]];
        yield 'six queries' => [['mcp:public' => ['One', 'Two', 'Three', 'Four', 'Five', 'Six']]];
        yield 'duplicate' => [['mcp:public' => ['Same query', 'Same query']]];
        yield 'control' => [['mcp:public' => ["Public\nquery", 'Other query']]];
        yield 'non-list' => [['mcp:public' => [1 => 'First', 2 => 'Second']]];
    }

    /** @param array<mixed, mixed> $queries */
    #[DataProvider('malformedQueries')]
    #[Test]
    public function malformed_representative_queries_fail_closed(array $queries): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AiCatalog(
            'https://cms.example',
            [new AiCatalogEntry('mcp:public', 'MCP', 'application/json', '/.well-known/mcp.json')],
            $queries,
        );
    }
}
