<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\ServiceProvider\Capability;

use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/** Read-only, live provider-capability projection owned by the kernel. @api */
final class ProviderCapabilitySource
{
    /** @var \Closure(): list<ServiceProvider> */
    private readonly \Closure $providers;

    /** @param \Closure(): list<ServiceProvider> $providers */
    public function __construct(\Closure $providers)
    {
        $this->providers = $providers;
    }

    /**
     * @param class-string $capability
     * @return list<ServiceProvider>
     */
    public function implementing(string $capability): array
    {
        if (!interface_exists($capability)) {
            throw new \InvalidArgumentException(sprintf('Provider capability "%s" is not an interface.', $capability));
        }

        return array_values(array_filter(
            ($this->providers)(),
            static fn(ServiceProvider $provider): bool => $provider instanceof $capability,
        ));
    }
}
