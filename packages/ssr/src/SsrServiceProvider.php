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
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\ConfiguresHttpKernelInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasHttpDomainRoutersInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasRenderCacheListenersInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\SSR\Flash\Flash;
use Waaseyaa\SSR\Flash\FlashMessageService;
use Waaseyaa\SSR\Http\Router\AppControllerRouter;
use Waaseyaa\SSR\Http\Router\SsrRouter;
use Waaseyaa\SSR\Http\SeoPublicController;
use Waaseyaa\SSR\Twig\FlashTwigExtension;

final class SsrServiceProvider extends ServiceProvider implements ConfiguresHttpKernelInterface, HasHttpDomainRoutersInterface, HasRenderCacheListenersInterface, LanguagePathStripperInterface
{
    private static ?Environment $twigEnvironment = null;
    private static ?FieldFormatterRegistry $formatterRegistry = null;

    private ?Environment $kernelTwigEnvironment = null;

    private ?RenderCache $renderCache = null;

    private ?SsrPageHandler $ssrPageHandler = null;

    public function register(): void
    {
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

    /**
     * Register the public, crawler-facing agent/SEO routes. Priority 10 keeps
     * them ahead of the SSR `/{path}` render fallback (BuiltinRouteRegistrar).
     */
    public function routes(WaaseyaaRouter $router, EntityTypeManager $entityTypeManager): void
    {
        $controller = SeoPublicController::class;

        $router->addRoute(
            'seo.robots_txt',
            RouteBuilder::create('/robots.txt')
                ->controller($controller . '::robotsTxt')
                ->methods('GET')
                ->allowAll()
                ->priority(10)
                ->build(),
        );

        $router->addRoute(
            'seo.sitemap_xml',
            RouteBuilder::create('/sitemap.xml')
                ->controller($controller . '::sitemapXml')
                ->methods('GET')
                ->allowAll()
                ->priority(10)
                ->build(),
        );

        $router->addRoute(
            'seo.llms_txt',
            RouteBuilder::create('/llms.txt')
                ->controller($controller . '::llmsTxt')
                ->methods('GET')
                ->allowAll()
                ->priority(10)
                ->build(),
        );
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
