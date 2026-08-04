<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\AI\Tools\ToolRegistryInterface as AgentToolRegistryInterface;
use Waaseyaa\Api\McpAdmin\ServerConfigReadModelInterface;
use Waaseyaa\Api\McpAdmin\ToolRegistryReadModelInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Audit\Approval\OperationApprovalStoreInterface;
use Waaseyaa\Foundation\Audit\NullStrictAuditLedger;
use Waaseyaa\Foundation\Audit\StrictAuditLedgerInterface;
use Waaseyaa\Foundation\Exception\ConfigException;
use Waaseyaa\Foundation\Log\LoggerInterface;
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
 * now runs against the correct identity. The per-request bridge talks directly
 * to the AI tools registry; the superseded MCP-local bridge interfaces were
 * removed because no production consumer resolved them
 * but are no longer container-bound at boot — the bridge is request-scoped.
 *
 * @api
 */
final class McpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // McpAuthInterface is deliberately NOT bound here. Binding it would put
        // it in this provider's OWN bindings, and ServiceProvider::resolve()
        // consults those before the cross-provider kernel-services bus — so an
        // application's binding would be shadowed and `/mcp` would stay anonymous
        // no matter what the app did. That is the same P0-1 shadowing already
        // fixed for WriteTierAuthInterface; the public tier now follows it.
        // The anonymous default is applied at the point of use instead, by
        // resolvePublicAuth() below.

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
                $fieldReadScope = $this->resolveOptional(AccountFieldReadScopeInterface::class);

                // Structural layer of the read-only boundary: the endpoint only
                // ever sees non-destructive tools on the read-capability
                // allowlist, so write tools are absent from tools/list and
                // rejected by tools/call regardless of the resolved account.
                $readOnlyRegistry = new ReadOnlyToolRegistry(
                    $this->resolve(AgentToolRegistryInterface::class),
                    PublicAnonymousAuth::DEFAULT_READ_CAPABILITIES,
                );

                [$limiter, $maxRequests, $windowSeconds] = $this->rateLimitSettings();
                $logger = $this->resolveOptional(LoggerInterface::class);

                return new McpEndpoint(
                    auth: $this->resolvePublicAuth(),
                    agentRegistry: $readOnlyRegistry,
                    dispatcher: $dispatcher instanceof EventDispatcherInterface ? $dispatcher : null,
                    accountContext: $accountContext instanceof AccountContextInterface ? $accountContext : null,
                    fieldReadScope: $fieldReadScope instanceof AccountFieldReadScopeInterface ? $fieldReadScope : null,
                    rateLimiter: $limiter,
                    rateLimitMaxRequests: $maxRequests,
                    rateLimitWindowSeconds: $windowSeconds,
                    rateLimitTier: 'public',
                    logger: $logger instanceof LoggerInterface ? $logger : null,
                    // The public read-only tier keeps its documented best-effort
                    // auditing. It mutates nothing, so a durable pre-record buys
                    // no safety, and making it fail-closed would take a read-only
                    // surface down whenever the audit store hiccups.
                    durableAudit: false,
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
                $fieldReadScope = $this->resolveOptional(AccountFieldReadScopeInterface::class);

                // Capability-scoped structural layer: this tier exposes ONLY tools
                // whose capability is on the write-tier allowlist (destructive
                // included) — not the whole destructive catalogue. Defaults to the
                // Wayfinding `present guided content` capability; override via the
                // `mcp.write_tier.capabilities` config.
                $writeRegistry = new CapabilityScopedToolRegistry(
                    $this->resolve(AgentToolRegistryInterface::class),
                    $this->writeTierCapabilities(),
                );

                [$limiter, $maxRequests, $windowSeconds] = $this->rateLimitSettings();
                $logger = $this->resolveOptional(LoggerInterface::class);
                [$ledger, $durableAudit] = $this->writeTierAuditSettings();
                [$approvalStore, $approvalGate] = $this->writeTierApprovalSettings($durableAudit);

                $inner = new McpEndpoint(
                    auth: $this->resolveWriteTierAuth(),
                    agentRegistry: $writeRegistry,
                    dispatcher: $dispatcher instanceof EventDispatcherInterface ? $dispatcher : null,
                    accountContext: $accountContext instanceof AccountContextInterface ? $accountContext : null,
                    fieldReadScope: $fieldReadScope instanceof AccountFieldReadScopeInterface ? $fieldReadScope : null,
                    rateLimiter: $limiter,
                    rateLimitMaxRequests: $maxRequests,
                    rateLimitWindowSeconds: $windowSeconds,
                    rateLimitTier: 'write',
                    logger: $logger instanceof LoggerInterface ? $logger : null,
                    auditLedger: $ledger,
                    durableAudit: $durableAudit,
                    approvalStore: $approvalStore,
                    approvalGate: $approvalGate,
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
                auth: $this->resolvePublicAuth(),
            ),
        );
    }

    public function routes(WaaseyaaRouter $router, EntityTypeManagerInterface $entityTypeManager): void
    {
        new McpRouteProvider($this->publicEndpointEnabled())->registerRoutes($router);
    }

    /**
     * Durable-audit wiring for the authenticated write tier (#2177 F4).
     *
     * Config `mcp.write_tier.durable_audit`; DEFAULT **on**. A surface that
     * mutates content should not be quietly downgraded to best-effort auditing,
     * so the safe value is the default and disabling it is the explicit act.
     *
     * **Fails closed on a wiring gap.** If durable auditing is requested but no
     * `StrictAuditLedgerInterface` can be resolved — typically because
     * `waaseyaa/audit` is not installed — this throws at provider setup rather
     * than substituting {@see NullStrictAuditLedger}. Silently substituting it
     * would leave a write tier that *looks* durably audited and records nothing,
     * which is worse than a deployment that refuses to boot.
     *
     * @return array{0: ?StrictAuditLedgerInterface, 1: bool}
     *
     * @throws ConfigException when durable auditing is on but unwireable
     */
    private function writeTierAuditSettings(): array
    {
        $mcp = $this->config['mcp'] ?? null;
        $writeTier = \is_array($mcp) && \is_array($mcp['write_tier'] ?? null) ? $mcp['write_tier'] : [];

        $durable = \array_key_exists('durable_audit', $writeTier)
            ? self::requireBool($writeTier['durable_audit'], 'mcp.write_tier.durable_audit')
            : true;

        if (!$durable) {
            return [null, false];
        }

        $ledger = $this->resolveOptional(StrictAuditLedgerInterface::class);
        if (!$ledger instanceof StrictAuditLedgerInterface) {
            throw new ConfigException(
                'The authenticated MCP write tier is configured for durable auditing '
                . '(mcp.write_tier.durable_audit), but no StrictAuditLedgerInterface is bound. '
                . 'Install waaseyaa/audit, or bind your own implementation, or set '
                . 'mcp.write_tier.durable_audit to false to accept best-effort auditing.',
                ['config_key' => 'mcp.write_tier.durable_audit'],
            );
        }

        return [$ledger, true];
    }

    /**
     * Human-approval gate wiring for the authenticated write tier (#2177 F1).
     *
     * Config `mcp.write_tier.approval.enabled`; DEFAULT **on** — destructive
     * tools should not silently run unapproved, so disabling the gate is the
     * explicit act, exactly like `durable_audit` above. The public tier never
     * receives the gate: its registry already excludes destructive tools, and
     * this method is only consulted by the write-tier binding.
     *
     * **Fails closed on a wiring gap.** Enabled-but-unwireable — no
     * `OperationApprovalStoreInterface` bound, typically because
     * `waaseyaa/audit` is not installed — throws at provider setup. Enabled
     * while `durable_audit` is off is a contradiction (consuming an approval
     * joins it to the strict-ledger receipt of the executing reservation) and
     * is refused rather than resolved silently in either direction.
     *
     * @return array{0: ?OperationApprovalStoreInterface, 1: bool}
     *
     * @throws ConfigException when the gate is on but unwireable
     */
    private function writeTierApprovalSettings(bool $durableAudit): array
    {
        $mcp = $this->config['mcp'] ?? null;
        $writeTier = \is_array($mcp) && \is_array($mcp['write_tier'] ?? null) ? $mcp['write_tier'] : [];

        $approvalSection = $writeTier['approval'] ?? null;
        if ($approvalSection !== null && !\is_array($approvalSection)) {
            // `approval: false` is a realistic way to write the intent; refuse
            // it with the correct key named rather than reading it as enabled.
            throw self::malformedConfig('mcp.write_tier.approval', $approvalSection, 'a map containing an "enabled" key');
        }
        $approval = \is_array($approvalSection) ? $approvalSection : [];

        $enabled = \array_key_exists('enabled', $approval)
            ? self::requireBool($approval['enabled'], 'mcp.write_tier.approval.enabled')
            : true;

        if (!$enabled) {
            return [null, false];
        }

        if (!$durableAudit) {
            throw new ConfigException(
                'The MCP write tier has durable auditing disabled (mcp.write_tier.durable_audit) '
                . 'while the human-approval gate is enabled (mcp.write_tier.approval.enabled, default true). '
                . 'The gate consumes approvals against strict-ledger receipts, so it cannot run without '
                . 'durable auditing. Re-enable durable auditing, or explicitly set '
                . 'mcp.write_tier.approval.enabled to false to run destructive tools without human approval.',
                ['config_key' => 'mcp.write_tier.approval.enabled'],
            );
        }

        try {
            $store = $this->resolve(OperationApprovalStoreInterface::class);
        } catch (\RuntimeException $e) {
            // Only the container's own "nothing is bound" sentinel means the
            // wiring gap this method reports. Anything else — a ConfigException
            // for a malformed approval TTL, a schema/DB failure from a BOUND
            // store's factory — is the store's own error and must surface as
            // such: misreporting it as "no store bound" sends the operator to
            // install a package they already have.
            if ($e->getMessage() !== sprintf('No binding registered for %s.', OperationApprovalStoreInterface::class)) {
                throw $e;
            }
            $store = null;
        }

        if (!$store instanceof OperationApprovalStoreInterface) {
            throw new ConfigException(
                'The authenticated MCP write tier is configured for the human-approval gate '
                . '(mcp.write_tier.approval.enabled, default true), but no OperationApprovalStoreInterface '
                . 'is bound. Install waaseyaa/audit, or bind your own implementation, or set '
                . 'mcp.write_tier.approval.enabled to false to run destructive tools without human approval.',
                ['config_key' => 'mcp.write_tier.approval.enabled'],
            );
        }

        return [$store, true];
    }

    /**
     * Resolve the PUBLIC-tier auth, preferring an application override and
     * falling back to the anonymous read-only default (FR-002).
     *
     * The mechanism is the one `resolveWriteTierAuth()` established:
     * `resolveOptional()` finds nothing in this provider's own bindings — the
     * package deliberately binds no default — and falls through to the
     * cross-provider kernel-services bus, so an application's
     * `McpAuthInterface` binding is what reaches `/mcp`.
     *
     * The two tiers differ only in their fallback, and deliberately so.
     * The write tier falls back to a credential that cannot succeed
     * (`BearerTokenAuth([])`, so every request 401s) because a write surface
     * with no configured identity must be unusable. The public tier falls back
     * to {@see PublicAnonymousAuth} because anonymous read is its designed
     * behavior — an operator who wants it off sets `mcp.public.enabled` to
     * false rather than relying on an auth strategy to suppress it.
     */
    private function resolvePublicAuth(): McpAuthInterface
    {
        $auth = $this->resolveOptional(McpAuthInterface::class);

        return $auth instanceof McpAuthInterface ? $auth : new PublicAnonymousAuth();
    }

    /**
     * Whether the anonymous public `/mcp` surface is served at all.
     *
     * Config `mcp.public.enabled`; DEFAULT TRUE when the key is absent, so an
     * existing deployment that sets nothing is unaffected. Set it to false and
     * neither `/mcp` nor the `/.well-known/mcp.json` discovery card is
     * registered — both 404. The card goes with the endpoint on purpose: its
     * entire job is to advertise a discovery surface, and advertising one that
     * answers 404 is worse than advertising nothing.
     *
     * `/mcp/write` is unaffected. It is not a discovery surface, it is already
     * fail-closed without an application-supplied credential, and an operator
     * who wants an authenticated write tier but no anonymous read tier is
     * exactly who this flag is for.
     *
     * **A supplied value that is not a recognised boolean throws.** This key
     * gates a public network surface, and there is no safe way to guess at a
     * typo: reading `"flase"` as enabled silently publishes the endpoint the
     * operator meant to close, and reading it as disabled silently withdraws a
     * surface someone may depend on. Absent means default; present means it
     * must parse. Raised during provider/route setup, so the deployment fails
     * at boot rather than serving a misconfigured surface.
     *
     * @throws ConfigException on a malformed value
     */
    private function publicEndpointEnabled(): bool
    {
        $mcp = $this->config['mcp'] ?? null;
        if (!\is_array($mcp) || !\array_key_exists('public', $mcp)) {
            return true;
        }

        $public = $mcp['public'];
        if (!\is_array($public)) {
            // `mcp.public: false` is a realistic way to write the intent, and
            // silently reading it as "enabled" is the exact failure this guard
            // exists to prevent — so it is refused, with the correct key named.
            throw self::malformedConfig('mcp.public', $public, 'a map containing an "enabled" key');
        }

        if (!\array_key_exists('enabled', $public)) {
            return true;
        }

        return self::requireBool($public['enabled'], 'mcp.public.enabled');
    }

    /** Recognised true spellings, lowercased. */
    private const array TRUE_VALUES = ['1', 'true', 'on', 'yes'];

    /** Recognised false spellings, lowercased. */
    private const array FALSE_VALUES = ['0', 'false', 'off', 'no'];

    /**
     * Parse a config value as a boolean, or throw.
     *
     * Deliberately NOT `filter_var(..., FILTER_VALIDATE_BOOL)`: that maps both
     * `null` and `''` to `false` and would silently withdraw the endpoint for a
     * key an operator left blank. An explicit allowlist is auditable and treats
     * every unrecognised value — including `null`, `''`, floats, arrays and
     * objects — the same way.
     *
     * @throws ConfigException on anything outside the allowlist
     */
    private static function requireBool(mixed $value, string $key): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        if (\is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        if (\is_string($value)) {
            $normalized = \strtolower(\trim($value));
            if (\in_array($normalized, self::TRUE_VALUES, true)) {
                return true;
            }
            if (\in_array($normalized, self::FALSE_VALUES, true)) {
                return false;
            }
        }

        throw self::malformedConfig(
            $key,
            $value,
            'a boolean: true/false, 1/0, "true"/"false", "on"/"off", or "yes"/"no"',
        );
    }

    /**
     * Build the refusal for a malformed config value.
     *
     * The message names the KEY and the value's TYPE, never the value itself —
     * configuration routinely holds credentials, and an exception message reaches
     * logs and error pages. `get_debug_type()` returns a type name only, so a
     * secret cannot ride out through the diagnostic.
     */
    private static function malformedConfig(string $key, mixed $value, string $expectation): ConfigException
    {
        return new ConfigException(
            \sprintf(
                'Configuration key "%s" must be %s; got a value of type %s. '
                . 'This key gates a public network surface, so an unrecognised value is refused '
                . 'rather than guessed. Remove the key to accept the default (enabled). '
                . '(The value is omitted from this message because configuration may hold secrets.)',
                $key,
                $expectation,
                \get_debug_type($value),
            ),
            ['config_key' => $key, 'value_type' => \get_debug_type($value)],
        );
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
        if ($auth instanceof WriteTierAuthInterface) {
            return $auth;
        }

        // Framework default (#2177 F3): the DURABLE token path. When the
        // kernel supplies the bearer-token store (bound by AuthServiceProvider
        // over the kernel database) and the user repository, `/mcp/write`
        // authenticates against hashed, expiring, revocable, audience-bound
        // credentials issued via the `bearer-token:*` operator commands. A
        // fresh deployment has no tokens, so the tier still fails closed with
        // 401 until an operator issues one — same observable posture as the
        // empty map, but production-usable without application auth code.
        $store = $this->resolveOptional(\Waaseyaa\Auth\Token\Bearer\BearerTokenStoreInterface::class);
        $entityTypeManager = $this->resolveOptional(EntityTypeManagerInterface::class);
        $principals = $this->resolveOptional(\Waaseyaa\Access\AccountPrincipalFactoryInterface::class);
        if ($store instanceof \Waaseyaa\Auth\Token\Bearer\BearerTokenStoreInterface
            && $entityTypeManager instanceof EntityTypeManagerInterface
            && $principals instanceof \Waaseyaa\Access\AccountPrincipalFactoryInterface
        ) {
            try {
                $accounts = $entityTypeManager->getRepository('user');
            } catch (\Throwable) {
                // No user entity type (e.g. a bare kernel): the durable path
                // cannot resolve owners, so the tier keeps the fail-closed map.
                return new BearerTokenAuth([]);
            }

            $logger = $this->resolveOptional(LoggerInterface::class);

            return new Auth\DurableBearerTokenAuth(
                store: $store,
                accounts: $accounts,
                principals: $principals,
                logger: $logger instanceof LoggerInterface ? $logger : null,
            );
        }

        // No durable store wireable: the framework ships no usable static
        // credential — every `/mcp/write` request is HTTP 401.
        return new BearerTokenAuth([]);
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
     * Per-principal rate limiting for both MCP tiers (#2136 WP3).
     *
     * Config `mcp.rate_limit.{max_requests, window_seconds}`; DEFAULT OFF
     * (`max_requests` absent or <= 0). When enabled, a shared
     * {@see \Waaseyaa\Auth\DatabaseRateLimiter} is built over the kernel
     * database; when no database resolves, limiting stays off (the endpoint
     * fails open by contract — limiter availability is not endpoint
     * availability).
     *
     * @return array{0: ?\Waaseyaa\Auth\RateLimiterInterface, 1: int, 2: int}
     */
    private function rateLimitSettings(): array
    {
        $mcp = $this->config['mcp'] ?? null;
        $settings = \is_array($mcp) && \is_array($mcp['rate_limit'] ?? null) ? $mcp['rate_limit'] : [];
        $maxRequests = (int) ($settings['max_requests'] ?? 0);
        $windowSeconds = max(1, (int) ($settings['window_seconds'] ?? 60));
        if ($maxRequests <= 0) {
            return [null, 0, $windowSeconds];
        }

        $database = $this->resolveOptional(\Waaseyaa\Database\DatabaseInterface::class);
        if (!$database instanceof \Waaseyaa\Database\DatabaseInterface) {
            return [null, 0, $windowSeconds];
        }

        return [new \Waaseyaa\Auth\DatabaseRateLimiter($database), $maxRequests, $windowSeconds];
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
