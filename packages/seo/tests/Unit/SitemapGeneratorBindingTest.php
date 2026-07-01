<?php

declare(strict_types=1);

namespace Waaseyaa\Seo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Entity\Testing\QueryOnlyStubRepository;
use Waaseyaa\Entity\Testing\RecordingEntityQuery;
use Waaseyaa\Seo\SitemapGenerator;

/**
 * Regression guard for #1527: SitemapGenerator::collectFromEntityTypes() must
 * call accessCheck(false) for every entity-type query — sitemap generation
 * enumerates public URLs for crawlers and runs without a request-scoped
 * account; entity-level access is enforced when the caller subsequently loads
 * entities to render their pages.
 *
 * Without accessCheck(false), SqlEntityQuery::execute() throws
 * MissingQueryAccountException under the fail-closed default introduced in
 * v0.1.0-alpha.181, returning HTTP 500 on /sitemap.xml.
 */
#[CoversClass(SitemapGenerator::class)]
final class SitemapGeneratorBindingTest extends TestCase
{
    #[Test]
    public function collectFromEntityTypesCallsAccessCheckFalseForEveryType(): void
    {
        $query = new RecordingEntityQuery();

        $storage = $this->createStub(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn($query);

        $def = $this->createStub(EntityTypeInterface::class);

        $etm = $this->createStub(EntityTypeManagerInterface::class);
        $etm->method('getDefinitions')->willReturn(['node' => $def, 'media' => $def]);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getStorage')->willReturn($storage);
        // C-22: the query builder now lives on the repository.
        $etm->method('getRepository')->willReturn(new QueryOnlyStubRepository($query));

        $gen = new SitemapGenerator();
        $gen->collectFromEntityTypes(
            $etm,
            static fn (string $type, int|string $id): string => 'https://example.com/' . $type . '/' . $id,
        );

        self::assertSame(
            [false, false],
            $query->accessChecks,
            'SitemapGenerator::collectFromEntityTypes() must call accessCheck(false) for every entity type (regression #1527).',
        );
    }
}
