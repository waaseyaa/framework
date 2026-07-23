<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\PageComposition;

use Symfony\Component\HttpFoundation\Response;

/**
 * Application-owned full-document composition for authorized SSR entity pages.
 *
 * The framework invokes this presentation-only seam after routing, entity
 * visibility, entity access, field access, and safe-label resolution. Returning
 * null deliberately selects the framework's default entity renderer.
 *
 * @api
 */
interface EntityPageComposerInterface
{
    public function compose(EntityPageRenderPayload $page): ?Response;
}
