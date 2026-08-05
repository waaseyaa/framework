<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools;

use Psr\Container\ContainerInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Tools\Catalogue\AttributeToolRegistry;
use Waaseyaa\AI\Tools\Catalogue\AutowiringToolContainer;
use Waaseyaa\AI\Tools\ContentSearch\ContentSearchTool;
use Waaseyaa\AI\Tools\ContentSearch\SearchPackageContentSearchAdapter;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\ServiceProvider\Capability\AcceptsAgentToolProvidersInterface;
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
final class AiToolsServiceProvider extends ServiceProvider implements AcceptsAgentToolProvidersInterface
{
    /** @var list<ProvidesAgentToolsInterface> */
    private array $agentToolProviders = [];

    public function withAgentToolProviders(array $providers): void
    {
        $this->agentToolProviders = array_values(array_filter(
            $providers,
            static fn(object $provider): bool => $provider instanceof ProvidesAgentToolsInterface,
        ));
    }

    public function register(): void
    {
        $this->singleton(ToolRegistryInterface::class, function (): ToolRegistryInterface {
            $manifest = $this->resolveManifest();
            $container = $this->resolveContainer();
            $logger = $this->resolveLogger();

            $registry = new AttributeToolRegistry(
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

            foreach ($this->agentToolProviders as $provider) {
                $provider->registerAgentTools($registry);
            }

            // Installing Search is the explicit composition opt-in for the
            // principal-safe rich search tool. Availability probes only touch
            // autoload metadata: resolving SearchProviderInterface here would
            // open the database during boot or tools/list (#1611). The service
            // is resolved lazily by the tool for the acting request instead.
            if (SearchPackageContentSearchAdapter::isAvailable()) {
                $services = $this->kernelServices;
                $impl = new ContentSearchTool(static function () use ($services): object {
                    if ($services === null) {
                        throw new \RuntimeException('The kernel-services bus is unavailable.');
                    }
                    $provider = $services->get(SearchPackageContentSearchAdapter::providerServiceId());
                    if (!is_object($provider)) {
                        throw new \RuntimeException('The optional content search service binding is unavailable.');
                    }

                    return $provider;
                });
                $impl->setLogger($logger);
                $registry->register(new AgentTool(
                    name: 'content.search',
                    capability: 'tool.content.search',
                    destructive: false,
                    dryRunSupported: false,
                    category: 'content',
                    inputSchema: $impl->inputSchema(),
                    impl: $impl,
                    title: 'Search CMS content',
                    outputSchema: ContentSearchTool::outputSchema(),
                    idempotent: true,
                    openWorld: false,
                ));
            }

            return $registry;
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
