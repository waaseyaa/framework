<?php

declare(strict_types=1);

namespace Waaseyaa\SSR;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Waaseyaa\Access\AccountPrincipalFactoryInterface;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\Access\ErrorPageRendererInterface;
use Waaseyaa\Access\Gate\EntityAccessGate;
use Waaseyaa\Api\InternalFieldVisibilityPolicy;
use Waaseyaa\Cache\CacheBackendInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
use Waaseyaa\Foundation\Http\LanguagePathStripperInterface;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Foundation\Kernel\RuntimePolicy;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Routing\Metadata\HandlerReference;
use Waaseyaa\Foundation\Routing\Metadata\RouteContributionContext;
use Waaseyaa\Foundation\Routing\Metadata\RouteDefinition;
use Waaseyaa\Foundation\ServiceProvider\Capability\ConfiguresHttpKernelInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\ContributesRouteMetadataInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasHttpDomainRoutersInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasRenderCacheListenersInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteMetadataCompiler;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\Seo\Discovery\CrawlEligibilityPolicyInterface;
use Waaseyaa\Seo\Discovery\DiscoveryFailurePolicy;
use Waaseyaa\Seo\Discovery\PublicUrlPolicyInterface;
use Waaseyaa\Seo\Discovery\SitemapContributorInterface;
use Waaseyaa\SSR\Flash\Flash;
use Waaseyaa\SSR\Flash\FlashMessageService;
use Waaseyaa\SSR\Http\CanonicalPublicOrigin;
use Waaseyaa\SSR\Http\Router\AppControllerRouter;
use Waaseyaa\SSR\Http\Router\SsrRouter;
use Waaseyaa\SSR\Http\SeoPublicController;
use Waaseyaa\SSR\Twig\FlashTwigExtension;
use Waaseyaa\Workflows\EditorialVisibilityResolver;

final class SsrServiceProvider extends ServiceProvider implements ContributesRouteMetadataInterface, ConfiguresHttpKernelInterface, HasHttpDomainRoutersInterface, HasRenderCacheListenersInterface, LanguagePathStripperInterface
{
    private static ?Environment $twigEnvironment = null;
    private static ?FieldFormatterRegistry $formatterRegistry = null;

    private ?Environment $kernelTwigEnvironment = null;

    private ?RenderCache $renderCache = null;

    private ?SsrPageHandler $ssrPageHandler = null;

    public function register(): void
    {
        $this->bind(SeoPublicController::class, fn(): SeoPublicController => $this->createSeoPublicController());
        $canonicalOrigin = CanonicalPublicOrigin::tryFromTrustedConfig($this->config);
        if ($canonicalOrigin !== null) {
            $this->singleton(
                CanonicalPublicOrigin::class,
                static fn(): CanonicalPublicOrigin => $canonicalOrigin,
            );
        }

        // Always bound, so SeoPublicController receives an explicit policy rather
        // than inferring one. An unrecognised `seo.failure_policy` throws here, at
        // boot, instead of silently resolving to the more permissive case.
        $failurePolicy = DiscoveryFailurePolicy::fromConfig($this->config);
        $this->singleton(
            DiscoveryFailurePolicy::class,
            static fn(): DiscoveryFailurePolicy => $failurePolicy,
        );

        if ($this->projectRoot !== '') {
            $this->kernelTwigEnvironment = ThemeServiceProvider::getTwigEnvironment()
                ?? self::createTwigEnvironment($this->projectRoot, $this->config);
            self::$twigEnvironment = $this->kernelTwigEnvironment;
        }

        $this->singleton(ErrorPageRendererInterface::class, function (): ErrorPageRendererInterface {
            $twig = $this->kernelTwigEnvironment ?? self::getTwigEnvironment();
            if ($twig !== null) {
                return new TwigErrorPageRenderer($twig);
            }

            return new class implements ErrorPageRendererInterface {
                public function render(int $statusCode, string $title, string $detail, Request $request): ?Response
                {
                    return null;
                }
            };
        });

        // Expose the Twig environment as a container service so lower layers
        // (e.g. the user package's AuthMailer) can render templates without
        // statically reaching up into this Layer-6 provider. Resolved lazily;
        // throws when no environment is available so a caller's resolveOptional()
        // degrades to null rather than receiving a non-object.
        $this->singleton(Environment::class, function (): Environment {
            $twig = $this->kernelTwigEnvironment ?? self::getTwigEnvironment();
            if ($twig === null) {
                throw new \RuntimeException('SSR Twig environment is not available.');
            }

            return $twig;
        });
    }

    public function boot(): void
    {
        if ($this->projectRoot === '') {
            return;
        }

        $this->kernelTwigEnvironment ??= ThemeServiceProvider::getTwigEnvironment()
            ?? self::createTwigEnvironment($this->projectRoot, $this->config);
        self::$twigEnvironment = $this->kernelTwigEnvironment;
        self::$formatterRegistry = new FieldFormatterRegistry($this->manifestFormatters);

        $flashService = new FlashMessageService();
        Flash::setService($flashService);
        if (self::$twigEnvironment !== null) {
            self::$twigEnvironment->addExtension(new FlashTwigExtension($flashService));
        }
    }

    /** Pure crawler route declarations, shared with the compatibility projection. */
    public function routeDefinitions(RouteContributionContext $context): iterable
    {
        foreach ([
            ['seo.robots_txt', '/robots.txt', 'robotsTxt'],
            ['seo.sitemap_xml', '/sitemap.xml', 'sitemapXml'],
            ['seo.llms_txt', '/llms.txt', 'llmsTxt'],
        ] as $ordinal => [$name, $path, $method]) {
            yield new RouteDefinition(
                $name,
                $path,
                HandlerReference::fromString('class:' . SeoPublicController::class . '::' . $method),
                methods: ['GET'],
                options: ['_public' => true],
                priority: 10,
                sourceId: $context->sourceId,
                ordinal: $ordinal,
            );
        }
    }

    /** Compatibility only: admitted HTTP selects metadata and never invokes this hook. */
    public function routes(WaaseyaaRouter $router, EntityTypeManager $entityTypeManager): void
    {
        $compiler = new RouteMetadataCompiler();
        foreach ($this->routeDefinitions(new RouteContributionContext(self::class, 0)) as $definition) {
            $route = $compiler->compileRoute($definition);
            $route->setDefault('_controller', $definition->handler->target . '::' . $definition->handler->method);
            $router->addRoute($definition->name, $route);
        }
    }

    private function createSeoPublicController(): SeoPublicController
    {
        $manager = $this->resolve(EntityTypeManager::class);
        $failurePolicy = $this->resolve(DiscoveryFailurePolicy::class);
        $scope = $this->kernelServices?->get(AccountFieldReadScopeInterface::class);
        $principalFactory = $this->kernelServices?->get(AccountPrincipalFactoryInterface::class);
        $origin = array_key_exists(CanonicalPublicOrigin::class, $this->getBindings())
            ? $this->resolve(CanonicalPublicOrigin::class)
            : $this->kernelServices?->get(CanonicalPublicOrigin::class);
        $urlPolicy = $this->kernelServices?->get(PublicUrlPolicyInterface::class);
        $crawlEligibility = $this->kernelServices?->get(CrawlEligibilityPolicyInterface::class);
        $contributor = $this->kernelServices?->get(SitemapContributorInterface::class);
        if (!$manager instanceof EntityTypeManager || !$failurePolicy instanceof DiscoveryFailurePolicy
            || ($scope !== null && !$scope instanceof AccountFieldReadScopeInterface)
            || ($principalFactory !== null && !$principalFactory instanceof AccountPrincipalFactoryInterface)
            || ($origin !== null && !$origin instanceof CanonicalPublicOrigin)
            || ($urlPolicy !== null && !$urlPolicy instanceof PublicUrlPolicyInterface)
            || ($crawlEligibility !== null && !$crawlEligibility instanceof CrawlEligibilityPolicyInterface)
            || ($contributor !== null && !$contributor instanceof SitemapContributorInterface)) {
            throw new \LogicException('Invalid SEO execution dependency.');
        }
        return new SeoPublicController($manager, $scope, $principalFactory, $origin, $urlPolicy, $crawlEligibility, $contributor, $failurePolicy);
    }

    public function registerRenderCacheListeners(EventDispatcherInterface $dispatcher, ?CacheBackendInterface $renderCacheBackend): void
    {
        if ($renderCacheBackend === null) {
            return;
        }

        $this->renderCache = new RenderCache($renderCacheBackend);
        $renderCache = $this->renderCache;

        $invalidate = function (object $event) use ($renderCache): void {
            if (!$event instanceof EntityEvent) {
                return;
            }

            $entityType = $event->entity->getEntityTypeId();
            $renderCache->invalidateEntity(
                $entityType,
                $event->entity->id(),
            );

            if (in_array($entityType, [
                'relationship',
                'node',
                'genealogy_person',
                'genealogy_family',
                'genealogy_event',
                'genealogy_tree',
            ], true)) {
                $renderCache->invalidateEntity('node', null);
                $renderCache->invalidateEntity('relationship', null);
                foreach (['genealogy_person', 'genealogy_family', 'genealogy_event', 'genealogy_tree'] as $genealogyType) {
                    $renderCache->invalidateEntity($genealogyType, null);
                }
            }
        };

        $dispatcher->addListener(EntityEvents::POST_SAVE->value, $invalidate);
        $dispatcher->addListener(EntityEvents::POST_DELETE->value, $invalidate);
    }

    public function configureHttpKernel(HttpKernel $kernel): void
    {
        if ($this->renderCache === null) {
            return;
        }
        $internalFieldVisibility = $this->resolveOptional(InternalFieldVisibilityPolicy::class);
        $editorialVisibilityResolver = $this->resolveOptional(EditorialVisibilityResolver::class);

        $this->ssrPageHandler = new SsrPageHandler(
            entityTypeManager: $kernel->getEntityTypeManager(),
            database: $kernel->getDatabase(),
            renderCache: $this->renderCache,
            cacheConfigResolver: new \Waaseyaa\Routing\CacheConfigResolver($kernel->getConfig()),
            discoveryHandler: $kernel->getDiscoveryApiHandler(),
            projectRoot: $kernel->getProjectRoot(),
            config: $kernel->getConfig(),
            manifest: $kernel->getManifest(),
            serviceResolver: $kernel->getHttpServiceResolver(),
            logger: $this->resolve(LoggerInterface::class),
            gate: new EntityAccessGate(
                $kernel->getAccessHandler(),
                null,
                $this->resolve(AccountFieldReadScopeInterface::class),
                $kernel->accountContext(),
            ),
            inertiaFullPageRenderer: $kernel->getInertiaFullPageRenderer(),
            accessHandler: $kernel->getAccessHandler(),
            fieldReadScope: $this->resolve(AccountFieldReadScopeInterface::class),
            principalFactory: $this->resolve(AccountPrincipalFactoryInterface::class),
            internalFieldVisibility: $internalFieldVisibility instanceof InternalFieldVisibilityPolicy
                ? $internalFieldVisibility
                : InternalFieldVisibilityPolicy::fromConfig($this->config),
            editorialVisibilityResolver: $editorialVisibilityResolver instanceof EditorialVisibilityResolver
                ? $editorialVisibilityResolver
                : null,
        );
    }

    /**
     * @return iterable<SsrRouter|AppControllerRouter>
     */
    public function httpDomainRouters(HttpKernel $httpKernel): iterable
    {
        if ($this->ssrPageHandler === null) {
            return [];
        }

        return [
            new SsrRouter($this->ssrPageHandler),
            new AppControllerRouter(
                $this->ssrPageHandler,
                $this->resolve(ErrorPageRendererInterface::class),
                debug: RuntimePolicy::resolve($httpKernel->getConfig())->debug,
            ),
        ];
    }

    public function stripLanguagePrefixForRouting(string $path): string
    {
        return $this->ssrPageHandler?->getLanguageResolver()?->stripLanguagePrefixForRouting($path) ?? $path;
    }

    public static function getTwigEnvironment(): ?Environment
    {
        return self::$twigEnvironment;
    }

    public static function getFormatterRegistry(): ?FieldFormatterRegistry
    {
        return self::$formatterRegistry;
    }

    public static function setFormatterRegistry(?FieldFormatterRegistry $formatterRegistry): void
    {
        self::$formatterRegistry = $formatterRegistry;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function createTwigEnvironment(string $projectRoot, array $config = []): Environment
    {
        return ThemeServiceProvider::createTwigEnvironment($projectRoot, $config);
    }

    /**
     * Wire a pre-built Twig environment so the SSR render path can use it under
     * test, or reset it with null between tests. `createTwigEnvironment()` is a
     * pure factory that never populated the static the renderer reads, so there
     * was no wired test-render path; pair them:
     * `SsrServiceProvider::setTwigEnvironment(SsrServiceProvider::createTwigEnvironment($root))`. (#1604)
     */
    public static function setTwigEnvironment(?Environment $env): void
    {
        self::$twigEnvironment = $env;
    }
}
