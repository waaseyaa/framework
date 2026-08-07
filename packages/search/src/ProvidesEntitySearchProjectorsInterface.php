<?php

declare(strict_types=1);

namespace Waaseyaa\Search;

use Waaseyaa\Search\Projection\EntitySearchProjectorInterface;

/**
 * Application contribution point for entity search projectors.
 *
 * Bind an implementation in a service provider's `register()` (the same
 * container convention as {@see ProvidesSearchSourceResolversInterface}) to
 * add site-specific searchable fields or canonical URL projection. Returned
 * projectors are consulted BEFORE the built-in defaults, so an application
 * projector that `supports()` node entities replaces the default node
 * projection entirely — including the ability to decline (`project()` =>
 * null) content that must stay out of the index.
 *
 * @api
 */
interface ProvidesEntitySearchProjectorsInterface
{
    /** @return list<EntitySearchProjectorInterface> Ordered ahead of the built-in defaults. */
    public function entitySearchProjectors(): array;
}
