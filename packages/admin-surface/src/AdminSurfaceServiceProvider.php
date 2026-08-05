<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface;

use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\Capability\McpApprovalCapabilities;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AdminSurface\Host\AbstractAdminSurfaceHost;
use Waaseyaa\AdminSurface\Host\GenericAdminSurfaceHost;
use Waaseyaa\Api\Schema\SchemaPresenter;
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
 * To customize, either:
 * - Extend GenericAdminSurfaceHost and override methods
 * - Implement AbstractAdminSurfaceHost directly
 * Then call registerRoutes() from your own service provider.
 */
final class AdminSurfaceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // No bindings needed — host is constructed in routes() where
        // EntityTypeManagerInterface is available via injection.
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
        $fieldDefinitionRegistry = $this->resolveOptional(FieldDefinitionRegistryInterface::class);
        $workflowBindingResolver = $this->resolveOptional(WorkflowBindingResolver::class);
        $host = new GenericAdminSurfaceHost(
            entityTypeManager: $entityTypeManager,
            accessHandler: $this->discoverAccessHandler(),
            schemaPresenter: new SchemaPresenter(
                $fieldDefinitionRegistry instanceof FieldDefinitionRegistryInterface
                    ? $fieldDefinitionRegistry
                    : null,
            ),
            workflowBindingResolver: $workflowBindingResolver instanceof WorkflowBindingResolver
                ? $workflowBindingResolver
                : null,
            features: self::defaultFeatures(
                mcpInstalled: class_exists('Waaseyaa\\Mcp\\McpServiceProvider'),
                wayfindingInstalled: class_exists('Waaseyaa\\Wayfinding\\WayfindingServiceProvider'),
            ),
            capabilityAllowlist: self::defaultCapabilityAllowlist(
                mcpInstalled: class_exists('Waaseyaa\\Mcp\\McpServiceProvider'),
            ),
        );

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
     * Register admin surface routes with a custom host.
     *
     * Call this from your application's service provider if you need
     * custom admin behavior beyond what GenericAdminSurfaceHost provides.
     */
    public static function registerRoutes(WaaseyaaRouter $router, AbstractAdminSurfaceHost $host): void
    {
        // Session endpoint uses requireSession (not requireAuthentication) so the
        // SPA can distinguish "not logged in" (SurfaceResult with ok:false) from
        // "endpoint not available" (network error). The host's resolveSession()
        // checks the account and returns null for unauthorized users.
        $router->addRoute('admin_surface.session', RouteBuilder::create(AdminSurfaceRoutePaths::PATH_SESSION)
            ->methods('GET')
            ->requireSession()
            ->controller(fn($request) => $host->handleSession($request))
            ->build());

        $router->addRoute('admin_surface.catalog', RouteBuilder::create(AdminSurfaceRoutePaths::PATH_CATALOG)
            ->methods('GET')
            ->requireAuthentication()
            ->controller(fn($request) => $host->handleCatalog($request))
            ->build());

        $router->addRoute('admin_surface.list', RouteBuilder::create(AdminSurfaceRoutePaths::PATH_LIST)
            ->methods('GET')
            ->requireAuthentication()
            ->controller(fn($request, $type) => $host->handleList($request, $type))
            ->build());

        $router->addRoute('admin_surface.get', RouteBuilder::create(AdminSurfaceRoutePaths::PATH_GET)
            ->methods('GET')
            ->requireAuthentication()
            ->controller(fn($request, $type, $id) => $host->handleGet($request, $type, $id))
            ->build());

        $router->addRoute('admin_surface.action', RouteBuilder::create(AdminSurfaceRoutePaths::PATH_ACTION)
            ->methods('POST')
            ->requireAuthentication()
            ->controller(fn($request, $type, $action) => $host->handleAction($request, $type, $action))
            ->build());
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
