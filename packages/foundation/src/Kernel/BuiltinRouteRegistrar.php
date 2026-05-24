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

        $router->sortRoutesByPriority();
    }
}
