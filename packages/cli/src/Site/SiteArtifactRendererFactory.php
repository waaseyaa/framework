<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site;

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
     * `[]` in 01D-1: the manifest-only root compiler advertises nothing, so a
     * blueprint-bearing manifest is refused at negotiation regardless of the
     * installed recipe set. 01D-2 unions the blueprint root compiler's own
     * declared feature roster here once that compiler is wired into
     * `site:init` (see FW-SITE-BLUEPRINT-01D decision (g)).
     *
     * @return list<string>
     */
    public static function advertisedGeneratorFeatures(): array
    {
        return [];
    }
}
