<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\Capability\CapabilityRegistryInterface;
use Waaseyaa\Access\Capability\McpApprovalCapabilities;
use Waaseyaa\Access\DecisionAccountResolver;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AdminSurface\Host\AbstractAdminSurfaceHost;
use Waaseyaa\AdminSurface\Host\AdminPublicationFieldReaderInterface;
use Waaseyaa\AdminSurface\Host\AdminSurfaceHostFactoryInterface;
use Waaseyaa\AdminSurface\Host\AuditedAdminPublicationFieldReader;
use Waaseyaa\AdminSurface\Host\GenericAdminSurfaceHost;
use Waaseyaa\AdminSurface\PageBuilder\PageBuilderSurfaceHostInterface;
use Waaseyaa\AdminSurface\PageBuilder\PageBuilderSurfaceRequest;
use Waaseyaa\Api\InternalFieldVisibilityPolicy;
use Waaseyaa\Api\Schema\SchemaPresenter;
use Waaseyaa\Audit\AuditedFieldRead;
use Waaseyaa\Audit\Contract\StrictPrivilegedReadLedgerInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;
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
final class AdminSurfaceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
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

        return new GenericAdminSurfaceHost(
            entityTypeManager: $entityTypeManager,
            accessHandler: $this->discoverAccessHandler(),
            schemaPresenter: new SchemaPresenter(
                $fieldDefinitionRegistry instanceof FieldDefinitionRegistryInterface
                    ? $fieldDefinitionRegistry
                    : null,
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
     */
    public static function serveStaticFile(string $filePath): Response
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

        return new Response(
            file_get_contents($filePath),
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

        $pageBuilderHost = $this->resolveOptional(PageBuilderSurfaceHostInterface::class);
        if ($pageBuilderHost instanceof PageBuilderSurfaceHostInterface) {
            self::registerPageBuilderRoutes($router, $pageBuilderHost);
        }

        self::registerRoutes($router, $host);

        // Admin SPA catch-all — registered after _surface API routes so those
        // match first, but before the framework's SSR catch-all in
        // BuiltinRouteRegistrar (provider routes() runs at line 145–147).
        $projectRoot = $this->projectRoot;
        $vendorDistDir = __DIR__ . '/../dist';
        $vendorDistContent = is_file($vendorDistDir . '/index.html')
            ? file_get_contents($vendorDistDir . '/index.html')
            : null;

        $router->addRoute('admin_spa', RouteBuilder::create('/admin/{path}')
            ->methods('GET')
            ->allowAll()
            ->controller(static function (mixed $request = null, string $path = '') use ($projectRoot, $vendorDistDir, $vendorDistContent): Response {
                // Serve static assets (JS, CSS, images) from public/admin/ or vendor dist.
                if ($path !== '' && !str_contains($path, '..')) {
                    $publicAsset = $projectRoot . '/public/admin/' . $path;
                    if (is_file($publicAsset)) {
                        return self::serveStaticFile($publicAsset);
                    }

                    $vendorAsset = $vendorDistDir . '/' . $path;
                    if (is_file($vendorAsset)) {
                        return self::serveStaticFile($vendorAsset);
                    }
                }

                // SPA index fallback for route paths.
                $html = self::resolveAdminIndex($projectRoot, $vendorDistContent);
                if ($html !== null) {
                    return new Response(
                        $html,
                        200,
                        ['Content-Type' => 'text/html; charset=UTF-8'],
                    );
                }

                $appName = getenv('APP_NAME');
                $appName = is_string($appName) && $appName !== '' ? $appName : 'Application';

                return AdminSpaFallback::htmlResponse($appName);
            })
            ->requirement('path', '(?!(?:_surface|api)(?:/|$)).*')
            ->default('path', '')
            ->build());
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
        // Session endpoint uses requireSession (not requireAuthentication) so the
        // SPA can distinguish "not logged in" from "endpoint not available"
        // (network error). The host's resolveSession() checks the account and
        // returns null for unauthorized users, which surfaces as a refusal
        // envelope AND — since #2161 — a real 401 on the status line.
        $router->addRoute('admin_surface.session', RouteBuilder::create(AdminSurfaceRoutePaths::PATH_SESSION)
            ->methods('GET')
            ->requireSession()
            ->controller(fn($request) => self::surfaceResponse($host->handleSession($request)))
            ->build());

        $router->addRoute('admin_surface.catalog', RouteBuilder::create(AdminSurfaceRoutePaths::PATH_CATALOG)
            ->methods('GET')
            ->requireAuthentication()
            ->controller(fn($request) => self::surfaceResponse($host->handleCatalog($request)))
            ->build());

        $router->addRoute('admin_surface.list', RouteBuilder::create(AdminSurfaceRoutePaths::PATH_LIST)
            ->methods('GET')
            ->requireAuthentication()
            ->controller(fn($request, $type) => self::surfaceResponse($host->handleList($request, $type)))
            ->build());

        $router->addRoute('admin_surface.get', RouteBuilder::create(AdminSurfaceRoutePaths::PATH_GET)
            ->methods('GET')
            ->requireAuthentication()
            ->controller(fn($request, $type, $id) => self::surfaceResponse($host->handleGet($request, $type, $id)))
            ->build());

        $router->addRoute('admin_surface.action', RouteBuilder::create(AdminSurfaceRoutePaths::PATH_ACTION)
            ->methods('POST')
            ->requireAuthentication()
            ->controller(fn($request, $type, $action) => self::surfaceResponse($host->handleAction($request, $type, $action)))
            ->build());
    }

    /**
     * Emit the Admin Surface envelope with its own transport contract.
     *
     * Every `AbstractAdminSurfaceHost::handle*` method returns the package's
     * flat `{ok, data, error, meta}` envelope. It is not a JSON:API document,
     * so handing an array to `ControllerDispatcher` would incorrectly apply
     * the Foundation JSON:API media type and pretty-printing. Returning a
     * `JsonResponse` here keeps that package-specific knowledge at the route
     * boundary and preserves the compact `application/json` contract.
     *
     * Only a genuine 400-599 integer is promoted. `handle*` is overridable, so a
     * host subclass can return a hand-built envelope whose status is absent, a
     * string, or out of range; handing that to the dispatcher would reach the
     * `Response` constructor and turn a clean refusal into a 500. Anything
     * unrecognised keeps HTTP 200 instead.
     *
     * @param  array<string, mixed> $envelope
     */
    private static function surfaceResponse(array $envelope): JsonResponse
    {
        $status = $envelope['error']['status'] ?? null;
        if (($envelope['ok'] ?? null) !== false
            || !is_int($status)
            || $status < 400
            || $status > 599
        ) {
            $status = 200;
        }

        return new JsonResponse($envelope, $status);
    }

    public static function registerPageBuilderRoutes(
        WaaseyaaRouter $router,
        PageBuilderSurfaceHostInterface $host,
    ): void {
        $router->addRoute('admin_surface.page_builder.definitions', RouteBuilder::create(AdminSurfaceRoutePaths::PATH_PAGE_BUILDER_DEFINITIONS)
            ->methods('GET')
            ->requireAuthentication()
            ->controller(fn($request, $surface) => $host->handleDefinitions(self::pageBuilderRequest($request), $surface))
            ->build());

        $router->addRoute('admin_surface.page_builder.command', RouteBuilder::create(AdminSurfaceRoutePaths::PATH_PAGE_BUILDER_COMMAND)
            ->methods('POST')
            ->requireAuthentication()
            ->requireCsrf()
            ->controller(fn($request, $surface, $id) => $host->handleCommand(self::pageBuilderRequest($request), $surface, $id))
            ->build());

        $router->addRoute('admin_surface.page_builder.preview', RouteBuilder::create(AdminSurfaceRoutePaths::PATH_PAGE_BUILDER_PREVIEW)
            ->methods('POST')
            ->requireAuthentication()
            ->requireCsrf()
            ->controller(fn($request, $surface, $id) => $host->handlePreview(self::pageBuilderRequest($request), $surface, $id))
            ->build());

        $router->addRoute('admin_surface.page_builder.history', RouteBuilder::create(AdminSurfaceRoutePaths::PATH_PAGE_BUILDER_HISTORY)
            ->methods('GET')
            ->requireAuthentication()
            ->controller(fn($request, $surface, $id) => $host->handleHistory(self::pageBuilderRequest($request), $surface, $id))
            ->build());

        $router->addRoute('admin_surface.page_builder.revision', RouteBuilder::create(AdminSurfaceRoutePaths::PATH_PAGE_BUILDER_REVISION)
            ->methods('GET')
            ->requireAuthentication()
            ->controller(fn($request, $surface, $id, $revision) => $host->handleRevision(self::pageBuilderRequest($request), $surface, $id, $revision))
            ->requirement('revision', '[1-9][0-9]*')
            ->build());

        $router->addRoute('admin_surface.page_builder.restore', RouteBuilder::create(AdminSurfaceRoutePaths::PATH_PAGE_BUILDER_RESTORE)
            ->methods('POST')
            ->requireAuthentication()
            ->requireCsrf()
            ->controller(fn($request, $surface, $id) => $host->handleRestore(self::pageBuilderRequest($request), $surface, $id))
            ->build());

        $router->addRoute('admin_surface.page_builder.draft', RouteBuilder::create(AdminSurfaceRoutePaths::PATH_PAGE_BUILDER_DRAFT)
            ->methods('GET')
            ->requireAuthentication()
            ->controller(fn($request, $surface, $id) => $host->handleDraft(self::pageBuilderRequest($request), $surface, $id))
            ->build());
    }

    private static function pageBuilderRequest(Request $request): PageBuilderSurfaceRequest
    {
        return new PageBuilderSurfaceRequest(
            DecisionAccountResolver::resolve(
                $request->attributes->get('_authorization_principal'),
                $request->attributes->get('_account'),
            ),
            $request->getContent(),
        );
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
