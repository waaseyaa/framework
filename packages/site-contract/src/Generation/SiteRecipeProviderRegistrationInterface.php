<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Generation;

use Waaseyaa\SiteContract\SiteManifest;

/**
 * A first-party recipe's fixed Composer provider registration (ADR-025 D-15.2).
 *
 * Every returned registration is a fixed constant of the recipe — no package
 * name, constraint, raw Composer member, manifest-supplied class, or
 * caller-selected merge behavior. Returns an empty list when the recipe's
 * capability is not selected by the manifest, mirroring
 * {@see SiteRecipeRendererInterface::render()}'s own guard, so
 * {@see SiteArtifactRenderer::compile()} can call every wired recipe
 * unconditionally and let each decide its own contribution.
 *
 * @api
 */
interface SiteRecipeProviderRegistrationInterface
{
    /** @return list<ComposerProviderRegistration> */
    public function providerRegistrations(SiteManifest $manifest): array;
}
