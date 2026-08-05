<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Discovery\ApiCatalog\ApiCatalogEntry;
use Waaseyaa\Foundation\Discovery\ApiCatalog\ApiCatalogTarget;

#[CoversClass(ApiCatalogEntry::class)]
#[CoversClass(ApiCatalogTarget::class)]
final class ApiCatalogEntryTest extends TestCase
{
    #[Test]
    public function entry_carries_a_public_endpoint_and_bounded_description_relations(): void
    {
        $entry = new ApiCatalogEntry(
            endpoint: new ApiCatalogTarget('/mcp', 'application/json', 'Public MCP endpoint'),
            serviceDescriptions: [new ApiCatalogTarget('/openapi/public-search.json', 'application/openapi+json')],
            serviceDocumentation: [new ApiCatalogTarget('/docs/mcp', 'text/html')],
            serviceMetadata: [new ApiCatalogTarget('/.well-known/mcp.json', 'application/json')],
        );

        self::assertSame('/mcp', $entry->endpoint->path);
        self::assertSame('/openapi/public-search.json', $entry->serviceDescriptions[0]->path);
        self::assertSame('/docs/mcp', $entry->serviceDocumentation[0]->path);
        self::assertSame('/.well-known/mcp.json', $entry->serviceMetadata[0]->path);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPaths(): iterable
    {
        yield 'absolute attacker URL' => ['https://attacker.example/mcp'];
        yield 'scheme-relative URL' => ['//attacker.example/mcp'];
        yield 'relative path' => ['mcp'];
        yield 'fragment' => ['/mcp#tools'];
        yield 'control character' => ["/mcp\nX-Forged: yes"];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('invalidPaths')]
    public function target_rejects_non_local_or_injectable_paths(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ApiCatalogTarget($path);
    }

    #[Test]
    public function target_rejects_malformed_media_types(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ApiCatalogTarget('/mcp', "application/json\r\nX-Forged: yes");
    }
}
