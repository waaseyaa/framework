<?php

declare(strict_types=1);

namespace Waaseyaa\Workspace;

use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * Opt-in workspace shell package (Layer 6).
 *
 * This first cut ships the shared chat surface: the parameterized SSE chat
 * client (workspace-chat.js), the markdown renderer with status-vocabulary
 * and link-policy hooks (workspace-md.js), their stylesheet, and the
 * chat-panel Twig scaffold. The chat transport CONTRACT the client speaks is
 * documented in docs/specs/workspace-chat-surface.md — any app controller
 * that satisfies it (Anokii's CoIntelligenceController is the reference
 * consumer) can drive this client, and the framework's ai-agent transport
 * can replace such a controller later without client changes.
 *
 * Later cuts add the canvas+rails layout primitive and the preview panel.
 */
final class WorkspaceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Assets are served by route; no container bindings needed yet.
    }

    public function routes(WaaseyaaRouter $router, EntityTypeManager $entityTypeManager): void
    {
        // priority(1): outrank the SSR /{path} catch-all deterministically,
        // including in apps that follow the framework guidance of giving
        // their own catch-alls priority >= 1.
        $router->addRoute(
            'workspace.asset',
            RouteBuilder::create('/workspace/assets/{file}')
                ->controller(WorkspaceAssetController::class . '::serve')
                ->allowAll()
                ->render()
                ->methods('GET')
                ->priority(1)
                ->build(),
        );
    }
}
