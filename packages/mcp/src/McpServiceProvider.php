<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\ToolRegistryInterface as AgentToolRegistryInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Mcp\Auth\BearerTokenAuth;
use Waaseyaa\Mcp\Auth\McpAuthInterface;
use Waaseyaa\Mcp\Bridge\AgentToolRegistryBridge;
use Waaseyaa\Mcp\Bridge\ToolExecutorInterface;
use Waaseyaa\Mcp\Bridge\ToolRegistryInterface;
use Waaseyaa\Routing\WaaseyaaRouter;

final class McpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // McpAuthInterface default: empty-token BearerTokenAuth. Production
        // deployments override either via a kernel-services binding or by
        // re-binding this abstract in an application-level provider.
        $this->singleton(
            McpAuthInterface::class,
            fn(): McpAuthInterface => new BearerTokenAuth(tokens: []),
        );

        // AgentToolRegistryBridge — wraps the framework-wide agent-tool
        // registry (bound by AiToolsServiceProvider) so MCP can list and
        // dispatch every #[AsAgentTool] tool through one adapter. The bridge
        // takes AccountInterface at construction; AccountInterface is a
        // per-request value (set by SessionMiddleware) but register() runs
        // once at boot, so we bind with a placeholder no-permission account.
        // tools/list works (no permission check); tools/call returns the
        // documented `forbidden` envelope until WP03 lands per-request
        // account passthrough into the bridge. See
        // kitty-specs/bimaaji-mcp-bridge-01KS5VS8/tasks/WP02-read-tools.md
        // for the documented limitation.
        $this->singleton(
            AgentToolRegistryBridge::class,
            fn(): AgentToolRegistryBridge => new AgentToolRegistryBridge(
                registry: $this->resolveAgentToolRegistry(),
                account: $this->placeholderAccount(),
            ),
        );

        $this->singleton(
            ToolRegistryInterface::class,
            fn(): ToolRegistryInterface => $this->resolve(AgentToolRegistryBridge::class),
        );

        $this->singleton(
            ToolExecutorInterface::class,
            fn(): ToolExecutorInterface => $this->resolve(AgentToolRegistryBridge::class),
        );
    }

    public function routes(WaaseyaaRouter $router, EntityTypeManagerInterface $entityTypeManager): void
    {
        new McpRouteProvider()->registerRoutes($router);
    }

    private function resolveAgentToolRegistry(): AgentToolRegistryInterface
    {
        $registry = $this->kernelServices?->get(AgentToolRegistryInterface::class);
        if ($registry instanceof AgentToolRegistryInterface) {
            return $registry;
        }

        throw new \RuntimeException(
            'Mcp\\McpServiceProvider: no Waaseyaa\\AI\\Tools\\ToolRegistryInterface bound on the '
            . 'kernel-services bus. The MCP bridge requires the agent-tool registry shipped by '
            . 'waaseyaa/ai-tools (AiToolsServiceProvider must register before McpServiceProvider).',
        );
    }

    /**
     * No-permission anonymous-shaped account used as the placeholder bridge
     * account. WP03 (per-request account passthrough) will replace this with
     * the auth-resolved AccountInterface from each /mcp request.
     */
    private function placeholderAccount(): AccountInterface
    {
        return new class implements AccountInterface {
            public function id(): int
            {
                return 0;
            }

            public function hasPermission(string $permission): bool
            {
                return false;
            }

            public function getRoles(): array
            {
                return [];
            }

            public function isAuthenticated(): bool
            {
                return false;
            }
        };
    }
}
