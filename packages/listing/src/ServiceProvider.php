<?php

declare(strict_types=1);

namespace Waaseyaa\Listing;

use Symfony\Component\EventDispatcher\EventDispatcherInterface as SymfonyComponentEventDispatcherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface as SymfonyContractsEventDispatcherInterface;
use Waaseyaa\Access\Gate\Gate;
use Waaseyaa\Access\Gate\GateInterface;
use Waaseyaa\Cache\ContextNames;
use Waaseyaa\Cache\ContextRegistry;
use Waaseyaa\Cache\ContextResolver;
use Waaseyaa\Cache\TaggedCacheInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\EntityStorage\Event\AfterDeleteEvent;
use Waaseyaa\EntityStorage\Event\AfterSaveEvent;
use Waaseyaa\Foundation\Http\RequestContext;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider as FoundationServiceProvider;

/**
 * Listing pipeline service provider.
 *
 * Closes the M-007 implementation loop: binds every listing-pipeline service
 * via DI, registers the cache invalidator as an event listener for entity
 * lifecycle events, seeds canonical cache-context names, and runs the
 * boot-time {@see ListingDefinitionValidator} so the kernel fails fast on
 * misconfigured listings (FR-052 + FR-053).
 *
 * Boot order — {@see \Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistry}
 * runs `register()` on every provider first (entity types registered then),
 * then `boot()` on every provider. The validator therefore sees the fully
 * populated {@see EntityTypeManager} at boot time, satisfying FR-052's
 * "after entity-type registration but before route dispatch" constraint.
 *
 * Discovered listings — gathered by {@see ListingDiscoverer} from every
 * registered service provider implementing {@see HasListingsInterface}.
 * Cache invalidation — {@see ListingCacheInvalidator} subscribes to
 * {@see AfterSaveEvent} and {@see AfterDeleteEvent} via explicit
 * {@code addListener()} calls on the framework dispatcher (no
 * `#[AsEventListener]` attribute discovery).
 *
 * @api
 */
final class ServiceProvider extends FoundationServiceProvider
{
    /**
     * Event-listener priority. The codebase uses positive priorities to run
     * earlier; cache invalidation runs after most lifecycle work so the
     * default priority of 0 is intentional. Kept as a named constant for
     * test introspection.
     */
    private const EVENT_LISTENER_PRIORITY = 100;

    public function register(): void
    {
        // -----------------------------------------------------------------
        // Foundation singletons that listing depends on
        // -----------------------------------------------------------------
        $this->singleton(ContextRegistry::class, static fn(): ContextRegistry => new ContextRegistry());

        $this->singleton(
            ContextResolver::class,
            fn(): ContextResolver => new ContextResolver(
                $this->resolveContextRegistry(),
                $this->resolveLogger(),
            ),
        );

        // RequestContext is per-request state; absent an HTTP request we bind
        // a default anonymous instance. CLI and HTTP kernels override the
        // binding via setKernelServices() once a real request lands.
        $this->singleton(
            RequestContext::class,
            static fn(): RequestContext => new RequestContext(),
        );

        // -----------------------------------------------------------------
        // Listing services
        // -----------------------------------------------------------------
        $this->singleton(
            ListingDiscoverer::class,
            fn(): ListingDiscoverer => new ListingDiscoverer($this->discoverProviders()),
        );

        $this->singleton(
            ListingDefinitionRegistry::class,
            fn(): ListingDefinitionRegistry => ListingDefinitionRegistry::fromList(
                $this->resolve(ListingDiscoverer::class)->discover(),
            ),
        );

        $this->singleton(
            ListingCacheKeyBuilder::class,
            static fn(): ListingCacheKeyBuilder => new ListingCacheKeyBuilder(),
        );

        $this->singleton(
            EntityRepositoryRegistry::class,
            fn(): EntityRepositoryRegistry => $this->buildRepositoryRegistry(),
        );

        $this->singleton(
            ListingCacheInvalidator::class,
            fn(): ListingCacheInvalidator => new ListingCacheInvalidator(
                $this->resolveTaggedCache(),
                $this->resolveLogger(),
            ),
        );

        $this->singleton(
            ExposedFilterParser::class,
            static fn(): ExposedFilterParser => ExposedFilterParser::create(),
        );

        $this->singleton(
            ListingDefinitionValidator::class,
            fn(): ListingDefinitionValidator => new ListingDefinitionValidator(
                $this->resolveEntityTypeManager(),
            ),
        );

        $this->singleton(
            ListingResolver::class,
            fn(): ListingResolver => new ListingResolver(
                repositories: $this->resolve(EntityRepositoryRegistry::class),
                gate: $this->resolveGate(),
                contextResolver: $this->resolve(ContextResolver::class),
                entityTypes: $this->resolveEntityTypeManager(),
                requestContext: $this->resolve(RequestContext::class),
                cache: $this->resolveTaggedCache(),
                keyBuilder: $this->resolve(ListingCacheKeyBuilder::class),
                logger: $this->resolveLogger(),
            ),
        );
    }

    public function boot(): void
    {
        // 1. Wire the cache invalidator to entity lifecycle events.
        //    ServiceProvider-based subscription (confirmed in WP07 review):
        //    the codebase does NOT use #[AsEventListener] attribute discovery
        //    for entity events.
        $dispatcher = $this->resolveDispatcher();
        if ($dispatcher !== null) {
            $invalidator = $this->resolve(ListingCacheInvalidator::class);
            $dispatcher->addListener(
                AfterSaveEvent::class,
                $invalidator->onAfterSave(...),
                self::EVENT_LISTENER_PRIORITY,
            );
            $dispatcher->addListener(
                AfterDeleteEvent::class,
                $invalidator->onAfterDelete(...),
                self::EVENT_LISTENER_PRIORITY,
            );
        }

        // 2. Seed canonical context names with the registry. ContextRegistry
        //    seeds the well-known names in its constructor; this is a safety
        //    net for any extension-pack additions that need to be guaranteed.
        $registry = $this->resolve(ContextRegistry::class);
        foreach ($this->canonicalContextNames() as $name) {
            // register() is idempotent — re-registration is a no-op.
            $registry->register($name);
        }

        // 3. FR-052 + FR-053 — boot-time validator. Runs AFTER every
        //    provider's register() has completed (entity types registered)
        //    and AFTER every provider's earlier boot() has run (sibling
        //    services bound). Throws UnsupportedListingException on the
        //    first misconfigured listing; the kernel logs the full message
        //    and aborts boot. There is no silent fallback.
        $registryDefinitions = $this->resolve(ListingDefinitionRegistry::class);
        $validator = $this->resolve(ListingDefinitionValidator::class);
        $validator->validate($registryDefinitions);
    }

    /**
     * Canonical cache-context names this provider guarantees registered.
     *
     * @return list<non-empty-string>
     */
    private function canonicalContextNames(): array
    {
        return [
            ContextNames::USER_ROLES,
            ContextNames::USER_ID,
            ContextNames::LANGUAGE_CONTENT,
            ContextNames::LANGUAGE_INTERFACE,
        ];
    }

    /**
     * Build the iterable of registered service providers exposed to
     * {@see ListingDiscoverer}.
     *
     * Discovery strategy:
     *
     *   1. Ask the kernel-services bus for the canonical provider list
     *      under {@code ServiceProviderRegistryAccessorInterface::class}.
     *      When the host binds an accessor (or `ProviderRegistryKernelServices`
     *      grows native support), that path wins.
     *
     *   2. Fallback: introspect the kernel-services bus via reflection.
     *      The default kernel binding,
     *      {@code Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistryKernelServices},
     *      holds the live list under a private `$providersAccessor` closure.
     *      Reading it reflectively keeps the listing pipeline self-contained
     *      until foundation grows a typed accessor.
     *
     *   3. Final fallback: empty list. The registry resolves to an empty
     *      {@see ListingDefinitionRegistry}; validator runs as a no-op and
     *      boot succeeds even when no host has declared listings.
     *
     * @return iterable<object>
     */
    private function discoverProviders(): iterable
    {
        if ($this->kernelServices === null) {
            return [];
        }

        // Reflection fallback against ProviderRegistryKernelServices. The
        // foundation owns the bus implementation; reading the private
        // accessor is intentional and documented above.
        try {
            $reflection = new \ReflectionObject($this->kernelServices);
            if (!$reflection->hasProperty('providersAccessor')) {
                return [];
            }
            $property = $reflection->getProperty('providersAccessor');
            $accessor = $property->getValue($this->kernelServices);
            if (!$accessor instanceof \Closure) {
                return [];
            }
            $providers = $accessor();

            return is_array($providers) ? $providers : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Best-effort resolution of the framework dispatcher.
     *
     * The listing pipeline subscribes via `addListener()` which lives on the
     * Symfony **Component** interface (not the bare Contracts interface).
     * Foundation's `SymfonyEventDispatcherAdapter` implements both, so any of
     * the three commonly-bound abstracts resolves to a dispatcher capable of
     * `addListener()`.
     */
    private function resolveDispatcher(): ?SymfonyComponentEventDispatcherInterface
    {
        $candidate = $this->kernelServices?->get(SymfonyComponentEventDispatcherInterface::class);
        if ($candidate instanceof SymfonyComponentEventDispatcherInterface) {
            return $candidate;
        }
        $candidate = $this->kernelServices?->get(SymfonyContractsEventDispatcherInterface::class);
        if ($candidate instanceof SymfonyComponentEventDispatcherInterface) {
            return $candidate;
        }
        $candidate = $this->kernelServices?->get(\Waaseyaa\Foundation\Event\EventDispatcherInterface::class);
        if ($candidate instanceof SymfonyComponentEventDispatcherInterface) {
            return $candidate;
        }

        return null;
    }

    private function resolveEntityTypeManager(): EntityTypeManager
    {
        $candidate = $this->kernelServices?->get(EntityTypeManager::class);
        if ($candidate instanceof EntityTypeManager) {
            return $candidate;
        }
        $candidate = $this->kernelServices?->get(EntityTypeManagerInterface::class);
        if ($candidate instanceof EntityTypeManager) {
            return $candidate;
        }

        throw new \RuntimeException(
            'Listing\\ServiceProvider: no EntityTypeManager bound on the kernel-services bus. '
            . 'The listing pipeline requires Waaseyaa\\Entity\\EntityTypeManager to be reachable via setKernelServices().',
        );
    }

    private function resolveLogger(): LoggerInterface
    {
        $candidate = $this->kernelServices?->get(LoggerInterface::class);

        return $candidate instanceof LoggerInterface ? $candidate : new NullLogger();
    }

    /**
     * Resolve the {@see TaggedCacheInterface} binding from the kernel.
     *
     * The cache binding is host-provided. When no tagged cache is bound the
     * listing pipeline degrades to uncached operation (FR-058) — the
     * resolver simply skips the lookup/store path.
     */
    private function resolveTaggedCache(): ?TaggedCacheInterface
    {
        $candidate = $this->kernelServices?->get(TaggedCacheInterface::class);

        return $candidate instanceof TaggedCacheInterface ? $candidate : null;
    }

    /**
     * Resolve a {@see GateInterface}. The kernel-services bus binds a shared
     * {@see \Waaseyaa\Access\Gate\EntityAccessGate} once the kernel's access
     * handler is available (G-014 / #1940 —
     * `ProviderRegistryKernelServices::get()`); before that point, or when no
     * kernel context is wired at all, the bus resolves `null`. In that case
     * we fall back to a no-policy {@see Gate} (every access op is denied,
     * which the resolver handles as "row hidden") as defense in depth — this
     * keeps the listing pipeline fail-closed even if a future host wires a
     * kernel without an access handler. Host applications wiring real
     * policies get the shared kernel gate automatically; they no longer need
     * to bind their own ServiceProvider for this.
     */
    private function resolveGate(): GateInterface
    {
        $candidate = $this->kernelServices?->get(GateInterface::class);
        if ($candidate instanceof GateInterface) {
            return $candidate;
        }

        return new Gate([]);
    }

    private function resolveContextRegistry(): ContextRegistry
    {
        return $this->resolve(ContextRegistry::class);
    }

    /**
     * Build the {@see EntityRepositoryRegistry} from the live
     * {@see EntityTypeManager}, lazily fetching one
     * {@see \Waaseyaa\EntityStorage\EntityRepository} per registered entity
     * type. Any type whose repository factory is not configured is silently
     * skipped; the resolver only touches repositories for the entity types
     * referenced by registered listings.
     */
    private function buildRepositoryRegistry(): EntityRepositoryRegistry
    {
        $manager = $this->resolveEntityTypeManager();
        $registry = new EntityRepositoryRegistry();
        foreach ($manager->getDefinitions() as $typeId => $_definition) {
            try {
                $repository = $manager->getRepository($typeId);
            } catch (\Throwable) {
                // Type without a repository factory — skip; listings against
                // it would fail at resolve-time with a clear error from
                // EntityRepositoryRegistry::for().
                continue;
            }
            if ($repository instanceof \Waaseyaa\EntityStorage\EntityRepository) {
                /** @var non-empty-string $typeId */
                $registry->register($typeId, $repository);
            }
        }

        return $registry;
    }
}
