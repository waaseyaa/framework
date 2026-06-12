<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\AI\Tools\ToolRegistryInterface as AgentToolRegistryInterface;
use Waaseyaa\Api\McpAdmin\ServerConfigReadModelInterface;
use Waaseyaa\Api\McpAdmin\ToolRegistryReadModelInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Mcp\Admin\RecentInvocationsQueryInterface;
use Waaseyaa\Mcp\Admin\ServerConfigReadModel;
use Waaseyaa\Mcp\Admin\ToolRegistryReadModel;
use Waaseyaa\Mcp\Auth\BearerTokenAuth;
use Waaseyaa\Mcp\Auth\McpAuthInterface;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * Service provider for `waaseyaa/mcp`. Binds the `McpAuthInterface` default
 * and registers the `/mcp` + `/.well-known/mcp.json` routes.
 *
 * **WP03 architecture note.** `McpEndpoint` resolves the framework-wide
 * `Waaseyaa\AI\Tools\ToolRegistryInterface` from the kernel-services bus
 * (bound by `AiToolsServiceProvider`) and constructs a per-request
 * `AgentToolRegistryBridge` with the auth-resolved `AccountInterface` from
 * `McpAuthInterface::authenticate()`. This closes the WP02 caveat where
 * `register()` baked in a placeholder account; per-tool capability gating
 * now runs against the correct identity. `Mcp\Bridge\ToolRegistryInterface`
 * and `Mcp\Bridge\ToolExecutorInterface` remain `@api` as bridge contracts
 * but are no longer container-bound at boot — the bridge is request-scoped.
 *
 * @api
 */
final class McpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // McpAuthInterface default: empty-token BearerTokenAuth. Production
        // deployments override either by binding McpAuthInterface on the
        // kernel-services bus or by re-binding the abstract here.
        $this->singleton(
            McpAuthInterface::class,
            fn(): McpAuthInterface => new BearerTokenAuth(tokens: []),
        );

        // McpEndpoint: bound explicitly so AppControllerRouter's controller
        // resolution (which checks provider bindings before falling back to
        // reflection autowiring) injects the kernel-services event dispatcher
        // and acting-account context. Both are optional — when the kernel
        // bus cannot supply one, the endpoint degrades to its pre-provenance
        // behavior (no `waaseyaa.mcp.dispatch` event / no context scoping).
        $this->singleton(
            McpEndpoint::class,
            function (): McpEndpoint {
                $dispatcher = $this->resolveOptional(EventDispatcherInterface::class);
                $accountContext = $this->resolveOptional(AccountContextInterface::class);

                return new McpEndpoint(
                    auth: $this->resolve(McpAuthInterface::class),
                    agentRegistry: $this->resolve(AgentToolRegistryInterface::class),
                    dispatcher: $dispatcher instanceof EventDispatcherInterface ? $dispatcher : null,
                    accountContext: $accountContext instanceof AccountContextInterface ? $accountContext : null,
                );
            },
        );

        // M5C WP01 T003: admin read-model bindings.
        // ToolRegistryReadModelInterface → ToolRegistryReadModel (resolves
        // AgentToolRegistryInterface from container; RecentInvocationsQueryInterface
        // is optional — absent when waaseyaa/ai-observability is not installed).
        $this->singleton(
            ToolRegistryReadModelInterface::class,
            function (): ToolRegistryReadModelInterface {
                $agentRegistry = $this->resolve(AgentToolRegistryInterface::class);
                $invocationQuery = $this->resolveOptional(RecentInvocationsQueryInterface::class);

                return new ToolRegistryReadModel(
                    agentRegistry: $agentRegistry,
                    invocationQuery: $invocationQuery instanceof RecentInvocationsQueryInterface
                        ? $invocationQuery
                        : null,
                );
            },
        );

        // ServerConfigReadModelInterface → ServerConfigReadModel.
        // Delegates to McpAuthInterface for registered-client enumeration.
        $this->singleton(
            ServerConfigReadModelInterface::class,
            fn(): ServerConfigReadModelInterface => new ServerConfigReadModel(
                auth: $this->resolve(McpAuthInterface::class),
            ),
        );
    }

    public function routes(WaaseyaaRouter $router, EntityTypeManagerInterface $entityTypeManager): void
    {
        new McpRouteProvider()->registerRoutes($router);
    }
}
