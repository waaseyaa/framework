<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Host;

/**
 * Supplies the application's own admin surface host.
 *
 * Bind an implementation in your service provider's `register()`. The framework
 * resolves it in {@see \Waaseyaa\AdminSurface\AdminSurfaceServiceProvider::routes()}
 * and registers the canonical `admin_surface.*` routes against the host it
 * returns, instead of the default `GenericAdminSurfaceHost`. Everything else —
 * paths, HTTP methods, authentication requirements, and refusal-status
 * promotion — stays the framework's.
 *
 * A factory rather than the host itself, because a real application host needs
 * services that sibling providers bind during their own `register()`. `routes()`
 * runs after every provider is registered, so building the host there is safe;
 * building it during `register()` is not.
 *
 * Exactly one factory is supported. An application that binds none keeps the
 * generic host and is unaffected.
 *
 * @api
 */
interface AdminSurfaceHostFactoryInterface
{
    /**
     * Build the host that serves the canonical admin surface routes.
     *
     * Called once, at boot, during route registration.
     */
    public function createAdminSurfaceHost(): AbstractAdminSurfaceHost;
}
