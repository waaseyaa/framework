<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel;

use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Routing\Metadata\FoundationRouteDefinitions;
use Waaseyaa\Foundation\Routing\Metadata\RouteCompositionException;
use Waaseyaa\Foundation\Routing\Metadata\RouteContributionContext;
use Waaseyaa\Foundation\Routing\Metadata\RouteDefinition;
use Waaseyaa\Foundation\Routing\Metadata\RouteSnapshot;
use Waaseyaa\Foundation\Routing\Metadata\ValidatedRouteParticipation;
use Waaseyaa\Foundation\ServiceProvider\Capability\ContributesRouteMetadataInterface;
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

    /**
     * HTTP execution projection only. Canonical inspection must use the kernel snapshot.
     * @param array<string, RouteContributionContext> $contexts
     * @return array<string, RouteDefinition> Explicit handlers still present after legacy overrides.
     */
    public function registerAdmitted(WaaseyaaRouter $router, ValidatedRouteParticipation $participation, array $contexts, ?RouteSnapshot $snapshot): array
    {
        if (array_column($participation->records, 'provider') !== array_map(static fn(ServiceProvider $provider): string => $provider::class, $this->providers)) {
            throw new RouteCompositionException('inventory-unavailable', 'HTTP provider roster does not match admission.');
        }
        $projected = [];
        if ($snapshot !== null) {
            foreach ($snapshot->routes as $definition) {
                $projected[$definition->sourceId][] = $definition;
            }
            foreach ($projected as &$definitions) {
                usort($definitions, static fn(RouteDefinition $left, RouteDefinition $right): int => $left->ordinal <=> $right->ordinal);
            }
            unset($definitions);
        }
        $compiler = new RouteMetadataCompiler();
        $handlers = [];
        foreach (FoundationRouteDefinitions::builtins() as $definition) {
            $this->registerBuiltin($router, $compiler, $definition);
        }
        foreach ($participation->records as $order => $record) {
            $provider = $this->providers[$order];
            if ($record['kind'] === 'none') {
                continue;
            }
            if ($record['kind'] === 'legacy') {
                if ($snapshot !== null) {
                    throw new RouteCompositionException('legacy-contributor', 'Legacy route contributor: ' . $provider::class);
                }
                try {
                    $provider->routes($router, $this->entityTypeManager);
                } catch (\Throwable) {
                    throw new RouteCompositionException('legacy-contribution-failed', 'HTTP legacy contributor failed: ' . $provider::class);
                }
                continue;
            }
            $context = $contexts[$provider::class] ?? null;
            if ($record['kind'] !== 'declarative' || !$provider instanceof ContributesRouteMetadataInterface || !$context instanceof RouteContributionContext
                || $context->sourceId !== $provider::class || $context->sourceOrder !== $order) {
                throw new RouteCompositionException('inventory-unavailable', 'HTTP declaration context does not match admission.');
            }
            try {
                $ordinal = 0;
                $definitions = $snapshot === null ? $provider->routeDefinitions($context) : ($projected[$provider::class] ?? []);
                foreach ($definitions as $definition) {
                    $definition = $this->admitDefinition($definition, $provider::class, $ordinal++);
                    $route = $compiler->compileRoute($definition);
                    if ($definition->handler->kind === 'builtin') {
                        $route->setDefault('_controller', $definition->handler->target);
                    } else {
                        $handlers[$definition->name] = $definition;
                    }
                    $router->addRoute($definition->name, $route);
                }
            } catch (\Throwable) {
                throw new RouteCompositionException('contribution-failed', 'HTTP declarative contributor failed: ' . $provider::class);
            }
        }
        foreach (FoundationRouteDefinitions::terminal() as $definition) {
            $this->registerBuiltin($router, $compiler, $definition);
        }
        $router->sortRoutesByPriority();
        $collection = $router->getRouteCollection();
        foreach ($handlers as $name => $definition) {
            // Explicit legacy remove/add overrides remain legacy execution values.
            if ($collection->get($name)?->getDefault('_controller') !== $definition->handler->id) {
                unset($handlers[$name]);
            }
        }
        return $handlers;
    }

    /** Validate runtime contributor values, whose iterable PHPDoc is not admission. */
    private function admitDefinition(mixed $definition, string $source, int $ordinal): RouteDefinition
    {
        if (!$definition instanceof RouteDefinition || $definition->sourceId !== $source || $definition->ordinal !== $ordinal) {
            throw new \UnexpectedValueException();
        }
        return $definition;
    }
}
