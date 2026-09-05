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
    public function dispatcherAdvertisesOnlyTheActivatedBlueprintFeature(): void
    {
        // This advertises the dispatcher's installed capabilities; the
        // renderer remains the legacy recipe composition, and the engine
        // separately binds approval to the selected declared compiler.
        self::assertSame(['site-application-blueprint-v1'], SiteArtifactRendererFactory::advertisedGeneratorFeatures());
    }
}
