<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel\Http;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Http\BindingAwareHttpServiceResolverInterface;
use Waaseyaa\Foundation\Http\HttpServiceResolverInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Default {@see HttpServiceResolverInterface} backed by the kernel's
 * registered providers plus {@see KernelServicesInterface} for the narrow
 * kernel-owned fallback (currently {@see DatabaseInterface} only).
 *
 * The provider list is read through a closure accessor so each resolution
 * sees the live registration state — important when SSR resolves a class
 * name from an app-controller signature after later providers have
 * registered additional bindings.
 *
 * Mirrors the typed-resolver pattern introduced for `KernelServicesInterface`
 * in mission #824 WP02 surface A; the two contracts are intentionally
 * separate because the lookup semantics differ (finite/internal vs
 * open/user-driven).
 */
final class HttpKernelServiceResolver implements HttpServiceResolverInterface, BindingAwareHttpServiceResolverInterface
{
    /** @var \Closure(): list<ServiceProvider> */
    private \Closure $providersAccessor;

    /**
     * @param \Closure(): list<ServiceProvider> $providersAccessor
     */
    public function __construct(
        \Closure $providersAccessor,
        private readonly KernelServicesInterface $kernelServices,
        private readonly LoggerInterface $logger,
    ) {
        $this->providersAccessor = $providersAccessor;
    }

    public function resolve(string $className): ?object
    {
        foreach (($this->providersAccessor)() as $provider) {
            if (!isset($provider->getBindings()[$className])) {
                continue;
            }
            try {
                $resolved = $provider->resolve($className);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('Failed to resolve %s: %s', $className, $e->getMessage()));

                return null;
            }

            return $resolved;
        }

        if ($className === DatabaseInterface::class) {
            $resolved = $this->kernelServices->get(DatabaseInterface::class);

            return $resolved instanceof DatabaseInterface ? $resolved : null;
        }

        return null;
    }

    public function hasBinding(string $className): bool
    {
        foreach (($this->providersAccessor)() as $provider) {
            if (isset($provider->getBindings()[$className])) {
                return true;
            }
        }

        return $className === DatabaseInterface::class;
    }

    public function resolveBound(string $className): object
    {
        foreach (($this->providersAccessor)() as $provider) {
            if (isset($provider->getBindings()[$className])) {
                return $provider->resolve($className);
            }
        }

        if ($className === DatabaseInterface::class) {
            $resolved = $this->kernelServices->get(DatabaseInterface::class);
            if ($resolved instanceof DatabaseInterface) {
                return $resolved;
            }
        }

        throw new \LogicException(sprintf('Service %s is not bound.', $className));
    }
}
