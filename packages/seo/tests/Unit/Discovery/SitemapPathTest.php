<?php

declare(strict_types=1);

namespace Waaseyaa\Seo\Tests\Unit\Discovery;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Seo\Discovery\SitemapContributorInterface;
use Waaseyaa\Seo\Discovery\SitemapPath;

/**
 * SitemapPath is PUBLIC API and is contract-tested as such.
 *
 * The parity gate's automatic discovery walks only interfaces, abstract classes,
 * traits, and enums, so a concrete final class is invisible to it. That is a
 * property of the scanner, not a statement about stability: this type is the
 * declared return type of the `@api` {@see SitemapContributorInterface}, so
 * applications construct it directly and its constructor signature and validation
 * rules are part of the stability contract. It is listed explicitly in
 * docs/public-surface-map.php so its removal is caught, and the assertions below
 * pin the shape the map promises.
 */
#[CoversClass(SitemapPath::class)]
final class SitemapPathTest extends TestCase
{
    #[Test]
    public function is_the_declared_return_element_of_the_public_contributor_contract(): void
    {
        $returnType = new \ReflectionMethod(SitemapContributorInterface::class, 'contributedPaths')
            ->getDocComment();

        self::assertIsString($returnType);
        self::assertStringContainsString('iterable<SitemapPath>', $returnType);
    }

    #[Test]
    public function is_tracked_in_the_public_surface_map_as_public(): void
    {
        // Absent when this package is exercised as a split repository, where
        // packages/seo IS the root and the monorepo's docs/ tree is not present.
        $map = dirname(__DIR__, 5) . '/docs/public-surface-map.php';
        if (!is_file($map)) {
            self::markTestSkipped('Public surface map is only present in the monorepo tree.');
        }

        /** @var array<string, string> $dispositions */
        $dispositions = require $map;

        self::assertArrayHasKey(SitemapPath::class, $dispositions);
        self::assertSame('public', $dispositions[SitemapPath::class]);
    }

    #[Test]
    public function exposes_a_stable_readonly_shape(): void
    {
        $path = new SitemapPath('/services', lastmod: '2026-08-22', changefreq: 'weekly', priority: 0.8);

        self::assertSame('/services', $path->path);
        self::assertSame('2026-08-22', $path->lastmod);
        self::assertSame('weekly', $path->changefreq);
        self::assertSame(0.8, $path->priority);

        $reflection = new \ReflectionClass(SitemapPath::class);
        self::assertTrue($reflection->isReadOnly(), 'SitemapPath must stay immutable.');
        self::assertTrue($reflection->isFinal(), 'SitemapPath must stay final.');
    }

    #[Test]
    public function metadata_is_optional(): void
    {
        $path = new SitemapPath('/calendar');

        self::assertSame('/calendar', $path->path);
        self::assertNull($path->lastmod);
        self::assertNull($path->changefreq);
        self::assertNull($path->priority);
    }

    #[Test]
    public function refuses_an_absolute_url_so_a_contributor_cannot_supply_an_authority(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SitemapPath('https://evil.example/services');
    }

    #[Test]
    public function refuses_a_protocol_relative_path(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SitemapPath('//evil.example/services');
    }

    #[Test]
    public function refuses_a_query_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SitemapPath('/services?x=1');
    }

    #[Test]
    public function refuses_a_traversal_segment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SitemapPath('/services/../../etc');
    }

    #[Test]
    public function refuses_an_invalid_changefreq(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SitemapPath('/services', changefreq: 'fortnightly');
    }

    #[Test]
    public function refuses_a_priority_outside_the_sitemap_range(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SitemapPath('/services', priority: 1.5);
    }
}
