<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Generation;

use Waaseyaa\SiteContract\SiteManifest;

/** @api */
interface SiteRecipeRendererInterface
{
    public function id(): string;

    /** @return list<GeneratedArtifact> */
    public function render(SiteManifest $manifest): array;
}
