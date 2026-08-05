<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Discovery\AiCatalog\AiCatalogEntry;

final class AiCatalogEntryTest extends TestCase
{
    #[Test]
    public function public_artifact_metadata_is_immutable_and_deterministic(): void
    {
        $entry = new AiCatalogEntry(
            key: 'mcp:public',
            displayName: 'Waaseyaa public MCP',
            type: 'application/json',
            path: '/.well-known/mcp.json',
            description: 'Public read-only Model Context Protocol discovery.',
            capabilities: ['Search', 'ContentDiscovery', 'Search'],
        );

        self::assertSame([
            'key' => 'mcp:public',
            'displayName' => 'Waaseyaa public MCP',
            'type' => 'application/json',
            'path' => '/.well-known/mcp.json',
            'description' => 'Public read-only Model Context Protocol discovery.',
            'capabilities' => ['ContentDiscovery', 'Search'],
        ], $entry->fingerprintData());
    }

    /** @return iterable<string, array{string}> */
    public static function unsafePaths(): iterable
    {
        yield 'protocol relative' => ['//attacker.example/card.json'];
        yield 'absolute' => ['https://attacker.example/card.json'];
        yield 'backslash' => ['/safe\\..\\secret'];
        yield 'dot segment' => ['/safe/../secret'];
        yield 'encoded dot segment' => ['/safe/%2e%2e/secret'];
        yield 'encoded slash' => ['/safe%2fsecret'];
        yield 'double encoded traversal' => ['/safe/%252e%252e/secret'];
        yield 'encoded control' => ['/card%0d%0aheader.json'];
        yield 'malformed escape' => ['/card%zz.json'];
        yield 'query' => ['/card.json?token=secret'];
        yield 'fragment' => ['/card.json#private'];
    }

    #[DataProvider('unsafePaths')]
    #[Test]
    public function artifact_paths_cannot_escape_the_canonical_origin(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AiCatalogEntry('mcp:public', 'MCP', 'application/json', $path);
    }
}
