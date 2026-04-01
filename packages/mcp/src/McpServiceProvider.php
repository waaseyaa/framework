<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp;

use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\WaaseyaaRouter;

final class McpServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function routes(WaaseyaaRouter $router, ?EntityTypeManager $entityTypeManager = null): void
    {
        (new McpRouteProvider())->registerRoutes($router);
    }
}
