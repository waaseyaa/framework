<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Site\SiteArtifactRendererFactory;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;

#[CoversClass(SiteArtifactRendererFactory::class)]
final class SiteArtifactRendererFactoryTest extends TestCase
{
    #[Test]
    public function createReturnsARendererComposingTheFirstPartyRecipes(): void
    {
        self::assertInstanceOf(SiteArtifactRenderer::class, SiteArtifactRendererFactory::create());
    }

    #[Test]
    public function advertisedGeneratorFeaturesIsEmptyIn01D1(): void
    {
        // FW-SITE-BLUEPRINT-01D, 01D-1: the manifest-only root compiler
        // advertises nothing, so a blueprint-bearing manifest is refused at
        // negotiation regardless of the installed recipe set. A later slice
        // unions the blueprint root compiler's own declared feature roster
        // here once that compiler is wired into site:init.
        self::assertSame([], SiteArtifactRendererFactory::advertisedGeneratorFeatures());
    }
}
