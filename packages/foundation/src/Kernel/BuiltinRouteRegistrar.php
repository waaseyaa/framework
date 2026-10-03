<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel;

use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Routing\Metadata\FoundationRouteDefinitions;
use Waaseyaa\Foundation\Routing\Metadata\RouteDefinition;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteMetadataCompiler;
use Waaseyaa\Routing\WaaseyaaRouter;

/** Legacy HTTP adapter over the sole neutral framework declaration authority. */
final class BuiltinRouteRegistrar
{
    /** @param list<ServiceProvider> $providers */
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly array $providers = [],
    ) {}

    public function register(WaaseyaaRouter $router): void
    {
        $compiler = new RouteMetadataCompiler();
        foreach (FoundationRouteDefinitions::builtins() as $definition) {
            $this->registerBuiltin($router, $compiler, $definition);
        }
        // Compatibility only: canonical inspection never invokes these hooks.
        foreach ($this->providers as $provider) {
            $provider->routes($router, $this->entityTypeManager);
        }
        // Default-priority provider catch-alls precede the terminal SSR fallback.
        // Providers requiring precedence independent of ties must declare priority >= 1.
        foreach (FoundationRouteDefinitions::terminal() as $definition) {
            $this->registerBuiltin($router, $compiler, $definition);
        }
        $router->sortRoutesByPriority();
    }

    private function registerBuiltin(WaaseyaaRouter $router, RouteMetadataCompiler $compiler, RouteDefinition $definition): void
    {
        $route = $compiler->compileRoute($definition);
        // This authority contains only allowlisted builtin targets. Legacy dispatch
        // consumes their historical sentinel and original option shape.
        $route->setDefaults(['_controller' => $definition->handler->target] + $definition->defaults);
        $route->setOptions($definition->options);
        $router->addRoute($definition->name, $route);
    }
}
