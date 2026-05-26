<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel;

use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * Registers all built-in HTTP routes on the router.
 *
 * Handles JSON:API entity routes, schema, OpenAPI, discovery endpoints,
 * media upload, Telescope agent-context (codified-context) JSON routes,
 * SSR page rendering, and app-level provider routes.
 */
final class BuiltinRouteRegistrar
{
    /**
     * @param list<ServiceProvider> $providers
     */
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly array $providers = [],
    ) {}

    public function register(WaaseyaaRouter $router): void
    {
        $router->addRoute(
            'api.schema.show',
            RouteBuilder::create('/api/schema/{entity_type}')
                ->controller('Waaseyaa\\Api\\Controller\\SchemaController::show')
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'api.openapi',
            RouteBuilder::create('/api/openapi.json')
                ->controller('openapi')
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'api.entity_types',
            RouteBuilder::create('/api/entity-types')
                ->controller('entity_types')
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'api.entity_types.disable',
            RouteBuilder::create('/api/entity-types/{entity_type}/disable')
                ->controller('entity_type.disable')
                ->requireRole('admin')
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'api.entity_types.enable',
            RouteBuilder::create('/api/entity-types/{entity_type}/enable')
                ->controller('entity_type.enable')
                ->requireRole('admin')
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'api.broadcast',
            RouteBuilder::create('/api/broadcast')
                ->controller('broadcast')
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'api.media.upload',
            RouteBuilder::create('/api/media/upload')
                ->controller('media.upload')
                ->requirePermission('access media')
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'api.search',
            RouteBuilder::create('/api/search')
                ->controller('search.semantic')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'api.discovery.hub',
            RouteBuilder::create('/api/discovery/hub/{entity_type}/{id}')
                ->controller('discovery.topic_hub')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'api.discovery.cluster',
            RouteBuilder::create('/api/discovery/cluster/{entity_type}/{id}')
                ->controller('discovery.cluster')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'api.discovery.timeline',
            RouteBuilder::create('/api/discovery/timeline/{entity_type}/{id}')
                ->controller('discovery.timeline')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'api.discovery.endpoint',
            RouteBuilder::create('/api/discovery/endpoint/{entity_type}/{id}')
                ->controller('discovery.endpoint')
                ->allowAll()
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

        $router->addRoute(
            'api.workflow_definitions.dry_run',
            RouteBuilder::create('/api/workflow-definitions/dry-run')
                ->controller('Waaseyaa\\Api\\Workflow\\WorkflowDryRunController::dryRun')
                ->requireRole('admin')
                ->methods('POST')
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

        // M4A-5 Phase 1: read-only workflow-guards matrix endpoint
        // (mission `workflow-guards-readonly-01KSDS5W`, parent #1470). The
        // mutation surface is deferred to M4A-5b after a persistence ADR
        // exists (C-001). Admin-only by route option; the controller does
        // not re-check the role (NFR-001).
        $router->addRoute(
            'api.workflow.guards.index',
            RouteBuilder::create('/api/workflow-definitions/{workflow_id}/guards')
                ->controller('Waaseyaa\\Api\\Controller\\WorkflowGuardsController::index')
                ->requireRole('admin')
                ->methods('GET')
                ->build(),
        );

        $ccController = 'Waaseyaa\\Api\\Controller\\CodifiedContextController';
        $router->addRoute(
            'api.telescope.agent_context.sessions',
            RouteBuilder::create('/api/telescope/agent-context/sessions')
                ->controller($ccController . '::listSessions')
                ->requireRole('admin')
                ->methods('GET')
                ->build(),
        );
        $router->addRoute(
            'api.telescope.agent_context.session',
            RouteBuilder::create('/api/telescope/agent-context/sessions/{sessionId}')
                ->controller($ccController . '::getSession')
                ->requireRole('admin')
                ->methods('GET')
                ->build(),
        );
        $router->addRoute(
            'api.telescope.agent_context.session_events',
            RouteBuilder::create('/api/telescope/agent-context/sessions/{sessionId}/events')
                ->controller($ccController . '::getSessionEvents')
                ->requireRole('admin')
                ->methods('GET')
                ->build(),
        );
        $router->addRoute(
            'api.telescope.agent_context.session_validation',
            RouteBuilder::create('/api/telescope/agent-context/sessions/{sessionId}/validation')
                ->controller($ccController . '::getSessionValidation')
                ->requireRole('admin')
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'api.telescope.codified_context.sessions',
            RouteBuilder::create('/api/telescope/codified-context/sessions')
                ->controller($ccController . '::listSessions')
                ->requireRole('admin')
                ->methods('GET')
                ->build(),
        );
        $router->addRoute(
            'api.telescope.codified_context.session',
            RouteBuilder::create('/api/telescope/codified-context/sessions/{sessionId}')
                ->controller($ccController . '::getSession')
                ->requireRole('admin')
                ->methods('GET')
                ->build(),
        );
        $router->addRoute(
            'api.telescope.codified_context.session_events',
            RouteBuilder::create('/api/telescope/codified-context/sessions/{sessionId}/events')
                ->controller($ccController . '::getSessionEvents')
                ->requireRole('admin')
                ->methods('GET')
                ->build(),
        );
        $router->addRoute(
            'api.telescope.codified_context.session_validation',
            RouteBuilder::create('/api/telescope/codified-context/sessions/{sessionId}/validation')
                ->controller($ccController . '::getSessionValidation')
                ->requireRole('admin')
                ->methods('GET')
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
        // foundation registers the named route independently of the api package.
        // Refs: gap-matrix-A3, DIR-004.
        $router->addRoute(
            'api.audit.events.index',
            RouteBuilder::create('/api/audit/events')
                ->controller('Waaseyaa\\Api\\Controller\\AuditQueryController::index')
                ->requireRole('admin')
                ->methods('GET')
                ->build(),
        );

        // App routes — registered before SSR catchall so they take priority.
        foreach ($this->providers as $provider) {
            $provider->routes($router, $this->entityTypeManager);
        }

        $router->addRoute(
            'public.home',
            RouteBuilder::create('/')
                ->controller('render.page')
                ->allowAll()
                ->render()
                ->methods('GET')
                ->default('path', '/')
                ->build(),
        );

        $router->addRoute(
            'public.page',
            RouteBuilder::create('/{path}')
                ->controller('render.page')
                ->allowAll()
                ->render()
                ->methods('GET')
                ->requirement('path', '(?!api(?:/|$)).+')
                ->build(),
        );

        // WP05 (oidc-flows-completion-01KSEFTP): OIDC client admin CRUD API.
        // All endpoints require admin role. client_secret is returned once on
        // create/regenerate; omitted on all other responses.
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

        $router->sortRoutesByPriority();
    }
}
