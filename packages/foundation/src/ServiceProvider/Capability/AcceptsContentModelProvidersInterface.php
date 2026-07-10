<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\ServiceProvider\Capability;

/**
 * Capability marker for the service provider that owns the content-model
 * registrar and accepts the kernel-discovered content-model providers.
 *
 * Mirrors {@see AcceptsMigrationProvidersInterface} exactly, one layer over:
 * the kernel discovers every registered provider that implements the
 * migration package's `Waaseyaa\Migration\ContentModel\DerivesContentModelInterface`
 * and hands the collection to the provider implementing this interface,
 * before that provider's collaborators run. The provider opts in by
 * declaring `implements AcceptsContentModelProvidersInterface`;
 * `Waaseyaa\Foundation\Kernel\AbstractKernel::injectContentModelProviders()`
 * checks `instanceof` before invoking `withContentModelProviders()`, so the
 * abstract `Waaseyaa\Foundation\ServiceProvider\ServiceProvider` carries no
 * unused no-op default.
 *
 * Collection happens at boot (cheap — object references only); invocation of
 * `deriveContentModel()` and registration through the registrar happens at
 * import time (see `Waaseyaa\Migration\Runner\MigrationRunner`), not here.
 * This split is what fixes the pass-1 first-boot failure (G-026, #1940): a
 * provider constructed and invoked during `AbstractKernel::boot()`'s
 * schema-sync phase can find its destination tables not yet materialized;
 * a provider merely *collected* at boot and *invoked* at the first import
 * command never hits that window.
 *
 * The interface lives in Foundation (Layer 0) so the kernel can guard the
 * call site without a compile-time edge to the Layer-3 migration package,
 * and so the migration package opts in via a downward dependency.
 *
 * @api
 */
interface AcceptsContentModelProvidersInterface
{
    /**
     * Accept the kernel-discovered content-model providers (objects able to
     * derive an import-time content model from their source) before any
     * import command invokes them.
     *
     * @param list<object> $providers
     */
    public function withContentModelProviders(array $providers): void;
}
