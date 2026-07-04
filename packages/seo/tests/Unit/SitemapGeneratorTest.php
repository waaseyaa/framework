<?php

declare(strict_types=1);

namespace Waaseyaa\Seo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Entity\Testing\QueryOnlyStubRepository;
use Waaseyaa\Entity\Testing\RecordingEntityQuery;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Seo\SitemapGenerator;
use Waaseyaa\Seo\SitemapUrl;

/**
 * @covers \Waaseyaa\Seo\SitemapGenerator
 */
#[CoversClass(SitemapGenerator::class)]
final class SitemapGeneratorTest extends TestCase
{
    #[Test]
    public function to_xml_emits_urlset(): void
    {
        $gen = new SitemapGenerator();
        $xml = $gen->toXml([
            new SitemapUrl(
                loc: 'https://example.com/a',
                lastmod: '2026-04-01',
                changefreq: 'weekly',
                priority: 0.8,
            ),
        ]);

        $this->assertStringContainsString('xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"', $xml);
        $this->assertStringContainsString('<loc>https://example.com/a</loc>', $xml);
        $this->assertStringContainsString('<lastmod>2026-04-01</lastmod>', $xml);
        $this->assertStringContainsString('<changefreq>weekly</changefreq>', $xml);
        $this->assertStringContainsString('<priority>0.8</priority>', $xml);
    }

    #[Test]
    public function collect_from_entity_types_respects_include(): void
    {
        $articleQuery = new StubEntityQuery([10, 20]);
        $pageQuery = new StubEntityQuery([3]);

        $articleStorage = $this->createStub(EntityStorageInterface::class);
        $articleStorage->method('getQuery')->willReturn($articleQuery);

        $pageStorage = $this->createStub(EntityStorageInterface::class);
        $pageStorage->method('getQuery')->willReturn($pageQuery);

        $articleDef = $this->createStub(EntityTypeInterface::class);
        $pageDef = $this->createStub(EntityTypeInterface::class);

        $etm = $this->createStub(EntityTypeManagerInterface::class);
        $etm->method('getDefinitions')->willReturn([
            'article' => $articleDef,
            'page' => $pageDef,
        ]);
        $etm->method('hasDefinition')->willReturnCallback(static fn (string $id): bool => \in_array(
            $id,
            ['article', 'page'],
            true,
        ));
        $etm->method('getStorage')->willReturnMap([
            ['article', $articleStorage],
            ['page', $pageStorage],
        ]);
        // C-22: the query builder now lives on the repository.
        $etm->method('getRepository')->willReturnMap([
            ['article', new QueryOnlyStubRepository($articleQuery)],
            ['page', new QueryOnlyStubRepository($pageQuery)],
        ]);

        $gen = new SitemapGenerator();
        $urls = $gen->collectFromEntityTypes(
            $etm,
            static function (string $type, int|string $id): string {
                return 'https://example.com/' . $type . '/' . $id;
            },
            includeEntityTypes: ['article'],
            excludeEntityTypes: [],
            maxUrlsPerType: 10,
            perTypeOptions: [
                'article' => ['changefreq' => 'monthly', 'priority' => '0.7'],
            ],
        );

        $this->assertCount(2, $urls);
        $this->assertSame('https://example.com/article/10', $urls[0]->loc);
        $this->assertSame('monthly', $urls[0]->changefreq);
        $this->assertSame(0.7, $urls[0]->priority);
    }

    #[Test]
    public function collect_skips_excluded_types(): void
    {
        $articleStorage = $this->createStub(EntityStorageInterface::class);
        $articleStorage->method('getQuery')->willReturn(new StubEntityQuery([1]));

        $articleDef = $this->createStub(EntityTypeInterface::class);

        $etm = $this->createStub(EntityTypeManagerInterface::class);
        $etm->method('getDefinitions')->willReturn(['article' => $articleDef]);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getStorage')->with('article')->willReturn($articleStorage);

        $gen = new SitemapGenerator();
        $urls = $gen->collectFromEntityTypes(
            $etm,
            static fn (): string => 'https://example.com/x',
            excludeEntityTypes: ['article'],
        );

        $this->assertSame([], $urls);
    }

    #[Test]
    public function collect_skips_empty_locations_from_callback(): void
    {
        $query = new StubEntityQuery([1, 2]);
        $storage = $this->createStub(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn($query);

        $def = $this->createStub(EntityTypeInterface::class);
        $etm = $this->createStub(EntityTypeManagerInterface::class);
        $etm->method('getDefinitions')->willReturn(['article' => $def]);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getStorage')->with('article')->willReturn($storage);
        // C-22: the query builder now lives on the repository.
        $etm->method('getRepository')->with('article')->willReturn(new QueryOnlyStubRepository($query));

        $gen = new SitemapGenerator();
        $urls = $gen->collectFromEntityTypes(
            $etm,
            static function (string $type, int|string $id): ?string {
                return $id == 1 ? null : 'https://example.com/a/' . $id;
            },
        );

        $this->assertCount(1, $urls);
        $this->assertSame('https://example.com/a/2', $urls[0]->loc);
    }

    #[Test]
    public function collect_filters_to_published_for_status_bearing_types(): void
    {
        // A public sitemap must not advertise unpublished/draft content. For
        // entity types that declare a `status` (published) field, the
        // enumeration query must add condition('status', 1) so anonymous
        // crawlers only see published URLs. (The accessCheck(false) bypass is
        // retained for URL-generation performance per C-004.)
        $statusQuery = new StubEntityQuery([1, 2]);
        $statuslessQuery = new StubEntityQuery([3]);

        $nodeStorage = $this->createStub(EntityStorageInterface::class);
        $nodeStorage->method('getQuery')->willReturn($statusQuery);
        $taxStorage = $this->createStub(EntityStorageInterface::class);
        $taxStorage->method('getQuery')->willReturn($statuslessQuery);

        $nodeDef = $this->createStub(EntityTypeInterface::class);
        $nodeDef->method('getFieldDefinitions')->willReturn(['status' => true, 'title' => true]);
        $vocabDef = $this->createStub(EntityTypeInterface::class);
        $vocabDef->method('getFieldDefinitions')->willReturn(['name' => true]); // no status field

        $etm = $this->createStub(EntityTypeManagerInterface::class);
        $etm->method('getDefinitions')->willReturn(['node' => $nodeDef, 'vocab' => $vocabDef]);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getStorage')->willReturnMap([
            ['node', $nodeStorage],
            ['vocab', $taxStorage],
        ]);
        // C-22: the query builder now lives on the repository.
        $etm->method('getRepository')->willReturnMap([
            ['node', new QueryOnlyStubRepository($statusQuery)],
            ['vocab', new QueryOnlyStubRepository($statuslessQuery)],
        ]);

        (new SitemapGenerator())->collectFromEntityTypes(
            $etm,
            static fn (string $type, int|string $id): string => 'https://example.com/' . $type . '/' . $id,
        );

        $this->assertSame(
            [['status', 1, '=']],
            $statusQuery->conditions,
            'status-bearing types must be filtered to published (status = 1)',
        );
        $this->assertSame(
            [],
            $statuslessQuery->conditions,
            'types without a status field must not get a published filter',
        );
    }

    #[Test]
    public function collect_binds_the_given_account_per_entity_type_instead_of_bypassing_access(): void
    {
        // R6/M3 (audit M3): sitemap generation must be ACCESS-AWARE, not a
        // blanket bypass — a published-but-access-restricted entity must not
        // be enumerable to an anonymous crawler. setAccount() (not
        // accessCheck(false)) is the mechanism: SqlEntityQuery::execute()
        // runs EntityAccessHandler::check() per candidate when an account is
        // bound, so a Forbidden/Neutral policy result excludes the row.

        $query = (new RecordingEntityQuery())->withResults([1]);

        $storage = $this->createStub(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn($query);

        $def = $this->createStub(EntityTypeInterface::class);
        $etm = $this->createStub(EntityTypeManagerInterface::class);
        $etm->method('getDefinitions')->willReturn([
            'article' => $def,
            'page' => $def,
        ]);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getStorage')->willReturn($storage);
        // C-22: the query builder now lives on the repository.
        $etm->method('getRepository')->willReturn(new QueryOnlyStubRepository($query));

        $account = $this->createStub(AccountInterface::class);

        $gen = new SitemapGenerator();
        $gen->collectFromEntityTypes(
            $etm,
            static fn (string $type, int|string $id): string => 'https://example.com/' . $type . '/' . $id,
            account: $account,
        );

        $this->assertSame(
            [],
            $query->accessChecks,
            'collectFromEntityTypes must not call accessCheck(false) when an account is supplied — access checking stays enabled.',
        );
        $this->assertSame(
            $account,
            $query->boundAccount,
            'collectFromEntityTypes must bind the caller-supplied account via setAccount() so access policies are enforced.',
        );
    }

    #[Test]
    public function collect_does_not_bind_an_account_when_none_is_supplied(): void
    {
        // Fail-closed default: when no account is passed, the query is left
        // unbound (no setAccount(), no accessCheck(false)) rather than
        // silently reintroducing the C-004 bypass. Callers building a public
        // crawler surface MUST pass an account.
        $query = (new RecordingEntityQuery())->withResults([1]);

        $storage = $this->createStub(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn($query);

        $def = $this->createStub(EntityTypeInterface::class);
        $etm = $this->createStub(EntityTypeManagerInterface::class);
        $etm->method('getDefinitions')->willReturn(['article' => $def]);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getStorage')->willReturn($storage);
        $etm->method('getRepository')->willReturn(new QueryOnlyStubRepository($query));

        $gen = new SitemapGenerator();
        $gen->collectFromEntityTypes(
            $etm,
            static fn (string $type, int|string $id): string => 'https://example.com/' . $type . '/' . $id,
        );

        $this->assertSame([], $query->accessChecks);
        $this->assertNull($query->boundAccount);
    }
}
