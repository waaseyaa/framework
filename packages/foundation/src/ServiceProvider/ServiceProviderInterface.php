<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\ServiceProvider;

/**
 * Public contract every Waaseyaa service provider implements.
 *
 * Enumerates every hook the kernel and bootstrap layer call on a provider.
 * The abstract {@see ServiceProvider} base implements every method (no-op
 * defaults where appropriate); subclasses override what they need.
 *
 * **Stability.** Adding a method here is a breaking change for third-party
 * providers. The contract test at
 * `packages/foundation/tests/Contract/ServiceProviderContractTest.php` keeps
 * this interface, the abstract base, and the kernel call sites in lockstep.
 *
 * **Capability split.** HTTP-only hooks (routes, middleware, configureHttpKernel,
 * etc.) currently live here for compatibility with the existing kernel; a
 * follow-on surface will split them into capability interfaces dispatched via
 * `instanceof` (see {@see \Waaseyaa\Foundation\Http\LanguagePathStripperInterface}
 * for the established pattern).
 * @api
 */
interface ServiceProviderInterface
{
    /**
     * Bind services. Called once per registration pass after `setKernelContext`
     * and `setKernelServices`. Idempotent.
     */
    public function register(): void;

    /**
     * Late wiring after every provider has registered. Subscribe to events,
     * warm caches, etc.
     */
    public function boot(): void;

    /**
     * Contribute HTTP routes. Called by `BuiltinRouteRegistrar` after every
     * provider is registered.
     *
     * **Called exactly once, at boot.** Anything you build here lives for the
     * lifetime of the process. Do NOT eagerly `resolve()` and construct a
     * controller in this method when that controller depends on a
     * request-scoped service such as `DatabaseInterface` — the controller
     * would capture the boot-time instance and reuse it for every request,
     * so its writes can be silently lost (#1611). Instead defer construction
     * to dispatch time: register a closure controller that resolves its
     * dependencies when the route is matched. See
     * {@see \Waaseyaa\AI\Agent\Routing\AgentRouteServiceProvider} for the
     * canonical lazy-factory pattern.
     */
    public function routes(\Waaseyaa\Routing\WaaseyaaRouter $router, \Waaseyaa\Entity\EntityTypeManager $entityTypeManager): void;

    /**
     * Service ids this provider intends to bind. Used for deferred providers.
     *
     * @return list<string>
     */
    public function provides(): array;

    /**
     * `true` when the provider can defer `register()` until one of its
     * `provides()` services is requested.
     */
    public function isDeferred(): bool;

    /**
     * Local bindings registered by this provider, keyed by abstract.
     *
     * @return array<string, array{concrete: string|callable, shared: bool}>
     */
    public function getBindings(): array;

    /**
     * Resolve a binding registered via `singleton()` or `bind()`. Falls back
     * to the kernel-services bus for unbound abstracts; throws when neither
     * matches.
     *
     * @throws \RuntimeException When `$abstract` is not bound locally and the
     *                           kernel-services bus does not provide it.
     */
    public function resolve(string $abstract): object;

    /**
     * Provide the kernel-derived context the provider needs during
     * `register()`. Always called by `ProviderRegistry` before `register()`.
     *
     * @param array<string, mixed>        $config
     * @param array<string, class-string> $manifestFormatters
     */
    public function setKernelContext(string $projectRoot, array $config, array $manifestFormatters): void;

    /**
     * Provide the typed kernel-services bus that backs `resolve()` fallbacks
     * for abstracts the provider has not bound locally.
     */
    public function setKernelServices(KernelServicesInterface $services): void;

    /**
     * Entity-type registrations contributed by this provider.
     *
     * @return list<array{entityType: \Waaseyaa\Entity\EntityTypeInterface, registrant: class-string}>
     */
    public function getEntityTypeRegistrations(): array;
}
