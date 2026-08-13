<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site;

use Waaseyaa\CLI\Site\Recipe\PublishedContentRecipe;
use Waaseyaa\CLI\Site\Recipe\SubscriptionRecipe;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;

final class SiteArtifactRendererFactory
{
    public static function create(): SiteArtifactRenderer
    {
        return new SiteArtifactRenderer([
            new PublishedContentRecipe(),
            new SubscriptionRecipe(),
        ]);
    }
}
