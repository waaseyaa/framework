<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji;

use Symfony\Component\Routing\RouteCollection;
use Waaseyaa\Bimaaji\Graph\ApplicationGraphGenerator;
use Waaseyaa\Bimaaji\Graph\GraphSectionProviderInterface;
use Waaseyaa\Bimaaji\Introspection\Admin\AdminIntrospectionProvider;
use Waaseyaa\Bimaaji\Introspection\Entity\EntityIntrospectionProvider;
use Waaseyaa\Bimaaji\Introspection\JsonApi\JsonApiIntrospectionProvider;
use Waaseyaa\Bimaaji\Introspection\PublicSurface\PublicSurfaceProvider;
use Waaseyaa\Bimaaji\Introspection\Routing\RoutingIntrospectionProvider;
use Waaseyaa\Bimaaji\Introspection\Sovereignty\SovereigntyIntrospectionProvider;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasNativeCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider as FoundationServiceProvider;
use Waaseyaa\Foundation\Sovereignty\SovereigntyConfigInterface;
use Waaseyaa\Foundation\Sovereignty\SovereigntyProfile;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * Bimaaji service provider — wires the application graph generator plus the
 * six default {@see GraphSectionProviderInterface} implementations into the
 * container.
 *
 * After this provider lands, `ApplicationGraphGenerator` is reachable from the
 * container with the six default sections (admin, entities, jsonapi,
 * public_surface, routing, sovereignty) pre-wired. Consumers (CLI commands,
 * MCP tools, embedded agents) call `$generator->generate()` to obtain a
 * {@see Waaseyaa\Bimaaji\Graph\ApplicationGraph} snapshot.
 *
 * Third-party packages may add their own section providers by binding a
 * service that implements `GraphSectionProviderInterface` and including its
 * FQCN in the kernel-services bus under a custom key, then composing it in
 * via their own ServiceProvider's `register()` (the framework does not yet
 * support generic tagged-service resolution).
 *
 * Implements {@see HasNativeCommandsInterface} so future CLI command
 * discovery (WP02 of mission bimaaji-wakeup-01KS5VEY) can attach the
 * `graph:dump` command without an additional discovery layer.
 *
 * @api
 */
final class BimaajiServiceProvider extends FoundationServiceProvider implements HasNativeCommandsInterface
{
    public function register(): void
    {
        // 1. Bind the six default GraphSectionProvider implementations as
        //    singletons. Each provider's constructor deps come from the
        //    kernel-services bus.
        $this->singleton(
            AdminIntrospectionProvider::class,
            fn(): AdminIntrospectionProvider => new AdminIntrospectionProvider(
                $this->resolveEntityTypeManager(),
            ),
        );

        $this->singleton(
            EntityIntrospectionProvider::class,
            fn(): EntityIntrospectionProvider => new EntityIntrospectionProvider(
                $this->resolveEntityTypeManager(),
            ),
        );

        $this->singleton(
            JsonApiIntrospectionProvider::class,
            fn(): JsonApiIntrospectionProvider => new JsonApiIntrospectionProvider(
                $this->resolveRouteCollection(),
            ),
        );

        $this->singleton(
            PublicSurfaceProvider::class,
            fn(): PublicSurfaceProvider => new PublicSurfaceProvider(
                $this->resolveRouteCollection(),
            ),
        );

        $this->singleton(
            RoutingIntrospectionProvider::class,
            fn(): RoutingIntrospectionProvider => new RoutingIntrospectionProvider(
                $this->resolveRouteCollection(),
            ),
        );

        $this->singleton(
            SovereigntyIntrospectionProvider::class,
            fn(): SovereigntyIntrospectionProvider => new SovereigntyIntrospectionProvider(
                $this->resolveSovereigntyProfile(),
            ),
        );

        // 2. Tag each provider under the canonical "bimaaji.section_provider"
        //    tag. The base ServiceProvider does not currently expand tagged
        //    collections at resolve-time, but the tag is recorded so future
        //    versions of the container can iterate. ApplicationGraphGenerator
        //    receives the explicit list via the factory below.
        $this->tag(AdminIntrospectionProvider::class, self::SECTION_PROVIDER_TAG);
        $this->tag(EntityIntrospectionProvider::class, self::SECTION_PROVIDER_TAG);
        $this->tag(JsonApiIntrospectionProvider::class, self::SECTION_PROVIDER_TAG);
        $this->tag(PublicSurfaceProvider::class, self::SECTION_PROVIDER_TAG);
        $this->tag(RoutingIntrospectionProvider::class, self::SECTION_PROVIDER_TAG);
        $this->tag(SovereigntyIntrospectionProvider::class, self::SECTION_PROVIDER_TAG);

        // 3. Bind the generator. The factory composes the six defaults into
        //    an iterable. Logger and strict-mode default to NullLogger and
        //    false respectively; consumers can override by re-binding.
        $this->singleton(
            ApplicationGraphGenerator::class,
            fn(): ApplicationGraphGenerator => new ApplicationGraphGenerator(
                providers: $this->defaultSectionProviders(),
                logger: $this->resolveLogger(),
                strict: false,
            ),
        );
    }

    /**
     * The canonical tag attached to every default section provider. Third
     * parties wiring their own providers may use the same tag for symmetry
     * (the framework does not yet expand tagged collections at resolve
     * time, so the tag is informational until container support arrives).
     *
     * @api
     */
    public const string SECTION_PROVIDER_TAG = 'bimaaji.section_provider';

    /**
     * Native commands exported by this provider. WP02 of mission
     * bimaaji-wakeup-01KS5VEY adds the `graph:dump` command; this stub
     * keeps the {@see HasNativeCommandsInterface} contract satisfied
     * without forward-referencing classes that do not yet exist.
     *
     * @return iterable<\Waaseyaa\CLI\CommandDefinition>
     */
    public function nativeCommands(): iterable
    {
        return [];
    }

    /**
     * Build the iterable of default section providers in canonical order.
     * Order is intentional: identity-bearing sections (admin, entities) come
     * before transport sections (jsonapi, public_surface, routing), with
     * sovereignty last so its presence is the final entry an agent reads.
     *
     * @return list<GraphSectionProviderInterface>
     */
    private function defaultSectionProviders(): array
    {
        return [
            $this->resolve(AdminIntrospectionProvider::class),
            $this->resolve(EntityIntrospectionProvider::class),
            $this->resolve(JsonApiIntrospectionProvider::class),
            $this->resolve(PublicSurfaceProvider::class),
            $this->resolve(RoutingIntrospectionProvider::class),
            $this->resolve(SovereigntyIntrospectionProvider::class),
        ];
    }

    private function resolveEntityTypeManager(): EntityTypeManagerInterface
    {
        $candidate = $this->kernelServices?->get(EntityTypeManagerInterface::class);
        if ($candidate instanceof EntityTypeManagerInterface) {
            return $candidate;
        }
        $candidate = $this->kernelServices?->get(EntityTypeManager::class);
        if ($candidate instanceof EntityTypeManagerInterface) {
            return $candidate;
        }

        throw new \RuntimeException(
            'Bimaaji\\BimaajiServiceProvider: no EntityTypeManager bound on the kernel-services bus. '
            . 'Admin and entity introspection require Waaseyaa\\Entity\\EntityTypeManagerInterface to be reachable via setKernelServices().',
        );
    }

    private function resolveRouteCollection(): RouteCollection
    {
        $candidate = $this->kernelServices?->get(RouteCollection::class);
        if ($candidate instanceof RouteCollection) {
            return $candidate;
        }
        $router = $this->kernelServices?->get(WaaseyaaRouter::class);
        if ($router instanceof WaaseyaaRouter) {
            return $router->getRouteCollection();
        }

        throw new \RuntimeException(
            'Bimaaji\\BimaajiServiceProvider: no RouteCollection or WaaseyaaRouter bound on the kernel-services bus. '
            . 'JsonAPI, public-surface, and routing introspection require Symfony\\Component\\Routing\\RouteCollection.',
        );
    }

    private function resolveSovereigntyProfile(): SovereigntyProfile
    {
        $config = $this->kernelServices?->get(SovereigntyConfigInterface::class);
        if ($config instanceof SovereigntyConfigInterface) {
            return $config->getProfile();
        }

        // Default to Local when no sovereignty config is bound. This is the
        // safe fallback for development environments that have not yet
        // declared a profile; production kernels bind SovereigntyConfigInterface
        // explicitly during boot.
        return SovereigntyProfile::Local;
    }

    private function resolveLogger(): LoggerInterface
    {
        $candidate = $this->kernelServices?->get(LoggerInterface::class);

        return $candidate instanceof LoggerInterface ? $candidate : new NullLogger();
    }
}
