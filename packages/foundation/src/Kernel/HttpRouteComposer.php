<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Routing\Metadata\RouteCompositionException;
use Waaseyaa\Foundation\Routing\Metadata\RouteContributionContext;
use Waaseyaa\Foundation\Routing\Metadata\RouteDefinition;
use Waaseyaa\Foundation\Routing\Metadata\RouteSnapshot;
use Waaseyaa\Foundation\Routing\Metadata\ValidatedRouteParticipation;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteHandlerResolver;
use Waaseyaa\Routing\WaaseyaaRouter;

/** One kernel-local HTTP execution projection; never canonical inspection authority. @internal */
final class HttpRouteComposer
{
    private ?RouteCollection $routes = null;
    /** @var array<string, RouteDefinition> */
    private array $handlers = [];
    private bool $building = false;
    private ?RouteCompositionException $failure = null;

    /** @param list<ServiceProvider> $providers
     * @param array<string, RouteContributionContext> $contexts
     */
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly array $providers,
        private readonly ValidatedRouteParticipation $participation,
        private readonly array $contexts,
        private readonly KernelHandlerContainer $services,
        private readonly ?RouteSnapshot $snapshot = null,
        private readonly string $mode = 'legacy',
    ) {
        if (!in_array($mode, ['legacy', 'canonical'], true)) {
            throw new RouteCompositionException('unsupported-mode', 'HTTP route mode must be legacy or canonical.');
        }
        $legacy = array_filter($participation->records, static fn(array $record): bool => $record['kind'] === 'legacy');
        if ($mode === 'canonical' && $legacy !== []) {
            throw new RouteCompositionException('legacy-contributor', 'Canonical HTTP cannot admit legacy route contributors.');
        }
        if (($legacy === []) !== ($snapshot !== null)
            || ($snapshot !== null && (($snapshot->inputs['participation'] ?? null) !== $participation->records || ($snapshot->inputs['compiler'] ?? null) !== $participation->compilerIdentity))) {
            throw new RouteCompositionException('inventory-unavailable', 'HTTP snapshot does not match the admitted provider cohort.');
        }
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function router(Request $request): WaaseyaaRouter
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        if ($this->building) {
            throw $this->failure = new RouteCompositionException('collecting', 'Recursive HTTP route composition is unavailable.');
        }
        if ($this->routes === null) {
            $this->building = true;
            try {
                $router = new WaaseyaaRouter();
                $handlers = new BuiltinRouteRegistrar($this->entityTypeManager, $this->providers)->registerAdmitted($router, $this->participation, $this->contexts, $this->snapshot);
                $services = $this->services->explicitServices($request);
                foreach ($handlers as $definition) {
                    if (!$services->has($definition->handler->target)) {
                        throw new RouteCompositionException('handler-unavailable', 'Explicit HTTP handler binding unavailable for ' . $definition->name . '.');
                    }
                }
                $this->requireHealthy();
                $routes = $router->getRouteCollection();
                $this->handlers = $handlers;
                $this->routes = $routes;
            } catch (\Throwable $error) {
                $this->failure ??= $error instanceof RouteCompositionException ? $error : new RouteCompositionException('http-composition-failed', 'HTTP route composition failed; a new kernel is required.');
                throw $this->failure;
            } finally {
                $this->building = false;
            }
        }
        return WaaseyaaRouter::fromCollection($this->routes, new RequestContext()->fromRequest($request));
    }

    public function resolveMatched(Request $request): \Closure|string|null
    {
        $name = $request->attributes->get('_route');
        if (!is_string($name) || !isset($this->handlers[$name])) {
            return null;
        }
        return new RouteHandlerResolver($this->handlers[$name], $this->services->explicitServices($request))->resolveMatched();
    }

    /** Recheck after arbitrary legacy PHP, which can catch a refused recursive call. */
    private function requireHealthy(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
