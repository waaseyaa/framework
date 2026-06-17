<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools;

use Psr\Container\ContainerInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Tools\Catalogue\AttributeToolRegistry;
use Waaseyaa\AI\Tools\Catalogue\AutowiringToolContainer;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Registers the {@see AttributeToolRegistry} singleton implementing
 * {@see ToolRegistryInterface} alongside the eight stock tool
 * implementations shipped by this package.
 *
 * The registry is constructed lazily; concrete tool classes are
 * instantiated by the kernel container on first call to
 * {@see AttributeToolRegistry::all()} or {@see AttributeToolRegistry::get()}.
 *
 * @api
 */
final class AiToolsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(ToolRegistryInterface::class, function (): ToolRegistryInterface {
            $manifest = $this->resolveManifest();
            $container = $this->resolveContainer();
            $logger = $this->resolveLogger();

            return new AttributeToolRegistry(
                manifest: $manifest,
                container: $container,
                logger: $logger,
                // C-12: inject the kernel access handler so every stock entity
                // tool enforces per-entity AccessPolicy. Lazy — resolved at
                // hydration (after AbstractKernel::discoverAccessPolicies), and
                // non-null here always means production wiring is requested, so
                // the registry stamps fail-closed enforcement on each tool even
                // when the handler itself transiently resolves to null.
                accessHandlerResolver: fn(): ?EntityAccessHandler => $this->resolveAccessHandler(),
            );
        });

        $this->singleton(AttributeToolRegistry::class, function (): AttributeToolRegistry {
            $registry = $this->resolve(ToolRegistryInterface::class);
            \assert($registry instanceof AttributeToolRegistry);

            return $registry;
        });
    }

    private function resolveManifest(): PackageManifest
    {
        $manifest = $this->kernelServices?->get(PackageManifest::class);
        if ($manifest instanceof PackageManifest) {
            return $manifest;
        }
        // Empty fallback for early-boot / test paths without a kernel.
        return new PackageManifest();
    }

    private function resolveContainer(): ContainerInterface
    {
        $container = $this->kernelServices?->get(ContainerInterface::class);
        if ($container instanceof ContainerInterface) {
            return $container;
        }

        // #[AsAgentTool] classes are not container-bound, so the registry needs a
        // container that can autowire them: resolve from the kernel-services bus
        // (core services + any provider binding), then reflection-instantiate the
        // tool with its constructor deps. See AutowiringToolContainer.
        return new AutowiringToolContainer($this->kernelServices, $this);
    }

    private function resolveLogger(): LoggerInterface
    {
        $logger = $this->kernelServices?->get(LoggerInterface::class);

        return $logger instanceof LoggerInterface ? $logger : new NullLogger();
    }

    /**
     * Resolve the kernel's per-entity access handler from the kernel-services
     * bus (exposed by {@see \Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistryKernelServices}
     * after access-policy discovery). Returns null when the bus cannot supply
     * one; the registry still stamps fail-closed enforcement in that case.
     */
    private function resolveAccessHandler(): ?EntityAccessHandler
    {
        $handler = $this->kernelServices?->get(EntityAccessHandler::class);

        return $handler instanceof EntityAccessHandler ? $handler : null;
    }
}
