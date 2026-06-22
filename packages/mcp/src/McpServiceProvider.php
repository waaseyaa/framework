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
use Waaseyaa\Mcp\Auth\PublicAnonymousAuth;
use Waaseyaa\Mcp\Auth\WriteTierAuthInterface;
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
        // McpAuthInterface default: public read-only. Every request resolves to
        // an anonymous account holding ONLY the read capabilities (capability
        // layer of the read-only boundary). A deployment wanting an
        // authenticated write surface re-binds this with a delegate auth.
        $this->singleton(
            McpAuthInterface::class,
            static fn(): McpAuthInterface => new PublicAnonymousAuth(),
        );

        // Configurable server card (identity + declared auth + registry fields).
        $this->singleton(
            McpServerCard::class,
            fn(): McpServerCard => new McpServerCard($this->serverCardConfig()),
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

                // Structural layer of the read-only boundary: the endpoint only
                // ever sees non-destructive tools on the read-capability
                // allowlist, so write tools are absent from tools/list and
                // rejected by tools/call regardless of the resolved account.
                $readOnlyRegistry = new ReadOnlyToolRegistry(
                    $this->resolve(AgentToolRegistryInterface::class),
                    PublicAnonymousAuth::DEFAULT_READ_CAPABILITIES,
                );

                return new McpEndpoint(
                    auth: $this->resolve(McpAuthInterface::class),
                    agentRegistry: $readOnlyRegistry,
                    dispatcher: $dispatcher instanceof EventDispatcherInterface ? $dispatcher : null,
                    accountContext: $accountContext instanceof AccountContextInterface ? $accountContext : null,
                );
            },
        );

        // Authenticated MCP write tier (FR-004) — a SEPARATE endpoint from the
        // public read-only `/mcp`, so the alpha.221 trio is untouched (C-001).
        //
        // The write-tier auth is the documented app override point
        // (`WriteTierAuthInterface`, `docs/public-surface-map.php`). It is
        // resolved per-request by resolveWriteTierAuth() below and is
        // deliberately NOT bound to a package default HERE: binding a default in
        // this provider shadows an app override, because ServiceProvider::resolve()
        // consults the provider's OWN bindings before the cross-provider
        // kernel-services bus. That shadowing was the alpha.233 stress-test
        // blocker P0-1 — an app could bind WriteTierAuthInterface but `/mcp/write`
        // still used the empty-token default and always 401'd. See
        // kitty-specs/wayfinding-stress-remediation-01KVGK4Q/spec.md
        // ("The P0-1 precedence decision").
        $this->singleton(
            AuthenticatedMcpEndpoint::class,
            function (): AuthenticatedMcpEndpoint {
                $dispatcher = $this->resolveOptional(EventDispatcherInterface::class);
                $accountContext = $this->resolveOptional(AccountContextInterface::class);

                // Capability-scoped structural layer: this tier exposes ONLY tools
                // whose capability is on the write-tier allowlist (destructive
                // included) — not the whole destructive catalogue. Defaults to the
                // Wayfinding `present guided content` capability; override via the
                // `mcp.write_tier.capabilities` config.
                $writeRegistry = new CapabilityScopedToolRegistry(
                    $this->resolve(AgentToolRegistryInterface::class),
                    $this->writeTierCapabilities(),
                );

                $inner = new McpEndpoint(
                    auth: $this->resolveWriteTierAuth(),
                    agentRegistry: $writeRegistry,
                    dispatcher: $dispatcher instanceof EventDispatcherInterface ? $dispatcher : null,
                    accountContext: $accountContext instanceof AccountContextInterface ? $accountContext : null,
                );

                return new AuthenticatedMcpEndpoint($inner);
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

    /**
     * Resolve the authenticated write-tier auth, preferring an application
     * override and failing closed otherwise (FR-001/FR-003, P0-1).
     *
     * An application binds `WriteTierAuthInterface` in its OWN service provider
     * (e.g. `BearerTokenAuth` mapping a token to an account holding the
     * `present guided content` capability). `resolveOptional()` falls through
     * this provider's (deliberately empty) local bindings to the cross-provider
     * kernel-services bus, so the app binding is what reaches the write tier —
     * it is no longer shadowed by a package default. When no provider supplies
     * one, this fails closed with an empty-token `BearerTokenAuth`, so every
     * `/mcp/write` request is HTTP 401: the framework ships no usable default
     * credential (token→account mapping is inherently application-specific).
     */
    private function resolveWriteTierAuth(): WriteTierAuthInterface
    {
        $auth = $this->resolveOptional(WriteTierAuthInterface::class);

        return $auth instanceof WriteTierAuthInterface ? $auth : new BearerTokenAuth([]);
    }

    /**
     * The capability allowlist for the authenticated write tier. Tools whose
     * capability is listed are exposed on `/mcp/write` (destructive included).
     * Defaults to the Wayfinding `present guided content` capability; override
     * via `mcp.write_tier.capabilities` (a list of capability strings).
     *
     * @return non-empty-list<string>
     */
    private function writeTierCapabilities(): array
    {
        $mcp = $this->config['mcp'] ?? null;
        $writeTier = \is_array($mcp) && \is_array($mcp['write_tier'] ?? null) ? $mcp['write_tier'] : [];
        $capabilities = $writeTier['capabilities'] ?? null;

        $resolved = [];
        if (\is_array($capabilities)) {
            foreach ($capabilities as $capability) {
                if (\is_string($capability) && $capability !== '') {
                    $resolved[] = $capability;
                }
            }
        }

        return $resolved !== [] ? $resolved : ['present guided content'];
    }

    /**
     * Resolve the server-card config from `mcp.server_card` config, defaulting
     * to the public read-only card.
     */
    private function serverCardConfig(): McpServerCardConfig
    {
        $mcp = $this->config['mcp'] ?? null;
        $card = \is_array($mcp) && \is_array($mcp['server_card'] ?? null) ? $mcp['server_card'] : [];

        return McpServerCardConfig::fromArray($card);
    }
}
