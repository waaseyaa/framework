<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\ServiceProvider\Capability;

/**
 * Capability marker for the service provider that owns the migration registry
 * and accepts the kernel-discovered migration providers.
 *
 * The kernel discovers every provider that exposes application migrations and
 * hands the collection to the provider implementing this interface, before that
 * provider's `boot()` eagerly resolves the migration registry. The provider opts
 * in by declaring `implements AcceptsMigrationProvidersInterface`;
 * `Waaseyaa\Foundation\Kernel\AbstractKernel::injectMigrationProviders()` checks
 * `instanceof` before invoking `withMigrationProviders()`, so the abstract
 * `Waaseyaa\Foundation\ServiceProvider\ServiceProvider` carries no unused no-op
 * default.
 *
 * The interface lives in Foundation (Layer 0) so the kernel can guard the call
 * site without a compile-time edge to the Layer-3 migration package, and so the
 * migration package opts in via a downward dependency.
 *
 * Locked in step with the kernel call site by
 * `packages/foundation/tests/Contract/ServiceProviderContractTest.php`.
 *
 * @api
 */
interface AcceptsMigrationProvidersInterface
{
    /**
     * Accept the kernel-discovered migration providers (objects exposing
     * application migrations) before the registry is resolved.
     *
     * @param list<object> $providers
     */
    public function withMigrationProviders(array $providers): void;
}
