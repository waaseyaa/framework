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
use Waaseyaa\Foundation\Discovery\AiCatalog\AiCatalogEntry;
use Waaseyaa\Foundation\Discovery\ApiCatalog\ApiCatalogEntry;
use Waaseyaa\Foundation\Discovery\ApiCatalog\ApiCatalogTarget;
use Waaseyaa\Foundation\Exception\ConfigException;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesAiCatalogEntriesInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesApiCatalogEntriesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Mcp\Admin\RecentInvocationsQueryInterface;
use Waaseyaa\Mcp\Admin\ServerConfigReadModel;
use Waaseyaa\Mcp\Admin\ToolRegistryReadModel;
use Waaseyaa\Mcp\Auth\BearerTokenAuth;
use Waaseyaa\Mcp\Auth\McpAuthInterface;
use Waaseyaa\Mcp\Auth\PublicAnonymousAuth;
use Waaseyaa\Mcp\Auth\WriteTierAuthInterface;
use Waaseyaa\Mcp\Registry\McpRegistryManifest;
use Waaseyaa\Mcp\Registry\McpRegistryManifestConfig;
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
final class McpServiceProvider extends ServiceProvider implements ProvidesApiCatalogEntriesInterface, ProvidesAiCatalogEntriesInterface
{
    public function apiCatalogEntries(): array
    {
        if (!$this->publicEndpointEnabled()) {
            return [];
        }

        return [new ApiCatalogEntry(
            endpoint: new ApiCatalogTarget('/mcp', 'application/json', 'Model Context Protocol'),
            serviceMetadata: [new ApiCatalogTarget('/.well-known/mcp.json', 'application/json', 'MCP server card')],
        )];
    }

    public function aiCatalogEntries(): array
    {
        if (!$this->publicEndpointEnabled()) {
            return [];
        }

        return [new AiCatalogEntry(
            key: 'mcp:public',
            displayName: 'Waaseyaa public MCP',
            // The Waaseyaa compatibility card is intentionally not mislabeled
            // as the separate draft MCP Server Card media type.
            type: 'application/json',
            path: '/.well-known/mcp.json',
            description: 'Public read-only Model Context Protocol discovery.',
            capabilities: ['ContentDiscovery', 'ReadOnlyTools'],
        )];
    }

    /**
     * Generic entity mutations are not bundle-scoped and therefore are not a
     * safe default for a remotely callable editorial surface. Embedded agents
     * keep the complete catalogue; only `/mcp/write` withholds these names.
     */
    private const array GENERIC_ENTITY_MUTATION_TOOLS = [
        'entity.create',
        'entity.update',
        'entity.delete',
        'entity.rollback',
        'entity.set_current_revision',
    ];

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

        $implementationInfo = new McpImplementationInfoResolver(
            $this->installedMcpPackageVersion(),
        )->resolve($this->projectRoot, $this->config);
        $serverCardConfig = $this->serverCardConfig();

        // One implementation identity feeds initialize, server/discover, the
        // compatibility card, and the official Registry manifest projection.
        $this->singleton(
            McpImplementationInfo::class,
            static fn(): McpImplementationInfo => $implementationInfo,
        );

        // Configurable compatibility card. Official Registry server.json is a
        // separate artifact and is never embedded in this response.
        $this->singleton(
            McpServerCard::class,
            fn(): McpServerCard => new McpServerCard(
                $serverCardConfig,
                $this->resolve(McpImplementationInfo::class),
            ),
        );
        $this->singleton(
            McpRegistryManifest::class,
            fn(): McpRegistryManifest => new McpRegistryManifest(
                $this->registryManifestConfig(),
                $this->resolve(McpImplementationInfo::class),
            ),
        );

        $oauthResource = $this->writeTierOAuthResourceConfig();
        if ($oauthResource !== null) {
            $this->singleton(
                Auth\OAuthProtectedResourceMetadataConfig::class,
                static fn(): Auth\OAuthProtectedResourceMetadataConfig => $oauthResource,
            );
            $this->singleton(
                Auth\OAuthProtectedResourceMetadata::class,
                static fn(): Auth\OAuthProtectedResourceMetadata => new Auth\OAuthProtectedResourceMetadata($oauthResource),
            );
        }

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
                    $this->publicReadCapabilities(),
                );

                [$limiter, $maxRequests, $windowSeconds] = $this->rateLimitSettings();
                $logger = $this->resolveOptional(LoggerInterface::class);
                $oauthMetadata = $this->resolveOptional(Auth\OAuthProtectedResourceMetadata::class);

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
                    allowedOrigins: $this->transportAllowedOrigins(),
                    maxRequestBytes: $this->transportMaxRequestBytes(),
                    oauthProtectedResourceMetadata: $oauthMetadata instanceof Auth\OAuthProtectedResourceMetadata
                        ? $oauthMetadata
                        : null,
                    implementationInfo: $this->resolve(McpImplementationInfo::class),
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
                    $this->genericEntityMutationToolBlocklist(),
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
                    allowedOrigins: $this->transportAllowedOrigins(),
                    unauthorizedChallenge: $this->writeTierOAuthResourceConfig()?->challenge(),
                    maxRequestBytes: $this->transportMaxRequestBytes(),
                    implementationInfo: $this->resolve(McpImplementationInfo::class),
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
        new McpRouteProvider(
            $this->publicEndpointEnabled(),
            $this->writeTierOAuthResourceConfig(),
        )->registerRoutes($router);
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

        return $auth instanceof McpAuthInterface ? $auth : new PublicAnonymousAuth($this->publicReadCapabilities());
    }

    /**
     * Capabilities visible on the anonymous tier. Rich content search is an
     * explicit application decision because installing an optional package must
     * not silently widen a public network surface.
     *
     * @return list<string>
     */
    private function publicReadCapabilities(): array
    {
        $capabilities = PublicAnonymousAuth::DEFAULT_READ_CAPABILITIES;
        $mcp = $this->config['mcp'] ?? null;
        $public = \is_array($mcp) && \is_array($mcp['public'] ?? null) ? $mcp['public'] : [];
        if (!\array_key_exists('content_search_enabled', $public)) {
            return $capabilities;
        }
        if (self::requireBool($public['content_search_enabled'], 'mcp.public.content_search_enabled')) {
            $capabilities[] = 'tool.content.search';
        }

        return $capabilities;
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
                . 'rather than guessed. Remove the key to accept its documented default. '
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
     * Generic entity mutations require an explicit dangerous opt-in. The
     * framework-supported remote editing path is an app-registered
     * ContentToolSet: bundle-scoped, draft-first, idempotent and revision-aware.
     *
     * @return list<string>
     */
    private function genericEntityMutationToolBlocklist(): array
    {
        $mcp = $this->config['mcp'] ?? null;
        $writeTier = \is_array($mcp) && \is_array($mcp['write_tier'] ?? null) ? $mcp['write_tier'] : [];
        $configured = $writeTier['allow_generic_entity_mutations'] ?? false;
        $allowed = self::requireBool(
            $configured,
            'mcp.write_tier.allow_generic_entity_mutations',
        );

        return $allowed ? [] : self::GENERIC_ENTITY_MUTATION_TOOLS;
    }

    /** @return list<string> */
    private function transportAllowedOrigins(): array
    {
        $mcp = $this->config['mcp'] ?? null;
        $transport = \is_array($mcp) && \is_array($mcp['transport'] ?? null) ? $mcp['transport'] : [];
        $origins = $transport['allowed_origins'] ?? [];
        if (!\is_array($origins) || !\array_is_list($origins)) {
            throw self::malformedConfig('mcp.transport.allowed_origins', $origins, 'a list of HTTP(S) origins');
        }

        foreach ($origins as $origin) {
            if (!\is_string($origin) || $origin === '') {
                throw self::malformedConfig('mcp.transport.allowed_origins', $origins, 'a list of HTTP(S) origins');
            }
        }

        try {
            new StreamableHttpTransportGuard($origins);
        } catch (\InvalidArgumentException) {
            throw self::malformedConfig(
                'mcp.transport.allowed_origins',
                $origins,
                'a list of absolute HTTP(S) origins without paths or credentials',
            );
        }

        /** @var list<string> $origins */
        return $origins;
    }

    private function transportMaxRequestBytes(): int
    {
        $mcp = $this->config['mcp'] ?? null;
        $transport = \is_array($mcp) && \is_array($mcp['transport'] ?? null) ? $mcp['transport'] : [];
        $max = $transport['max_request_bytes'] ?? StreamableHttpTransportGuard::DEFAULT_MAX_REQUEST_BYTES;
        if (!\is_int($max) || $max < 1_024 || $max > 104_857_600) {
            throw self::malformedConfig(
                'mcp.transport.max_request_bytes',
                $max,
                'an integer between 1024 and 104857600',
            );
        }

        return $max;
    }

    /**
     * Per-principal rate limiting for both MCP tiers (#2136 WP3).
     *
     * Config `mcp.rate_limit.{max_requests, window_seconds}`; DEFAULT ON at
     * 120 authenticated requests per 60 seconds. An explicit integer zero
     * disables it. When enabled, a shared atomic
     * {@see \Waaseyaa\Auth\DatabaseRateLimiter} is built over the kernel
     * database; when no database resolves, provider wiring fails closed.
     *
     * @return array{0: ?\Waaseyaa\Auth\AtomicRateLimiterInterface, 1: int, 2: int}
     */
    private function rateLimitSettings(): array
    {
        $mcp = $this->config['mcp'] ?? null;
        $settings = \is_array($mcp) && \is_array($mcp['rate_limit'] ?? null) ? $mcp['rate_limit'] : [];
        $maxRequests = $settings['max_requests'] ?? 120;
        $windowSeconds = $settings['window_seconds'] ?? 60;
        if (!\is_int($maxRequests) || $maxRequests < 0 || $maxRequests > 10_000) {
            throw self::malformedConfig(
                'mcp.rate_limit.max_requests',
                $maxRequests,
                'an integer between 0 and 10000',
            );
        }
        if (!\is_int($windowSeconds) || $windowSeconds < 1 || $windowSeconds > 3_600) {
            throw self::malformedConfig(
                'mcp.rate_limit.window_seconds',
                $windowSeconds,
                'an integer between 1 and 3600',
            );
        }
        if ($maxRequests === 0) {
            return [null, 0, $windowSeconds];
        }

        $database = $this->resolveOptional(\Waaseyaa\Database\DatabaseInterface::class);
        if (!$database instanceof \Waaseyaa\Database\DatabaseInterface) {
            return [new UnavailableRateLimiter(), $maxRequests, $windowSeconds];
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

    /** Official Registry metadata is deployment-owned and lazy-resolved. */
    private function registryManifestConfig(): McpRegistryManifestConfig
    {
        $mcp = $this->config['mcp'] ?? null;
        if (\is_array($mcp) && \array_key_exists('registry', $mcp) && !\is_array($mcp['registry'])) {
            throw new ConfigException('mcp.registry must be a configuration map.');
        }
        $registry = \is_array($mcp) && \is_array($mcp['registry'] ?? null) ? $mcp['registry'] : [];

        return McpRegistryManifestConfig::fromArray($registry);
    }

    private function installedMcpPackageVersion(): ?string
    {
        if (!\class_exists(\Composer\InstalledVersions::class)
            || !\Composer\InstalledVersions::isInstalled('waaseyaa/mcp')
        ) {
            return null;
        }

        return \Composer\InstalledVersions::getPrettyVersion('waaseyaa/mcp');
    }

    private function writeTierOAuthResourceConfig(): ?Auth\OAuthProtectedResourceMetadataConfig
    {
        $mcp = $this->config['mcp'] ?? null;
        $writeTier = \is_array($mcp) && \is_array($mcp['write_tier'] ?? null) ? $mcp['write_tier'] : [];
        $oauth = $writeTier['oauth_resource'] ?? null;
        if ($oauth === null) {
            return null;
        }
        if (!\is_array($oauth)) {
            throw self::malformedConfig('mcp.write_tier.oauth_resource', $oauth, 'a configuration map');
        }
        $enabled = self::requireBool($oauth['enabled'] ?? false, 'mcp.write_tier.oauth_resource.enabled');
        if (!$enabled) {
            return null;
        }

        $resource = $oauth['resource'] ?? null;
        $servers = $oauth['authorization_servers'] ?? null;
        $scopes = $oauth['scopes_supported'] ?? [];
        $documentation = $oauth['resource_documentation'] ?? null;
        if (!\is_string($resource)
            || !\is_array($servers)
            || !\array_is_list($servers)
            || !\is_array($scopes)
            || !\array_is_list($scopes)
            || ($documentation !== null && !\is_string($documentation))
        ) {
            throw self::malformedConfig(
                'mcp.write_tier.oauth_resource',
                $oauth,
                'resource URI, authorization_servers list, optional scopes_supported list, and optional resource_documentation URI',
            );
        }
        foreach ([...$servers, ...$scopes] as $value) {
            if (!\is_string($value)) {
                throw self::malformedConfig('mcp.write_tier.oauth_resource', $oauth, 'string URI and scope lists');
            }
        }

        try {
            /** @var list<string> $servers */
            /** @var list<string> $scopes */
            return new Auth\OAuthProtectedResourceMetadataConfig(
                $resource,
                $servers,
                $scopes,
                $documentation,
            );
        } catch (\InvalidArgumentException) {
            throw self::malformedConfig(
                'mcp.write_tier.oauth_resource',
                $oauth,
                'secure absolute resource and authorization-server URIs with valid unique OAuth scopes',
            );
        }
    }
}
