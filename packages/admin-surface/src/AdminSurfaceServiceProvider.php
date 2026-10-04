<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\Capability\CapabilityRegistryInterface;
use Waaseyaa\Access\Capability\McpApprovalCapabilities;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AdminSurface\Host\AbstractAdminSurfaceHost;
use Waaseyaa\AdminSurface\Host\AdminPublicationFieldReaderInterface;
use Waaseyaa\AdminSurface\Host\AdminSurfaceHostFactoryInterface;
use Waaseyaa\AdminSurface\Host\AuditedAdminPublicationFieldReader;
use Waaseyaa\AdminSurface\Host\GenericAdminSurfaceHost;
use Waaseyaa\AdminSurface\Http\AdminSpaHttpController;
use Waaseyaa\AdminSurface\Http\AdminSurfaceHttpController;
use Waaseyaa\AdminSurface\Http\PageBuilderHttpController;
use Waaseyaa\AdminSurface\PageBuilder\PageBuilderSurfaceHostInterface;
use Waaseyaa\Api\InternalFieldVisibilityPolicy;
use Waaseyaa\Api\Schema\SchemaPresenter;
use Waaseyaa\Audit\AuditedFieldRead;
use Waaseyaa\Audit\Contract\StrictPrivilegedReadLedgerInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Field\FieldSchemaAuthority;
use Waaseyaa\Field\FieldTypeManagerInterface;
use Waaseyaa\Foundation\Routing\Metadata\HandlerReference;
use Waaseyaa\Foundation\Routing\Metadata\RouteContributionContext;
use Waaseyaa\Foundation\Routing\Metadata\RouteDefinition;
use Waaseyaa\Foundation\ServiceProvider\Capability\ContributesRouteMetadataInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteMetadataCompiler;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\User\Session\SessionCookiePolicy;
use Waaseyaa\Workflows\Binding\WorkflowBindingResolver;

/**
 * Registers admin surface routes with a generic CRUD host.
 *
 * Works out of the box: auto-discovers entity types and provides full
 * admin CRUD without any app-level configuration.
 *
 * To customize, supply your own host:
 * - Extend GenericAdminSurfaceHost and override methods, or implement
 *   AbstractAdminSurfaceHost directly.
 * - Bind an {@see AdminSurfaceHostFactoryInterface} returning it, in your
 *   service provider's `register()`.
 *
 * This provider then registers the canonical `admin_surface.*` routes against
 * your host instead of the generic one — same paths, same methods, same
 * authentication requirements, same refusal-status promotion, registered
 * exactly once. Do not register those paths yourself: the router refuses a
 * duplicate route name, and shadowing them under different names to win on
 * priority forks the refusal contract (#2422).
 */
final class AdminSurfaceServiceProvider extends ServiceProvider implements ContributesRouteMetadataInterface
{
    public function register(): void
    {
        // Load only the owned path authority before cold declaration reads.
        new \ReflectionClass(AdminSurfaceRoutePaths::class);
        $this->bind(AdminSurfaceHttpController::class, function (): AdminSurfaceHttpController {
            $factory = $this->kernelServices?->get(AdminSurfaceHostFactoryInterface::class);
            if ($factory !== null && !$factory instanceof AdminSurfaceHostFactoryInterface) {
                throw new \RuntimeException('The Admin Surface host factory is invalid.');
            }
            if ($factory instanceof AdminSurfaceHostFactoryInterface) {
                return new AdminSurfaceHttpController($factory->createAdminSurfaceHost());
            }
            $manager = $this->resolve(EntityTypeManagerInterface::class);
            if (!$manager instanceof EntityTypeManagerInterface) {
                throw new \RuntimeException('The Admin Surface entity manager is invalid.');
            }
            return new AdminSurfaceHttpController($this->buildGenericHost($manager));
        });
        $this->bind(PageBuilderHttpController::class, function (): PageBuilderHttpController {
            $host = $this->resolve(PageBuilderSurfaceHostInterface::class);
            if (!$host instanceof PageBuilderSurfaceHostInterface) {
                throw new \RuntimeException('The page-builder host is invalid.');
            }
            return new PageBuilderHttpController($host);
        });
        $this->bind(AdminSpaHttpController::class, fn(): AdminSpaHttpController => $this->spaController());
        $this->singleton(AdminPublicationFieldReaderInterface::class, function (): AdminPublicationFieldReaderInterface {
            $capabilities = $this->resolve(CapabilityRegistryInterface::class);
            $privilegedReadLedger = $this->resolve(StrictPrivilegedReadLedgerInterface::class);
            assert($capabilities instanceof CapabilityRegistryInterface);
            assert($privilegedReadLedger instanceof StrictPrivilegedReadLedgerInterface);

            return new AuditedAdminPublicationFieldReader(
                new AuditedFieldRead($capabilities, $privilegedReadLedger),
                $capabilities,
            );
        });
    }

    /**
     * The application's own host, when one is supplied.
     *
     * Resolved here rather than in `register()` because `routes()` runs after
     * every provider has registered, so a factory may safely depend on sibling
     * bindings. Absent a factory the framework keeps the generic host, so an
     * install that supplies none is unaffected.
     */
    private function resolveApplicationHost(): ?AbstractAdminSurfaceHost
    {
        $factory = $this->resolveOptional(AdminSurfaceHostFactoryInterface::class);

        return $factory instanceof AdminSurfaceHostFactoryInterface
            ? $factory->createAdminSurfaceHost()
            : null;
    }

    /** The framework default: full admin CRUD with no app-level configuration. */
    private function buildGenericHost(EntityTypeManagerInterface $entityTypeManager): GenericAdminSurfaceHost
    {
        $fieldDefinitionRegistry = $this->resolveOptional(FieldDefinitionRegistryInterface::class);
        $workflowBindingResolver = $this->resolveOptional(WorkflowBindingResolver::class);
        $publicationFieldReader = $this->resolveOptional(AdminPublicationFieldReaderInterface::class);
        $internalFieldVisibility = $this->resolveOptional(InternalFieldVisibilityPolicy::class);
        $revisionPreviewAuthority = $this->resolveOptional(\Waaseyaa\AdminSurface\Host\AdminRevisionPreviewAuthorityInterface::class);

        return new GenericAdminSurfaceHost(
            entityTypeManager: $entityTypeManager,
            accessHandler: $this->discoverAccessHandler(),
            // The presenter projects field schemas through the kernel's
            // boot-scoped field-type registry (#2786 B1), so a downstream
            // plugin admitted at boot renders in the admin form.
            schemaPresenter: new SchemaPresenter(
                $fieldDefinitionRegistry instanceof FieldDefinitionRegistryInterface
                    ? $fieldDefinitionRegistry
                    : null,
                fieldSchemas: $this->fieldSchemaAuthority(),
            ),
            internalFieldVisibility: $internalFieldVisibility instanceof InternalFieldVisibilityPolicy
                ? $internalFieldVisibility
                : InternalFieldVisibilityPolicy::fromConfig($this->config),
            workflowBindingResolver: $workflowBindingResolver instanceof WorkflowBindingResolver
                ? $workflowBindingResolver
                : null,
            publicationFieldReader: $publicationFieldReader instanceof AdminPublicationFieldReaderInterface
                ? $publicationFieldReader
                : null,
            revisionPreviewAuthority: $revisionPreviewAuthority instanceof \Waaseyaa\AdminSurface\Host\AdminRevisionPreviewAuthorityInterface
                ? $revisionPreviewAuthority
                : null,
            features: self::defaultFeatures(
                mcpInstalled: class_exists('Waaseyaa\\Mcp\\McpServiceProvider'),
                wayfindingInstalled: class_exists('Waaseyaa\\Wayfinding\\WayfindingServiceProvider'),
            ),
            capabilityAllowlist: self::defaultCapabilityAllowlist(
                mcpInstalled: class_exists('Waaseyaa\\Mcp\\McpServiceProvider'),
            ),
        );
    }

    /**
     * The field schema authority composed over the kernel's boot-scoped
     * field-type registry: FieldServiceProvider's binding when it registered,
     * else one composed here over the registry the kernel-services bus serves.
     *
     * A provider composed without either has no registry to present against;
     * narrowing silently to the built-in roster would refuse every downstream
     * plugin the kernel admitted, so refuse loudly instead.
     */
    private function fieldSchemaAuthority(): FieldSchemaAuthority
    {
        $authority = $this->resolveOptional(FieldSchemaAuthority::class);
        if ($authority instanceof FieldSchemaAuthority) {
            return $authority;
        }

        $fieldTypes = $this->resolveOptional(FieldTypeManagerInterface::class);
        if ($fieldTypes instanceof FieldTypeManagerInterface) {
            return new FieldSchemaAuthority($fieldTypes);
        }

        throw new \LogicException(
            'The generic admin surface host requires the kernel\'s field schema authority; '
            . 'neither FieldSchemaAuthority nor FieldTypeManagerInterface is resolvable from the kernel-services bus.',
        );
    }

    /**
     * Rewrite the Nuxt public `csrfCookieName` embedded in packaged/app Admin
     * HTML so it matches the runtime {@see SessionCookiePolicy} CSRF cookie
     * (#3047). Host-bound PHP emits `__Host-XSRF-TOKEN` while the shipped dist
     * still embeds `XSRF-TOKEN`; serving HTML unchanged would omit the correct
     * token on requests/uploads.
     *
     * Replacement uses a callback so literal `$` in a configured cookie name
     * (e.g. `APP$1-XSRF`) is not interpreted as a `preg_replace` backreference.
     */
    public static function applyRuntimeCsrfCookieName(string $html, string $csrfCookieName): string
    {
        $rewritten = preg_replace_callback(
            '/csrfCookieName\s*:\s*"[^"]*"/',
            static fn(): string => 'csrfCookieName:"' . addcslashes($csrfCookieName, '"\\') . '"',
            $html,
            1,
        );

        return is_string($rewritten) ? $rewritten : $html;
    }

    /**
     * Resolve the admin SPA index.html content.
     *
     * Two-tier fallback:
     * 1. App override: $projectRoot/public/admin/index.html (checked first)
     * 2. Vendor fallback: pre-built content passed as $vendorDistContent
     *
     * Returns null if neither source is available.
     */
    public static function resolveAdminIndex(string $projectRoot, ?string $vendorDistContent): ?string
    {
        $appIndexPath = $projectRoot . '/public/admin/index.html';
        if (is_file($appIndexPath)) {
            return file_get_contents($appIndexPath);
        }

        return $vendorDistContent;
    }

    /**
     * Serve a static file with the correct Content-Type.
     *
     * PHP's built-in server defaults to text/html for BinaryFileResponse,
     * so we read the file and set the MIME type explicitly.
     *
     * When `$csrfCookieName` is provided and the asset is HTML, the packaged
     * Nuxt `csrfCookieName` is rewritten from the runtime session cookie policy
     * so direct entry points (`/admin/index.html`, `/admin/login/index.html`,
     * `/admin/200.html`, …) match the SPA fallback path (#3047).
     */
    public static function serveStaticFile(string $filePath, ?string $csrfCookieName = null): Response
    {
        $mimeTypes = [
            'js' => 'application/javascript',
            'mjs' => 'application/javascript',
            'css' => 'text/css',
            'json' => 'application/json',
            'html' => 'text/html; charset=UTF-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'map' => 'application/json',
        ];

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $contentType = $mimeTypes[$ext] ?? 'application/octet-stream';
        $content = file_get_contents($filePath);
        if ($ext === 'html' && is_string($content) && $csrfCookieName !== null && $csrfCookieName !== '') {
            $content = self::applyRuntimeCsrfCookieName($content, $csrfCookieName);
        }

        return new Response(
            $content === false ? '' : $content,
            200,
            ['Content-Type' => $contentType],
        );
    }

    /**
     * Auto-register admin surface routes with the generic host.
     *
     * If an app provides its own host via a higher-priority provider,
     * it should call registerRoutes() directly and skip this provider.
     */
    public function routes(WaaseyaaRouter $router, EntityTypeManagerInterface $entityTypeManager): void
    {
        $host = $this->resolveApplicationHost() ?? $this->buildGenericHost($entityTypeManager);
        $pageBuilder = $this->resolveOptional(PageBuilderSurfaceHostInterface::class);
        $context = new RouteContributionContext(self::class, 0, capabilities: [
            'service:Waaseyaa\AdminSurface\PageBuilder\PageBuilderSurfaceHostInterface' => $pageBuilder instanceof PageBuilderSurfaceHostInterface,
        ]);
        $controllers = [AdminSurfaceHttpController::class => new AdminSurfaceHttpController($host), AdminSpaHttpController::class => $this->spaController()];
        if ($pageBuilder instanceof PageBuilderSurfaceHostInterface) {
            $controllers[PageBuilderHttpController::class] = new PageBuilderHttpController($pageBuilder);
        }
        self::replay($router, self::declarations($context), $controllers);
    }

    private function spaController(): AdminSpaHttpController
    {
        $sessionCookie = $this->config['session']['cookie'] ?? null;
        $csrfName = new SessionCookiePolicy(is_array($sessionCookie) ? $sessionCookie : null)->csrfName();
        return new AdminSpaHttpController($this->projectRoot, __DIR__ . '/../dist', $csrfName);
    }

    /**
     * Framework-default session capability allowlist for the generic host.
     *
     * When the MCP package is installed, project exactly the two approval
     * permissions (`mcp.approval.view` / `mcp.approval.decide`) so the SPA can
     * distinguish a read-only triage operator from a deciding operator without
     * guessing from roles. Slim installs without MCP project nothing. The
     * identifiers come from McpApprovalCapabilities (L1 access — a legal
     * downward import for this L6 package), never duplicated strings.
     *
     * @return list<string>
     */
    public static function defaultCapabilityAllowlist(bool $mcpInstalled): array
    {
        return $mcpInstalled ? McpApprovalCapabilities::all() : [];
    }

    /**
     * Project optional framework packages into the authenticated SPA session.
     *
     * Class-string detection keeps this L6 package independent of optional
     * package imports and Composer requirements. Clients treat only exact true
     * as available, so a slim install cannot activate unsupported UI or network
     * work.
     *
     * @return array{mcp: bool, wayfinding: bool}
     */
    public static function defaultFeatures(bool $mcpInstalled, bool $wayfindingInstalled): array
    {
        return [
            'mcp' => $mcpInstalled,
            'wayfinding' => $wayfindingInstalled,
        ];
    }

    /**
     * Register admin surface routes against a given host.
     *
     * Called by {@see self::routes()} with either the application's host (see
     * {@see AdminSurfaceHostFactoryInterface}) or the generic default. Prefer
     * binding a factory over calling this yourself: `routes()` has already run
     * by the time an application provider does, so a second call registers the
     * same names twice and the router refuses it.
     */
    public static function registerRoutes(WaaseyaaRouter $router, AbstractAdminSurfaceHost $host): void
    {
        self::replay($router, self::declarations(new RouteContributionContext(self::class, 0), includePageBuilder: false, includeSpa: false), [AdminSurfaceHttpController::class => new AdminSurfaceHttpController($host)]);
    }

    public static function registerPageBuilderRoutes(WaaseyaaRouter $router, PageBuilderSurfaceHostInterface $host): void
    {
        $context = new RouteContributionContext(self::class, 0, capabilities: ['service:Waaseyaa\AdminSurface\PageBuilder\PageBuilderSurfaceHostInterface' => true]);
        self::replay($router, self::declarations($context, includeCore: false, includeSpa: false), [PageBuilderHttpController::class => new PageBuilderHttpController($host)]);
    }

    /** @return iterable<RouteDefinition> Pure declarations from admitted binding presence. */
    public function routeDefinitions(RouteContributionContext $context): iterable
    {
        yield from self::declarations($context);
    }

    /** @return iterable<RouteDefinition> */
    private static function declarations(RouteContributionContext $context, bool $includeCore = true, bool $includePageBuilder = true, bool $includeSpa = true): iterable
    {
        $ordinal = 0;
        $pageBuilderPresent = $context->capabilities['service:Waaseyaa\AdminSurface\PageBuilder\PageBuilderSurfaceHostInterface'] ?? false;
        foreach ([
            ['admin_surface.page_builder.definitions', AdminSurfaceRoutePaths::PATH_PAGE_BUILDER_DEFINITIONS, PageBuilderHttpController::class, 'definitions', 'GET', ['_authenticated' => true], [], 'page'],
            ['admin_surface.page_builder.command', AdminSurfaceRoutePaths::PATH_PAGE_BUILDER_COMMAND, PageBuilderHttpController::class, 'command', 'POST', ['_authenticated' => true, '_csrf' => true], [], 'page'],
            ['admin_surface.page_builder.preview', AdminSurfaceRoutePaths::PATH_PAGE_BUILDER_PREVIEW, PageBuilderHttpController::class, 'preview', 'POST', ['_authenticated' => true, '_csrf' => true], [], 'page'],
            ['admin_surface.page_builder.history', AdminSurfaceRoutePaths::PATH_PAGE_BUILDER_HISTORY, PageBuilderHttpController::class, 'history', 'GET', ['_authenticated' => true], [], 'page'],
            ['admin_surface.page_builder.revision', AdminSurfaceRoutePaths::PATH_PAGE_BUILDER_REVISION, PageBuilderHttpController::class, 'revision', 'GET', ['_authenticated' => true], ['revision' => '[1-9][0-9]*'], 'page'],
            ['admin_surface.page_builder.restore', AdminSurfaceRoutePaths::PATH_PAGE_BUILDER_RESTORE, PageBuilderHttpController::class, 'restore', 'POST', ['_authenticated' => true, '_csrf' => true], [], 'page'],
            ['admin_surface.page_builder.draft', AdminSurfaceRoutePaths::PATH_PAGE_BUILDER_DRAFT, PageBuilderHttpController::class, 'draft', 'GET', ['_authenticated' => true], [], 'page'],
            ['admin_surface.session', AdminSurfaceRoutePaths::PATH_SESSION, AdminSurfaceHttpController::class, 'session', 'GET', ['_session' => true], [], 'core'],
            ['admin_surface.catalog', AdminSurfaceRoutePaths::PATH_CATALOG, AdminSurfaceHttpController::class, 'catalog', 'GET', ['_authenticated' => true], [], 'core'],
            ['admin_surface.list', AdminSurfaceRoutePaths::PATH_LIST, AdminSurfaceHttpController::class, 'list', 'GET', ['_authenticated' => true], [], 'core'],
            ['admin_surface.get', AdminSurfaceRoutePaths::PATH_GET, AdminSurfaceHttpController::class, 'get', 'GET', ['_authenticated' => true], [], 'core'],
            ['admin_surface.action', AdminSurfaceRoutePaths::PATH_ACTION, AdminSurfaceHttpController::class, 'action', 'POST', ['_authenticated' => true, '_csrf' => true], [], 'core'],
        ] as [$name, $path, $target, $action, $method, $options, $requirements, $family]) {
            if (($family === 'page' && (!$includePageBuilder || !$pageBuilderPresent)) || ($family === 'core' && !$includeCore)) {
                continue;
            }
            yield new RouteDefinition($name, $path, HandlerReference::fromString('class:' . $target . '::' . $action), methods: [$method], options: $options, requirements: $requirements, sourceId: $context->sourceId, ordinal: $ordinal++);
        }
        if ($includeSpa) {
            yield new RouteDefinition('admin_spa', '/admin/{path}', HandlerReference::fromString('class:' . AdminSpaHttpController::class . '::serve'), methods: ['GET'], requirements: ['path' => '(?!(?:_surface|api)(?:/|$)).*'], defaults: ['path' => ''], options: ['_public' => true], sourceId: $context->sourceId, ordinal: $ordinal);
        }
    }

    /** @param iterable<RouteDefinition> $definitions
     * @param array<string, object> $controllers
     */
    private static function replay(WaaseyaaRouter $router, iterable $definitions, array $controllers): void
    {
        foreach ($definitions as $definition) {
            $route = new RouteMetadataCompiler()->compileRoute($definition);
            $options = $route->getOptions();
            unset($options['_waaseyaa_priority']);
            $route->setOptions($options);
            $controller = $controllers[$definition->handler->target];
            $route->setDefault('_controller', \Closure::fromCallable([$controller, $definition->handler->method]));
            $router->addRoute($definition->name, $route);
        }
    }

    /**
     * Use the kernel's already-built access handler — the same in-memory handler
     * the SSR/MCP/JSON:API paths use, carrying the framework defaults
     * (PublishedContentAccessPolicy, ContentAdminAccessPolicy) and every
     * discovered per-type policy. In dev it is fresh-compiled per request, so the
     * admin surface tracks newly added types/policies with no `optimize:manifest`.
     *
     * Returns null only if the kernel-services bus is unavailable or has not yet
     * built the handler; the host fails closed in that case (denies + filters
     * everything) rather than serving entities unchecked.
     */
    private function discoverAccessHandler(): ?EntityAccessHandler
    {
        $handler = $this->kernelServices?->get(EntityAccessHandler::class);

        return $handler instanceof EntityAccessHandler ? $handler : null;
    }
}
