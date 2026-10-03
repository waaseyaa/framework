<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\ServiceProvider\Capability;

use Waaseyaa\Foundation\Routing\Metadata\RouteContributionContext;
use Waaseyaa\Foundation\Routing\Metadata\RouteDefinition;

/** Pure route declarations, separate from execution-service construction. @api */
interface ContributesRouteMetadataInterface
{
    /** @return iterable<RouteDefinition> */
    public function routeDefinitions(RouteContributionContext $context): iterable;
}
