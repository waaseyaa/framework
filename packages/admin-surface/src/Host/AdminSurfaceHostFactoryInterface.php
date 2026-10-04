<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Host;

/**
 * Supplies the application's own admin surface host behind canonical routes.
 *
 * Bind one implementation during register(). Canonical HTTP execution calls
 * the factory only after matching an Admin Surface operation, when every
 * sibling binding is ready. The nonshared request adapter calls it per selected
 * construction; the factory owns any intentional host reuse. The legacy bare
 * routes() entry point still calls it once during registration.
 *
 * Paths, gates and response promotion remain framework-owned. Absence keeps
 * the generic host; an unhealthy declared canonical factory refuses selection.
 * @api
 */
interface AdminSurfaceHostFactoryInterface
{
    /**
     * Build the host that serves the canonical admin surface routes.
     *
     * Called on selected canonical construction, or once by legacy route registration.
     */
    public function createAdminSurfaceHost(): AbstractAdminSurfaceHost;
}
