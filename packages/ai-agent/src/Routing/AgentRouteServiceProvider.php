<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Routing;

use Waaseyaa\Access\AccessChecker;
use Waaseyaa\AI\Agent\Access\AgentRunAccessPolicy;
use Waaseyaa\AI\Agent\AgentDefinitionRegistry;
use Waaseyaa\AI\Agent\Broadcast\AgentRunBroadcasterInterface;
use Waaseyaa\AI\Agent\Controller\AgentRunController;
use Waaseyaa\AI\Agent\Controller\AgentRunRequestValidator;
use Waaseyaa\AI\Agent\Repository\AgentAuditLogRepository;
use Waaseyaa\AI\Agent\Repository\AgentRunRepository;
use Waaseyaa\AI\Agent\Service\AgentRunService;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * Wire HTTP routes for the Agent Executor.
 *
 * **Why this lives in waaseyaa/ai-agent (not waaseyaa/routing).**
 * The constitution's layer rule forbids upward imports. `routing` (L4)
 * cannot import `ai-agent` (L5), so the controller and the route
 * registration both live here. The downward import
 * `Waaseyaa\AI\Agent\Routing` → `Waaseyaa\Routing\*` is L5 → L4, allowed
 * by the layer table. This mirrors the precedent set by
 * {@see \Waaseyaa\Routing\AuthOidcRouteServiceProvider} (route wiring
 * lifted out of L1 packages into L4) — only here the wiring lives in
 * the same higher-layer package as the controller, because routing
 * cannot reach up to L5.
 *
 * Routes registered (per `contracts/agent-run-api.yaml`):
 *
 *  - POST   /api/ai/agent/run            → AgentRunController::create
 *  - GET    /api/ai/agent/run/{id}       → AgentRunController::show
 *  - DELETE /api/ai/agent/run/{id}       → AgentRunController::cancel
 *  - POST   /api/ai/agent/run/{id}/approve → AgentRunController::approve
 *
 * Capability layer:
 *
 *  - All endpoints require `_authenticated: true` and `_permission: 'agent.run'`
 *    (or `agent.run.approve` for the approval endpoint), evaluated by
 *    {@see AccessChecker} from the matched route's options.
 *  - Ownership is enforced inside the controller using
 *    {@see AgentRunAccessPolicy}, so the response is a JSON-shaped 403.
 *
 * @api
 */
final class AgentRouteServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function routes(WaaseyaaRouter $router, EntityTypeManager $entityTypeManager): void
    {
        unset($entityTypeManager); // unused — agent routes don't need entity meta

        // Defer controller resolution until the route is actually matched.
        // Some kernels (CLI, OIDC/SSR integration test fixtures) skip
        // binding the AI message bus + DB deps that AgentRunController
        // pulls in, so eager construction at boot time crashes the
        // kernel for those callers. Lazy closures keep the routes
        // registered (`/api/ai/agent/run` is still discoverable) without
        // forcing the dependency graph.
        $factory = fn(): AgentRunController => $this->buildController();

        $router->addRoute(
            'api.ai.agent.run.create',
            RouteBuilder::create('/api/ai/agent/run')
                ->controller(static fn(\Symfony\Component\HttpFoundation\Request $request) => $factory()->create($request))
                ->methods('POST')
                ->requireAuthentication()
                ->requirePermission('agent.run')
                ->build(),
        );

        $router->addRoute(
            'api.ai.agent.run.show',
            RouteBuilder::create('/api/ai/agent/run/{id}')
                ->controller(static fn(\Symfony\Component\HttpFoundation\Request $request, string $id) => $factory()->show($request, $id))
                ->methods('GET')
                ->requireAuthentication()
                ->requirePermission('agent.run')
                ->build(),
        );

        $router->addRoute(
            'api.ai.agent.run.cancel',
            RouteBuilder::create('/api/ai/agent/run/{id}')
                ->controller(static fn(\Symfony\Component\HttpFoundation\Request $request, string $id) => $factory()->cancel($request, $id))
                ->methods('DELETE')
                ->requireAuthentication()
                ->requirePermission('agent.run')
                ->build(),
        );

        $router->addRoute(
            'api.ai.agent.run.approve',
            RouteBuilder::create('/api/ai/agent/run/{id}/approve')
                ->controller(static fn(\Symfony\Component\HttpFoundation\Request $request, string $id) => $factory()->approve($request, $id))
                ->methods('POST')
                ->requireAuthentication()
                ->requirePermission('agent.run.approve')
                ->build(),
        );
    }

    private function buildController(): AgentRunController
    {
        return new AgentRunController(
            runService: $this->resolve(AgentRunService::class),
            runRepository: $this->resolve(AgentRunRepository::class),
            auditRepository: $this->resolve(AgentAuditLogRepository::class),
            definitionRegistry: $this->resolve(AgentDefinitionRegistry::class),
            broadcaster: $this->resolve(AgentRunBroadcasterInterface::class),
            accessPolicy: $this->safeResolve(AgentRunAccessPolicy::class) ?? new AgentRunAccessPolicy(
                $this->resolve(AgentRunRepository::class),
            ),
            validator: new AgentRunRequestValidator(),
            accountProjectionReader: $this->resolve(\Waaseyaa\AI\Agent\Security\AgentRunAccountProjectionReaderInterface::class),
            logger: $this->safeResolve(LoggerInterface::class) ?? new NullLogger(),
        );
    }

    private function safeResolve(string $abstract): mixed
    {
        try {
            return $this->resolve($abstract);
        } catch (\Throwable) {
            return null;
        }
    }
}
