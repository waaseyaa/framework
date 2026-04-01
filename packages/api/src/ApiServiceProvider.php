<?php

declare(strict_types=1);

namespace Waaseyaa\Api;

use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\WaaseyaaRouter;

final class ApiServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function routes(WaaseyaaRouter $router, ?EntityTypeManager $entityTypeManager = null): void
    {
        if ($entityTypeManager === null) {
            return;
        }

        (new JsonApiRouteProvider($entityTypeManager))->registerRoutes($router);
    }
}
