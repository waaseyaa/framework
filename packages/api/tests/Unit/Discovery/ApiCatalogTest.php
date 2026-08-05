<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Api\Discovery\ApiCatalog;
use Waaseyaa\Foundation\Discovery\ApiCatalog\ApiCatalogEntry;
use Waaseyaa\Foundation\Discovery\ApiCatalog\ApiCatalogTarget;

#[CoversClass(ApiCatalog::class)]
final class ApiCatalogTest extends TestCase
{
    #[Test]
    public function serializes_an_rfc_9264_linkset_with_deterministic_absolute_targets(): void
    {
        $catalog = new ApiCatalog('https://cms.example/base', [
            new ApiCatalogEntry(new ApiCatalogTarget('/mcp', 'application/json', 'MCP'), serviceMetadata: [
                new ApiCatalogTarget('/.well-known/mcp.json', 'application/json'),
            ]),
            new ApiCatalogEntry(new ApiCatalogTarget('/.well-known/waaseyaa-anchors.json', 'application/json', 'Wayfinding anchors')),
        ]);

        self::assertSame([
            'linkset' => [
                [
                    'anchor' => 'https://cms.example/base/.well-known/api-catalog',
                    'item' => [
                        [
                            'href' => 'https://cms.example/base/.well-known/waaseyaa-anchors.json',
                            'type' => 'application/json',
                            'title' => 'Wayfinding anchors',
                        ],
                        [
                            'href' => 'https://cms.example/base/mcp',
                            'type' => 'application/json',
                            'title' => 'MCP',
                        ],
                    ],
                ],
                [
                    'anchor' => 'https://cms.example/base/mcp',
                    'service-meta' => [[
                        'href' => 'https://cms.example/base/.well-known/mcp.json',
                        'type' => 'application/json',
                    ]],
                ],
            ],
        ], $catalog->toArray());
    }

    #[Test]
    public function exact_duplicate_entries_collapse_without_changing_order(): void
    {
        $entry = new ApiCatalogEntry(new ApiCatalogTarget('/mcp', 'application/json', 'MCP'));
        $catalog = new ApiCatalog('https://cms.example', [$entry, $entry]);

        $items = $catalog->toArray()['linkset'][0]['item'];
        self::assertCount(1, $items);
        self::assertSame('https://cms.example/mcp', $items[0]['href']);
    }

    #[Test]
    public function relation_order_and_duplicate_targets_do_not_change_the_definition(): void
    {
        $a = new ApiCatalogTarget('/a', 'application/json');
        $b = new ApiCatalogTarget('/b', 'application/json');
        $catalog = new ApiCatalog('https://cms.example', [
            new ApiCatalogEntry(new ApiCatalogTarget('/mcp'), serviceMetadata: [$b, $a, $a]),
            new ApiCatalogEntry(new ApiCatalogTarget('/mcp'), serviceMetadata: [$a, $b]),
        ]);

        self::assertCount(1, $catalog->toArray()['linkset'][0]['item']);
        self::assertSame(
            ['https://cms.example/a', 'https://cms.example/b'],
            array_column($catalog->toArray()['linkset'][1]['service-meta'], 'href'),
        );
    }

    #[Test]
    public function conflicting_definitions_for_one_endpoint_fail_closed(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('/mcp');

        new ApiCatalog('https://cms.example', [
            new ApiCatalogEntry(new ApiCatalogTarget('/mcp', 'application/json', 'MCP')),
            new ApiCatalogEntry(new ApiCatalogTarget('/mcp', 'text/plain', 'Not MCP')),
        ]);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidBaseUrls(): iterable
    {
        yield 'insecure origin' => ['http://cms.example'];
        yield 'query' => ['https://cms.example/base?x=1'];
        yield 'fragment' => ['https://cms.example/base#x'];
        yield 'scheme relative' => ['//cms.example'];
        yield 'credentials' => ['https://user:password@cms.example'];
        yield 'dot segment' => ['https://cms.example/base/../admin'];
        yield 'control character' => ["https://cms.example/base\nforged"];
    }

    #[Test]
    #[DataProvider('invalidBaseUrls')]
    public function base_url_must_be_canonical_https_without_query_or_fragment(string $invalid): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ApiCatalog($invalid, []);
    }
}
