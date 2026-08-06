<?php

declare(strict_types=1);

namespace Waaseyaa\Testing\Traits;

/**
 * Trait that boots and shuts down the Waaseyaa application for tests.
 *
 * Provides a minimal bootstrap that creates an in-memory SQLite
 * database and a default configuration. Override bootApplication()
 * in your test class for custom setup.
 *
 * @api
 * @deprecated since 0.1.0-alpha.288. Use KernelServicesFixture with real
 *   service-provider contracts; this trait is a compatibility-only service bag.
 */
trait CreatesApplication
{
    /**
     * Application-level services created during bootstrap.
     *
     * @var array<string, object>
     */
    private array $services = [];

    /**
     * Boot the application for the current test.
     *
     * Override this method in your test class to customize
     * which services are created during bootstrap.
     */
    protected function bootApplication(): void
    {
        // Default implementation is a no-op. Subclasses override to
        // wire up the services they need (entity type manager, router, etc.).
    }

    /**
     * Shut down the application after the current test.
     *
     * Clears all registered services.
     */
    protected function shutdownApplication(): void
    {
        $this->services = [];
    }

    /**
     * Register a service instance by name.
     */
    protected function registerService(string $name, object $service): void
    {
        $this->services[$name] = $service;
    }

    /**
     * Retrieve a registered service by name.
     *
     * @throws \RuntimeException When the service is not registered.
     */
    protected function getService(string $name): object
    {
        if (!isset($this->services[$name])) {
            throw new \RuntimeException(
                sprintf('Service "%s" is not registered. Did you forget to call registerService()?', $name),
            );
        }

        return $this->services[$name];
    }

    /**
     * Check whether a service is registered.
     */
    protected function hasService(string $name): bool
    {
        return isset($this->services[$name]);
    }
}
