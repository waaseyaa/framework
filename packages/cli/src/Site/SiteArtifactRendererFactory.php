<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site;

use Waaseyaa\CLI\Site\Blueprint\ApplicationBlueprintCompiler;
use Waaseyaa\CLI\Site\Recipe\GovernedAuthoringRecipe;
use Waaseyaa\CLI\Site\Recipe\PublishedContentRecipe;
use Waaseyaa\CLI\Site\Recipe\SubscriptionRecipe;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;

final class SiteArtifactRendererFactory
{
    public static function create(): SiteArtifactRenderer
    {
        return new SiteArtifactRenderer([
            new GovernedAuthoringRecipe(),
            new PublishedContentRecipe(),
            new SubscriptionRecipe(),
        ]);
    }

    /**
     * The generator-feature tokens this installed generation authority
     * advertises (ADR-023 D-2, FW-SITE-BLUEPRINT-01D decision (g)).
     *
     * The process dispatcher can select the blueprint compiler. The legacy
     * renderer itself stays unchanged; execution eligibility and approval are
     * still enforced by SiteInitializationService against the declared plan.
     *
     * @return list<string>
     */
    public static function advertisedGeneratorFeatures(): array
    {
        return ApplicationBlueprintCompiler::GENERATOR_FEATURES;
    }
}
