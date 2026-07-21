<?php

declare(strict_types=1);

namespace Waaseyaa\Api;

use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Gate\GateInterface;
use Waaseyaa\Api\Audit\ApiAuditQueryAdapter;
use Waaseyaa\Api\Audit\AuditQueryReadModelInterface;
use Waaseyaa\Api\Controller\AuditQueryController;
use Waaseyaa\Api\Controller\McpAdminController;
use Waaseyaa\Api\Controller\MediaVersionController;
use Waaseyaa\Api\Controller\MercureMonitorController;
use Waaseyaa\Api\Controller\NotificationController;
use Waaseyaa\Api\Controller\OidcClientController;
use Waaseyaa\Api\Controller\QueueController;
use Waaseyaa\Api\Controller\SchedulerController;
use Waaseyaa\Api\Controller\WorkflowTransitionController;
use Waaseyaa\Api\Http\Router\AuditApiRouter;
use Waaseyaa\Api\Http\Router\DiscoveryRouter;
use Waaseyaa\Api\Http\Router\McpAdminApiRouter;
use Waaseyaa\Api\Http\Router\MediaVersionApiRouter;
use Waaseyaa\Api\Http\Router\MercureMonitorApiRouter;
use Waaseyaa\Api\Http\Router\NotificationAdminApiRouter;
use Waaseyaa\Api\Http\Router\OidcClientApiRouter;
use Waaseyaa\Api\Http\Router\QueueAdminApiRouter;
use Waaseyaa\Api\Http\Router\SchedulerAdminApiRouter;
use Waaseyaa\Api\Http\Router\WorkflowTransitionApiRouter;
use Waaseyaa\Api\McpAdmin\ServerConfigReadModelInterface;
use Waaseyaa\Api\McpAdmin\ToolRegistryReadModelInterface;
use Waaseyaa\Api\Media\ApiMediaVersionAdapter;
use Waaseyaa\Api\Media\MediaVersionReadModelInterface;
use Waaseyaa\Api\MercureMonitor\ChannelInspectorInterface;
use Waaseyaa\Api\MercureMonitor\EventStreamReadModelInterface;
use Waaseyaa\Api\MercureMonitor\SubscriberObserverInterface;
// Note: AuditQueryInterface is NOT imported at class-level — waaseyaa/audit
// is a require-dev dep. The singleton factory resolves it by string (C-002).
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasHttpDomainRoutersInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Media\Version\MediaVersionRepository;
use Waaseyaa\Notification\NotificationDispatcher;
use Waaseyaa\Queue\FailedJobRepositoryInterface;
use Waaseyaa\Queue\QueueInterface;
use Waaseyaa\Queue\Transport\TransportInterface;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\Scheduler\ScheduleInterface;
use Waaseyaa\Scheduler\ScheduleRunner;
use Waaseyaa\Scheduler\Storage\ScheduleStateRepository;
use Waaseyaa\Workflows\Transition\TransitionService;

final class ApiServiceProvider extends ServiceProvider implements HasHttpDomainRoutersInterface
{
    public function register(): void
    {
        // OCAP audit log substrate (ocap-audit-log-substrate-01KSEFTF WP03).
        // Bind the api-local read-model interface to the adapter that bridges
        // L0 audit contracts (AuditQueryInterface) into L4 DTOs.
        // DEAD-CODE GUARD: removing this singleton causes OcapAuditEndpointTest
        // to receive empty {data: [], meta: {total: 0}} — the test's count()
        // assertion fails, proving the binding is live.
        // waaseyaa/audit is in require-dev (C-002 / NFR-002) so AuditQueryInterface
        // is resolved by string to avoid a hard class-level import that would
        // crash kernel boot on installs without waaseyaa/audit.
        $this->singleton(AuditQueryReadModelInterface::class, function (): AuditQueryReadModelInterface {
            /** @var \Waaseyaa\Audit\Contract\AuditQueryInterface $auditQuery */
            $auditQuery = $this->resolve(\Waaseyaa\Audit\Contract\AuditQueryInterface::class);

            return new ApiAuditQueryAdapter($auditQuery);
        });

        // DIR-005 (versioned-blob-media-abstraction-01KSEFTJ): bind the API
        // read-model for MediaVersion. The adapter resolves MediaVersionRepository
        // (L2 media) and GateInterface at boot-time via resolveOptional so that
        // installs without the media package skip cleanly. The GateInterface is
        // optional: when absent (no media access policy registered) all versions
        // are accessible to authenticated accounts (open-by-default per spec).
        // Bind only when MediaVersionRepository is resolvable (media package present).
        // The httpDomainRouters() block uses resolveOptional to skip gracefully when absent.
        $repo = $this->resolveOptional(MediaVersionRepository::class);
        if ($repo instanceof MediaVersionRepository) {
            $gate = $this->resolveOptional(GateInterface::class);
            $this->singleton(MediaVersionReadModelInterface::class, fn(): ApiMediaVersionAdapter => new ApiMediaVersionAdapter(
                repo: $repo,
                gate: $gate instanceof GateInterface ? $gate : null,
            ));
        }
    }

    public function httpDomainRouters(HttpKernel $httpKernel): iterable
    {
        $routers = [
            new DiscoveryRouter(
                $httpKernel->getDiscoveryApiHandler(),
                $httpKernel->getEntityTypeManager(),
            ),
        ];

        // M4B WP01 (+ #1576 follow-up): admin queue dashboard. Pull the queue
        // services through the kernel-services resolver — they're bound by
        // QueueServiceProvider (Layer 0) and routed through here in Layer 4,
        // the same indirection pattern used by AuthOidcRouteServiceProvider
        // for auth/oidc. The TransportInterface is optional: when absent the
        // controller falls back to the M4B failed-only response shape so the
        // admin dashboard keeps working on installs without an SQL transport.
        $failedJobs = $this->resolveOptional(FailedJobRepositoryInterface::class);
        $queue = $this->resolveOptional(QueueInterface::class);
        $transportCandidate = $this->resolveOptional(TransportInterface::class);
        $transport = $transportCandidate instanceof TransportInterface ? $transportCandidate : null;
        if ($failedJobs instanceof FailedJobRepositoryInterface && $queue instanceof QueueInterface) {
            $routers[] = new QueueAdminApiRouter(new QueueController($failedJobs, $queue, $transport));
        }

        // M4B WP02: admin scheduler dashboard. Same indirection pattern as
        // the queue block — SchedulerServiceProvider (Layer 0) binds the
        // three services; if any of them is absent (slimmed-down install),
        // we skip wiring the router rather than crashing kernel boot.
        $schedule = $this->resolveOptional(ScheduleInterface::class);
        $schedulerState = $this->resolveOptional(ScheduleStateRepository::class);
        $schedulerRunner = $this->resolveOptional(ScheduleRunner::class);
        if (
            $schedule instanceof ScheduleInterface
            && $schedulerState instanceof ScheduleStateRepository
            && $schedulerRunner instanceof ScheduleRunner
        ) {
            $routers[] = new SchedulerAdminApiRouter(
                new SchedulerController($schedule, $schedulerState, $schedulerRunner),
            );
        }

        // M4C WP01: admin notifications dashboard. Same indirection pattern as
        // the queue + scheduler blocks — NotificationServiceProvider (Layer 3)
        // binds the dispatcher; if absent (slimmed-down install lacking
        // notification wiring) we skip the router cleanly rather than crash.
        $notificationDispatcher = $this->resolveOptional(NotificationDispatcher::class);
        if ($notificationDispatcher instanceof NotificationDispatcher) {
            $routers[] = new NotificationAdminApiRouter(
                new NotificationController(
                    $notificationDispatcher,
                    $this->resolve(\Waaseyaa\Access\User\UserInternalFieldReaderInterface::class),
                ),
            );
        }

        // CW-v1 WP-4 (#1920): workflow transition endpoints. Same
        // indirection pattern as the queue/scheduler/notification blocks
        // above — WorkflowServiceProvider (Layer 3) binds TransitionService; if the
        // binding is absent (a core-only install without waaseyaa/workflows
        // wired) we skip the router AND the routes (see routes() below)
        // rather than crashing boot or routing to a controller that could
        // not be constructed (design decision 1,
        // docs/history/plans/2026-07-10-content-workflow-wp4.md).
        $transitionService = $this->resolveOptional(TransitionService::class);
        if ($transitionService instanceof TransitionService) {
            $accessHandler = $this->resolveOptional(EntityAccessHandler::class);
            $routers[] = new WorkflowTransitionApiRouter(new WorkflowTransitionController(
                $httpKernel->getEntityTypeManager(),
                $accessHandler instanceof EntityAccessHandler ? $accessHandler : null,
                $transitionService,
            ));
        }

        // M5D WP01: Mercure broadcast monitor dashboard. Same indirection
        // pattern as queue/scheduler/notification blocks — all three deps are
        // bound by `MercureMonitorServiceProvider` (Layer 0 foundation). When
        // any binding is absent (slimmed-down install or monitor disabled via
        // `broadcasting.monitor.enabled = false`) the controller falls back to
        // zeroed empty-shape responses rather than crashing boot (FR-006).
        // The router is wired when at least one dep is resolvable; the
        // controller handles individual nulls internally.
        $inspector = $this->resolveOptional(ChannelInspectorInterface::class);
        $streamModel = $this->resolveOptional(EventStreamReadModelInterface::class);
        $observer = $this->resolveOptional(SubscriberObserverInterface::class);
        if (
            $inspector instanceof ChannelInspectorInterface
            || $streamModel instanceof EventStreamReadModelInterface
            || $observer instanceof SubscriberObserverInterface
        ) {
            $routers[] = new MercureMonitorApiRouter(
                new MercureMonitorController(
                    $inspector instanceof ChannelInspectorInterface ? $inspector : null,
                    $streamModel instanceof EventStreamReadModelInterface ? $streamModel : null,
                    $observer instanceof SubscriberObserverInterface ? $observer : null,
                ),
            );
        }

        // OCAP audit log substrate (ocap-audit-log-substrate-01KSEFTF WP03).
        // AuditQueryReadModelInterface is bound in register() above; resolve
        // it optionally so slimmed-down installs lacking waaseyaa/audit boot
        // cleanly. The controller handles null read-model → empty response.
        $auditReadModel = $this->resolveOptional(AuditQueryReadModelInterface::class);
        $routers[] = new AuditApiRouter(
            new AuditQueryController(
                $auditReadModel instanceof AuditQueryReadModelInterface ? $auditReadModel : null,
            ),
        );

        if (self::mcpInstalled()) {
            $mcpRegistry = $this->resolveOptional(ToolRegistryReadModelInterface::class);
            $mcpConfig = $this->resolveOptional(ServerConfigReadModelInterface::class);
            $routers[] = new McpAdminApiRouter(new McpAdminController(
                registry: $mcpRegistry instanceof ToolRegistryReadModelInterface ? $mcpRegistry : null,
                config: $mcpConfig instanceof ServerConfigReadModelInterface ? $mcpConfig : null,
            ));
        }

        // DIR-005 (versioned-blob-media-abstraction-01KSEFTJ): media version
        // read API. The read-model is bound in register() above; if the media
        // package is absent (slimmed-down install) the controller falls back to
        // empty/404 shapes without crashing boot. Routes are registered in
        // routes() below, gated by _authenticated.
        $mediaVersionReadModel = $this->resolveOptional(MediaVersionReadModelInterface::class);
        $routers[] = new MediaVersionApiRouter(
            new MediaVersionController(
                $mediaVersionReadModel instanceof MediaVersionReadModelInterface ? $mediaVersionReadModel : null,
            ),
        );

        // WP05: OIDC client CRUD admin API. The oidc_client entity type is
        // registered by OidcServiceProvider when the opt-in domain is installed.
        if ($httpKernel->getEntityTypeManager()->hasDefinition('oidc_client')) {
            $routers[] = new OidcClientApiRouter(new OidcClientController($httpKernel->getEntityTypeManager()));
        }

        return $routers;
    }

    public function routes(WaaseyaaRouter $router, EntityTypeManager $entityTypeManager): void
    {
        $jsonApiRouteProvider = new JsonApiRouteProvider($entityTypeManager);
        $jsonApiRouteProvider->registerRoutes($router);

        // CW-v1 WP-4 (#1920): gated on TransitionService resolving — see the
        // matching gate in httpDomainRouters() above for the full rationale.
        $transitionService = $this->resolveOptional(TransitionService::class);
        if ($transitionService instanceof TransitionService) {
            $jsonApiRouteProvider->registerWorkflowTransitionRoutes($router);
        }

        // Schema self-description surface requires authentication: it enumerates
        // every registered entity type plus its attribute/field schema, and computes
        // field-access against a value-less prototype entity — disclosing the
        // DEFINITIONS of instance-state-gated fields (e.g. classification-gated)
        // that a real row would deny. SCOPE: only these two REST routes (#1649).
        $router->addRoute(
            'api.schema.show',
            RouteBuilder::create('/api/schema/{entity_type}')
                ->controller('Waaseyaa\\Api\\Controller\\SchemaController::show')
                ->requireAuthentication()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'api.workflow_definitions.list',
            RouteBuilder::create('/api/workflow-definitions')
                ->controller('Waaseyaa\\Api\\Workflow\\WorkflowDefinitionsController::list')
                ->requireRole('admin')
                ->methods('GET')
                ->build(),
        );

        // M4B WP01: admin queue dashboard. Failed-jobs MVP only — queued/in-flight
        // job columns ship later once `TransportInterface::listJobs()` exists
        // (see WP01 follow-up issue tracked under #1471).
        $queueController = 'Waaseyaa\\Api\\Controller\\QueueController';
        $router->addRoute(
            'api.queue.jobs.index',
            RouteBuilder::create('/api/queue/jobs')
                ->controller($queueController . '::index')
                ->requireRole('admin')
                ->methods('GET')
                ->build(),
        );
        $router->addRoute(
            'api.queue.jobs.retry',
            RouteBuilder::create('/api/queue/jobs/{id}/retry')
                ->controller($queueController . '::retry')
                ->requireRole('admin')
                ->methods('POST')
                ->build(),
        );
        $router->addRoute(
            'api.queue.jobs.discard',
            RouteBuilder::create('/api/queue/jobs/{id}/discard')
                ->controller($queueController . '::discard')
                ->requireRole('admin')
                ->methods('POST')
                ->build(),
        );

        // M4B WP02: admin scheduler dashboard. Read-mostly view of the cron
        // registry plus a "Run now" trigger. Tasks themselves remain
        // code-defined via attributes (C-002) — no edit UI.
        $schedulerController = 'Waaseyaa\\Api\\Controller\\SchedulerController';
        $router->addRoute(
            'api.scheduler.tasks.index',
            RouteBuilder::create('/api/scheduler/tasks')
                ->controller($schedulerController . '::index')
                ->requireRole('admin')
                ->methods('GET')
                ->build(),
        );
        $router->addRoute(
            'api.scheduler.tasks.trigger',
            RouteBuilder::create('/api/scheduler/tasks/{name}/trigger')
                ->controller($schedulerController . '::trigger')
                ->requireRole('admin')
                ->methods('POST')
                ->build(),
        );

        // M4C WP01: admin notifications dashboard. Channels list + synthetic
        // test send. Delivery log + per-channel enable/disable deferred —
        // notification package does not yet carry the persistence.
        $notificationController = 'Waaseyaa\\Api\\Controller\\NotificationController';
        $router->addRoute(
            'api.notification.channels.index',
            RouteBuilder::create('/api/notification/channels')
                ->controller($notificationController . '::index')
                ->requireRole('admin')
                ->methods('GET')
                ->build(),
        );
        $router->addRoute(
            'api.notification.channels.test',
            RouteBuilder::create('/api/notification/channels/{type}/test')
                ->controller($notificationController . '::test')
                ->requireRole('admin')
                ->methods('POST')
                ->build(),
        );

        // M5D WP01: Mercure broadcast monitor endpoints. All gated by
        // `_role: admin`; controller does NOT re-check role (NFR-001 / DIR-004).
        $mmController = 'Waaseyaa\\Api\\Controller\\MercureMonitorController';
        $router->addRoute(
            'api.mercure.monitor.channels',
            RouteBuilder::create('/api/mercure/channels')
                ->controller($mmController . '::channels')
                ->requireRole('admin')
                ->methods('GET')
                ->build(),
        );
        $router->addRoute(
            'api.mercure.monitor.events',
            RouteBuilder::create('/api/mercure/events')
                ->controller($mmController . '::events')
                ->requireRole('admin')
                ->methods('GET')
                ->build(),
        );
        $router->addRoute(
            'api.mercure.monitor.subscribers',
            RouteBuilder::create('/api/mercure/subscribers')
                ->controller($mmController . '::subscribers')
                ->requireRole('admin')
                ->methods('GET')
                ->build(),
        );

        // DIR-005 (versioned-blob-media-abstraction-01KSEFTJ WP03 T-L):
        // Media version read API — list all versions + show a specific version.
        // Gated by _authenticated (FR-008): any logged-in account may call;
        // per-version filtering is applied inside the read-model adapter
        // (GateInterface) — forbidden versions are silently omitted from lists
        // and return 403 on direct show. Binary-stream download deferred (FR-010).
        $mvController = 'Waaseyaa\\Api\\Controller\\MediaVersionController';
        $router->addRoute(
            'api.media.versions.index',
            RouteBuilder::create('/api/media/{uuid}/versions')
                ->controller($mvController . '::index')
                ->requireAuthentication()
                ->methods('GET')
                ->build(),
        );
        $router->addRoute(
            'api.media.versions.show',
            RouteBuilder::create('/api/media/{uuid}/versions/{vid}')
                ->controller($mvController . '::show')
                ->requireAuthentication()
                ->methods('GET')
                ->build(),
        );

        // OCAP audit log substrate (ocap-audit-log-substrate-01KSEFTF).
        // Controller wired in WP03 (packages/api). Route reserved here so
        // the api package registers the named route independently of the
        // foundation kernel. Refs: gap-matrix-A3, DIR-004.
        $router->addRoute(
            'api.audit.events.index',
            RouteBuilder::create('/api/audit/events')
                ->controller('Waaseyaa\\Api\\Controller\\AuditQueryController::index')
                ->requireRole('admin')
                ->methods('GET')
                ->build(),
        );

        if (self::mcpInstalled()) {
            // M5C WP01: MCP endpoint admin — read-only tool registry + server config.
            // All three endpoints gated by `_role: admin`; controller does NOT
            // re-check role (NFR-001 / DIR-004). Refs C-L6-01, DIR-004.
            $mcpAdminController = 'Waaseyaa\\Api\\Controller\\McpAdminController';
            $router->addRoute(
                'api.mcp.admin.tools.index',
                RouteBuilder::create('/api/mcp/tools')
                    ->controller($mcpAdminController . '::tools')
                    ->requireRole('admin')
                    ->methods('GET')
                    ->build(),
            );
            $router->addRoute(
                'api.mcp.admin.tools.show',
                RouteBuilder::create('/api/mcp/tools/{name}')
                    ->controller($mcpAdminController . '::tool')
                    ->requireRole('admin')
                    ->methods('GET')
                    ->build(),
            );
            $router->addRoute(
                'api.mcp.admin.server-config',
                RouteBuilder::create('/api/mcp/server-config')
                    ->controller($mcpAdminController . '::serverConfig')
                    ->requireRole('admin')
                    ->methods('GET')
                    ->build(),
            );
        }

        // WP05 (oidc-flows-completion-01KSEFTP): OIDC client admin CRUD API.
        // The entity definition is the installation/activation signal. All
        // endpoints require admin role. client_secret is returned once on
        // create/regenerate; omitted on all other responses.
        if ($entityTypeManager->hasDefinition('oidc_client')) {
            $oidcClientController = 'Waaseyaa\\Api\\Controller\\OidcClientController';
            $router->addRoute(
                'api.oidc-clients.index',
                RouteBuilder::create('/api/oidc-clients')
                ->controller($oidcClientController . '::index')
                ->requireRole('admin')
                ->methods('GET')
                ->build(),
            );
            $router->addRoute(
                'api.oidc-clients.create',
                RouteBuilder::create('/api/oidc-clients')
                ->controller($oidcClientController . '::create')
                ->requireRole('admin')
                ->methods('POST')
                ->build(),
            );
            $router->addRoute(
                'api.oidc-clients.show',
                RouteBuilder::create('/api/oidc-clients/{id}')
                ->controller($oidcClientController . '::show')
                ->requireRole('admin')
                ->methods('GET')
                ->build(),
            );
            $router->addRoute(
                'api.oidc-clients.update',
                RouteBuilder::create('/api/oidc-clients/{id}')
                ->controller($oidcClientController . '::update')
                ->requireRole('admin')
                ->methods('PATCH')
                ->build(),
            );
            $router->addRoute(
                'api.oidc-clients.delete',
                RouteBuilder::create('/api/oidc-clients/{id}')
                ->controller($oidcClientController . '::delete')
                ->requireRole('admin')
                ->methods('DELETE')
                ->build(),
            );
            $router->addRoute(
                'api.oidc-clients.regenerate-secret',
                RouteBuilder::create('/api/oidc-clients/{id}/regenerate-secret')
                ->controller($oidcClientController . '::regenerateSecret')
                ->requireRole('admin')
                ->methods('POST')
                ->build(),
            );
        }

        // Classification retention-engine (classification-retention-engine-01KSEFTH WP02).
        // Friendly URLs for the RetentionPolicy entity served via the framework's
        // standard JSON:API entity controller. Read endpoints gate to
        // `governance-viewer` (audit/legal read-only) OR `admin`; mutations gate
        // to `admin` only. The auto-generated `/api/retention_policy` routes
        // (from JsonApiRouteProvider) remain reachable; these aliases exist for
        // discoverability and stable URL contracts documented in the admin SPA.
        // Refs: FR-008, NFR-001 / DIR-004.
        $retentionPolicyController = 'Waaseyaa\\Api\\JsonApiController';
        $router->addRoute(
            'api.classification.policies.index',
            RouteBuilder::create('/api/classification/policies')
                ->controller($retentionPolicyController . '::index')
                ->requireRole('governance-viewer,admin')
                ->methods('GET')
                ->default('_entity_type', 'retention_policy')
                ->build(),
        );
        $router->addRoute(
            'api.classification.policies.show',
            RouteBuilder::create('/api/classification/policies/{id}')
                ->controller($retentionPolicyController . '::show')
                ->requireRole('governance-viewer,admin')
                ->methods('GET')
                ->default('_entity_type', 'retention_policy')
                ->build(),
        );
        $router->addRoute(
            'api.classification.policies.store',
            RouteBuilder::create('/api/classification/policies')
                ->controller($retentionPolicyController . '::store')
                ->requireRole('admin')
                ->methods('POST')
                ->default('_entity_type', 'retention_policy')
                ->build(),
        );
        $router->addRoute(
            'api.classification.policies.update',
            RouteBuilder::create('/api/classification/policies/{id}')
                ->controller($retentionPolicyController . '::update')
                ->requireRole('admin')
                ->methods('PATCH')
                ->default('_entity_type', 'retention_policy')
                ->build(),
        );
        $router->addRoute(
            'api.classification.policies.destroy',
            RouteBuilder::create('/api/classification/policies/{id}')
                ->controller($retentionPolicyController . '::destroy')
                ->requireRole('admin')
                ->methods('DELETE')
                ->default('_entity_type', 'retention_policy')
                ->build(),
        );
    }

    private static function mcpInstalled(): bool
    {
        return class_exists('Waaseyaa\\Mcp\\McpServiceProvider');
    }
}
