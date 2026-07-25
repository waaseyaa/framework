<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\ServiceProvider;

/**
 * @api
 */
abstract class ServiceProvider implements ServiceProviderInterface
{
    protected string $projectRoot = '';

    /** @var array<string, mixed> */
    protected array $config = [];

    /** @var array<string, class-string> */
    protected array $manifestFormatters = [];

    /** @var array<string, array{concrete: string|callable, shared: bool}> */
    private array $bindings = [];

    /** @var array<string, object> */
    private array $resolved = [];

    /**
     * When this provider has been merged into a "stack" provider via
     * {@see mergeChildProvider()}, this points at the merge root so all
     * resolution funnels through a single {@see resolve()}/{@see $resolved}
     * cache. Null for a standalone (never-merged) provider.
     */
    private ?ServiceProvider $mergeRoot = null;

    /** @var array<string, list<string>> */
    private array $tags = [];

    /** @var list<array{entityType: \Waaseyaa\Entity\EntityTypeInterface, registrant: class-string}> */
    private array $entityTypeRegistrations = [];

    protected ?KernelServicesInterface $kernelServices = null;

    abstract public function register(): void;

    public function boot(): void {}

    /**
     * No-op default. Override to contribute HTTP routes.
     *
     * Called exactly once at boot by `BuiltinRouteRegistrar`. Resolve
     * request-scoped services (e.g. `DatabaseInterface`) lazily inside the
     * route handler, never eagerly when building a controller here, or writes
     * may be silently lost (#1611). See {@see ServiceProviderInterface::routes()}.
     */
    public function routes(\Waaseyaa\Routing\WaaseyaaRouter $router, \Waaseyaa\Entity\EntityTypeManager $entityTypeManager): void {}

    public function provides(): array
    {
        return [];
    }

    public function isDeferred(): bool
    {
        return $this->provides() !== [];
    }

    /**
     * Provide kernel context to providers before register()/boot().
     *
     * @param array<string, mixed> $config
     * @param array<string, class-string> $manifestFormatters
     */
    public function setKernelContext(string $projectRoot, array $config, array $manifestFormatters): void
    {
        $this->projectRoot = $projectRoot;
        $this->config = $config;
        $this->manifestFormatters = $manifestFormatters;
    }

    /**
     * Provide the typed kernel-services bus that backs {@see resolve()} fallbacks
     * for abstracts the provider has not bound locally.
     */
    public function setKernelServices(KernelServicesInterface $services): void
    {
        $this->kernelServices = $services;
    }

    /**
     * Run {@see register()} on a child provider and merge its bindings, entity types, and tags into this provider.
     *
     * Used by application "stack" providers to preserve a single composer entry while delegating to focused classes.
     * This is a register/compose-time operation.
     *
     * Resolution-root guarantee: after merge, the child is re-rooted to this provider
     * (transitively for nested merges). All resolution — including calls to
     * {@see resolve()} made from inside the child's own binding closures, which capture
     * the child's `$this` — funnels through, and caches in, the single merge root. A
     * binding declared via {@see singleton()} therefore yields exactly one shared
     * instance across the composed stack, never a per-provider copy. Any entries the
     * child resolved before it was merged are adopted into the root; a genuine conflict
     * (the same abstract already resolved to a different instance in the root) throws
     * rather than silently forking.
     */
    final protected function mergeChildProvider(ServiceProvider $child): void
    {
        // Re-root to the ultimate merge root so a shared binding is one instance
        // across the composed stack. When this provider is itself a merged child, use
        // its root so nested grandchildren reach the same ultimate root.
        $root = $this->mergeRoot ?? $this;

        // Precondition guards run before register() so a mistake fails loudly instead
        // of recursing forever or half-wiring a child.
        if ($child === $root) {
            throw new \RuntimeException('mergeChildProvider: a provider cannot merge itself.');
        }
        if ($child->mergeRoot !== null && $child->mergeRoot !== $root) {
            throw new \RuntimeException('mergeChildProvider: child provider is already merged into a different root.');
        }

        $child->setKernelContext($this->projectRoot, $this->config, $this->manifestFormatters);
        if ($this->kernelServices !== null) {
            $child->setKernelServices($this->kernelServices);
        }
        $child->register();
        foreach ($child->getBindings() as $abstract => $binding) {
            $this->bindings[$abstract] = $binding;
        }
        foreach ($child->getEntityTypeRegistrations() as $registration) {
            $this->entityTypeRegistrations[] = $registration;
        }
        foreach ($child->getTags() as $tag => $abstracts) {
            foreach ($abstracts as $abstract) {
                $this->tag($abstract, $tag);
            }
        }

        // Adopt anything the child resolved before merge; a genuine conflict fails loud
        // rather than leaving two live instances of a shared binding.
        foreach ($child->resolved as $abstract => $instance) {
            if (isset($root->resolved[$abstract]) && $root->resolved[$abstract] !== $instance) {
                throw new \RuntimeException(
                    "mergeChildProvider: split-brain — '{$abstract}' is already resolved to a different instance in the merge root.",
                );
            }
            $root->resolved[$abstract] = $instance;
        }

        $child->mergeRoot = $root;
    }

    protected function singleton(string $abstract, string|callable $concrete): void
    {
        $this->bindings[$abstract] = ['concrete' => $concrete, 'shared' => true];
    }

    protected function bind(string $abstract, string|callable $concrete): void
    {
        $this->bindings[$abstract] = ['concrete' => $concrete, 'shared' => false];
    }

    protected function tag(string $abstract, string $tag): void
    {
        $this->tags[$tag] ??= [];
        $this->tags[$tag][] = $abstract;
    }

    /**
     * Resolve an abstract without throwing — returns null when not bound.
     *
     * Useful for optional framework services (event dispatcher, logger, writer)
     * whose absence must not crash the provider.
     */
    public function resolveOptional(string $abstract): ?object
    {
        try {
            return $this->resolve($abstract);
        } catch (\RuntimeException) {
            return null;
        }
    }

    public function resolve(string $abstract): object
    {
        // A merged child re-roots resolution to its merge root so that a shared
        // binding is one instance across the composed stack — including when it is
        // consumed from inside another binding's closure (which captured the
        // child's $this). Recurses up the chain for nested merges.
        if ($this->mergeRoot !== null) {
            return $this->mergeRoot->resolve($abstract);
        }

        if (isset($this->resolved[$abstract])) {
            return $this->resolved[$abstract];
        }

        if (!isset($this->bindings[$abstract])) {
            if ($this->kernelServices !== null) {
                $resolved = $this->kernelServices->get($abstract);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
            throw new \RuntimeException("No binding registered for {$abstract}.");
        }

        $binding = $this->bindings[$abstract];
        $concrete = $binding['concrete'];

        $instance = is_callable($concrete) ? $concrete() : new $concrete();

        if (!is_object($instance)) {
            throw new \RuntimeException("Concrete for {$abstract} did not produce an object.");
        }

        if ($binding['shared']) {
            $this->resolved[$abstract] = $instance;
        }

        return $instance;
    }

    protected function entityType(\Waaseyaa\Entity\EntityTypeInterface $entityType): void
    {
        $this->entityTypeRegistrations[] = [
            'entityType' => $entityType,
            'registrant' => static::class,
        ];
    }

    /** @return array<string, array{concrete: string|callable, shared: bool}> */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /** @return array<string, list<string>> */
    public function getTags(): array
    {
        return $this->tags;
    }

    /** @return list<\Waaseyaa\Entity\EntityTypeInterface> */
    public function getEntityTypes(): array
    {
        return array_map(
            static fn(array $registration): \Waaseyaa\Entity\EntityTypeInterface => $registration['entityType'],
            $this->entityTypeRegistrations,
        );
    }

    /** @return list<array{entityType: \Waaseyaa\Entity\EntityTypeInterface, registrant: class-string}> */
    public function getEntityTypeRegistrations(): array
    {
        return $this->entityTypeRegistrations;
    }
}
