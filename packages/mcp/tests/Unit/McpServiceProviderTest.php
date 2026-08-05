<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Auth\AtomicRateLimiterInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Exception\ConfigException;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesApiCatalogEntriesInterface;
use Waaseyaa\Mcp\Auth\McpAuthInterface;
use Waaseyaa\Mcp\McpServiceProvider;
use Waaseyaa\Mcp\UnavailableRateLimiter;
use Waaseyaa\Routing\WaaseyaaRouter;

#[CoversClass(McpServiceProvider::class)]
final class McpServiceProviderTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private function routesFor(array $config): \Symfony\Component\Routing\RouteCollection
    {
        $router = new WaaseyaaRouter();
        $provider = new McpServiceProvider();
        $provider->setKernelContext('', $config, []);
        $provider->routes($router, new EntityTypeManager(new EventDispatcher()));

        return $router->getRouteCollection();
    }

    #[Test]
    public function registers_mcp_routes_through_the_package_service_provider(): void
    {
        $routes = $this->routesFor([]);

        self::assertNotNull($routes->get('mcp.endpoint'));
        self::assertNotNull($routes->get('mcp.server_card'));
        self::assertNotNull($routes->get('mcp.endpoint.write'));
    }

    /**
     * The anti-shadowing invariant. `McpAuthInterface` must NOT appear in this
     * provider's own bindings: `ServiceProvider::resolve()` consults those before
     * the cross-provider kernel-services bus, so a package default here would
     * silently beat an application's binding no matter how providers are ordered.
     * The anonymous default is applied at the point of use instead.
     */
    #[Test]
    public function does_not_bind_a_package_default_that_would_shadow_an_application_override(): void
    {
        $provider = new McpServiceProvider();
        $provider->register();

        self::assertArrayNotHasKey(
            McpAuthInterface::class,
            $provider->getBindings(),
            'McpServiceProvider must not bind McpAuthInterface locally — a local binding '
            . 'takes precedence over the kernel-services bus and shadows the application.',
        );
    }

    #[Test]
    public function public_endpoint_is_enabled_by_default_when_no_config_is_supplied(): void
    {
        $routes = $this->routesFor(['mcp' => []]);

        self::assertNotNull($routes->get('mcp.endpoint'));
        self::assertNotNull($routes->get('mcp.server_card'));
    }

    #[Test]
    public function catalog_contribution_matches_only_the_enabled_public_tier(): void
    {
        $enabled = new McpServiceProvider();
        $enabled->setKernelContext('', ['mcp' => ['public' => ['enabled' => true]]], []);
        self::assertInstanceOf(ProvidesApiCatalogEntriesInterface::class, $enabled);

        $entries = $enabled->apiCatalogEntries();
        self::assertCount(1, $entries);
        self::assertSame('/mcp', $entries[0]->endpoint->path);
        self::assertSame('/.well-known/mcp.json', $entries[0]->serviceMetadata[0]->path);
        self::assertStringNotContainsString('/write', json_encode($entries, JSON_THROW_ON_ERROR));

        $disabled = new McpServiceProvider();
        $disabled->setKernelContext('', ['mcp' => ['public' => ['enabled' => false]]], []);
        self::assertSame([], $disabled->apiCatalogEntries());
    }

    #[Test]
    public function disabling_the_public_endpoint_removes_both_it_and_its_discovery_card(): void
    {
        $routes = $this->routesFor(['mcp' => ['public' => ['enabled' => false]]]);

        self::assertNull($routes->get('mcp.endpoint'), '/mcp must not be routed when disabled.');
        self::assertNull(
            $routes->get('mcp.server_card'),
            '/.well-known/mcp.json must not advertise an endpoint that is not served.',
        );
    }

    #[Test]
    public function disabling_the_public_endpoint_leaves_the_authenticated_write_tier_routed(): void
    {
        $routes = $this->routesFor(['mcp' => ['public' => ['enabled' => false]]]);

        self::assertNotNull(
            $routes->get('mcp.endpoint.write'),
            'An authenticated write tier without an anonymous read tier is a supported shape.',
        );
    }

    #[Test]
    public function oauth_resource_config_registers_discovery_and_the_matching_write_challenge(): void
    {
        $config = ['mcp' => [
            'public' => ['enabled' => false],
            'write_tier' => ['oauth_resource' => [
                'enabled' => true,
                'resource' => 'https://cms.example/mcp/write',
                'authorization_servers' => ['https://identity.example'],
                'scopes_supported' => ['content.read', 'content.write'],
            ]],
        ]];
        $metadataRoute = $this->routesFor($config)->get('mcp.oauth_protected_resource');
        self::assertNotNull($metadataRoute);
        self::assertSame('/.well-known/oauth-protected-resource/mcp/write', $metadataRoute->getPath());

        $endpoint = $this->resolveWriteEndpoint($config, $this->workingLedger(), $this->approvalStore());
        $inner = new \ReflectionProperty(\Waaseyaa\Mcp\AuthenticatedMcpEndpoint::class, 'inner')->getValue($endpoint);
        self::assertSame(
            'Bearer resource_metadata="https://cms.example/.well-known/oauth-protected-resource/mcp/write", scope="content.read content.write"',
            new \ReflectionProperty(\Waaseyaa\Mcp\McpEndpoint::class, 'unauthorizedChallenge')->getValue($inner),
        );
    }

    #[Test]
    public function malformed_oauth_resource_config_fails_boot_without_echoing_values(): void
    {
        try {
            $this->routesFor(['mcp' => ['write_tier' => ['oauth_resource' => [
                'enabled' => true,
                'resource' => 'http://not-loopback.example/mcp/write',
                'authorization_servers' => ['https://identity.example'],
            ]]]]);
            self::fail('Insecure OAuth resource metadata must fail boot.');
        } catch (ConfigException $e) {
            self::assertStringContainsString('mcp.write_tier.oauth_resource', $e->getMessage());
            self::assertStringNotContainsString('not-loopback.example', $e->getMessage());
        }
    }

    /**
     * Config arrives from YAML/env, where a boolean is routinely the string
     * `"false"` or `"0"`. Case and surrounding whitespace are tolerated.
     */
    #[Test]
    public function public_enabled_flag_accepts_the_string_forms_config_actually_carries(): void
    {
        foreach ([false, 'false', 'off', 'no', 0, '0', 'FALSE', ' off '] as $disabled) {
            self::assertNull(
                $this->routesFor(['mcp' => ['public' => ['enabled' => $disabled]]])->get('mcp.endpoint'),
                sprintf('%s must disable the public endpoint.', var_export($disabled, true)),
            );
        }

        foreach ([true, 'true', 'on', 'yes', 1, '1', 'TRUE', ' on '] as $enabled) {
            self::assertNotNull(
                $this->routesFor(['mcp' => ['public' => ['enabled' => $enabled]]])->get('mcp.endpoint'),
                sprintf('%s must keep the public endpoint.', var_export($enabled, true)),
            );
        }
    }

    /**
     * A typo in a security control must never be guessed at. Reading `"flase"`
     * as enabled silently publishes the endpoint the operator meant to close;
     * reading it as disabled silently withdraws a surface someone depends on.
     * Both are wrong, so the deployment refuses to boot instead.
     *
     * @return list<array{0: string, 1: mixed}>
     */
    public static function malformedValues(): array
    {
        return [
            'typo' => ['typo', 'flase'],
            'arbitrary string' => ['arbitrary string', 'perhaps'],
            'empty string' => ['empty string', ''],
            'whitespace only' => ['whitespace only', '   '],
            'null' => ['null', null],
            'list' => ['list', ['yes']],
            'map' => ['map', ['enabled' => true]],
            'object' => ['object', new \stdClass()],
            'float' => ['float', 1.5],
            'out-of-range int' => ['out-of-range int', 2],
            'negative int' => ['negative int', -1],
        ];
    }

    #[Test]
    #[DataProvider('malformedValues')]
    public function a_malformed_enabled_flag_throws_instead_of_guessing(string $label, mixed $value): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/mcp\.public\.enabled/');

        $this->routesFor(['mcp' => ['public' => ['enabled' => $value]]]);
    }

    /**
     * `null` deserves its own statement of intent: PHP's own
     * `filter_var(null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)` returns
     * `false`, so the obvious implementation would have silently WITHDRAWN the
     * endpoint for a key an operator left blank. It throws instead.
     */
    #[Test]
    public function an_explicitly_null_flag_throws_rather_than_silently_disabling(): void
    {
        self::assertFalse(filter_var(null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE));

        $this->expectException(ConfigException::class);
        $this->routesFor(['mcp' => ['public' => ['enabled' => null]]]);
    }

    /**
     * `mcp.public: false` is a realistic way to write the intent. Silently
     * reading it as "enabled" is exactly the failure this guard prevents, so it
     * is refused with the correct key named.
     */
    #[Test]
    public function a_non_map_public_section_throws_and_names_the_right_key(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/mcp\.public/');

        $this->routesFor(['mcp' => ['public' => false]]);
    }

    /**
     * The exception reaches logs and error pages, and configuration routinely
     * holds credentials. It must name the key and the value's TYPE, never the
     * value.
     */
    #[Test]
    public function the_configuration_error_never_echoes_the_supplied_value(): void
    {
        // Stands in for a credential a misconfiguration might place here. Kept
        // deliberately un-credential-shaped so repository and platform secret
        // scanners do not flag this fixture.
        $secret = 'NOT-A-REAL-CREDENTIAL-placeholder-value';

        try {
            $this->routesFor(['mcp' => ['public' => ['enabled' => $secret]]]);
            self::fail('Expected a ConfigException.');
        } catch (ConfigException $e) {
            self::assertStringNotContainsString($secret, $e->getMessage());
            self::assertStringNotContainsString($secret, json_encode($e->context, JSON_THROW_ON_ERROR));
            self::assertStringContainsString('mcp.public.enabled', $e->getMessage());
            self::assertStringContainsString('string', $e->getMessage());
        }
    }

    /** @return array{0: ?AtomicRateLimiterInterface, 1: int, 2: int} */
    private function rateLimitSettingsFor(array $config): array
    {
        $provider = new McpServiceProvider();
        $provider->setKernelContext('', $config, []);
        $method = new \ReflectionMethod(McpServiceProvider::class, 'rateLimitSettings');

        return $method->invoke($provider);
    }

    #[Test]
    public function mcp_rate_limiting_is_enabled_by_default_and_fails_closed_without_storage(): void
    {
        [$limiter, $max, $window] = $this->rateLimitSettingsFor([]);

        self::assertInstanceOf(UnavailableRateLimiter::class, $limiter);
        self::assertSame(120, $max);
        self::assertSame(60, $window);
    }

    #[Test]
    public function an_explicit_zero_disables_mcp_rate_limiting(): void
    {
        [$limiter, $max, $window] = $this->rateLimitSettingsFor([
            'mcp' => ['rate_limit' => ['max_requests' => 0, 'window_seconds' => 30]],
        ]);

        self::assertNull($limiter);
        self::assertSame(0, $max);
        self::assertSame(30, $window);
    }

    #[Test]
    public function malformed_rate_limit_values_are_refused_without_coercion(): void
    {
        foreach ([
            ['max_requests' => '120'],
            ['max_requests' => -1],
            ['max_requests' => 10_001],
            ['window_seconds' => '60'],
            ['window_seconds' => 0],
            ['window_seconds' => 3_601],
        ] as $settings) {
            try {
                $this->rateLimitSettingsFor(['mcp' => ['rate_limit' => $settings]]);
                self::fail('Expected malformed MCP rate-limit config to be refused.');
            } catch (ConfigException $e) {
                self::assertStringContainsString('mcp.rate_limit.', $e->getMessage());
            }
        }
    }

    // ------------------------------------------------ durable write audit (#2177 F4)

    /** Resolve the write endpoint through the real provider with a given config. */
    private function resolveWriteEndpoint(array $config, ?object $ledger = null, ?object $approvalStore = null): object
    {
        return $this->resolveThroughProviders($config, $ledger, $approvalStore, \Waaseyaa\Mcp\AuthenticatedMcpEndpoint::class);
    }

    private function writeRegistryFrom(object $endpoint): \Waaseyaa\Mcp\CapabilityScopedToolRegistry
    {
        $inner = new \ReflectionProperty(\Waaseyaa\Mcp\AuthenticatedMcpEndpoint::class, 'inner')->getValue($endpoint);
        $registry = new \ReflectionProperty(\Waaseyaa\Mcp\McpEndpoint::class, 'agentRegistry')->getValue($inner);
        self::assertInstanceOf(\Waaseyaa\Mcp\CapabilityScopedToolRegistry::class, $registry);

        return $registry;
    }

    /** Wire the real provider pair and resolve one endpoint class from it. */
    private function resolveThroughProviders(
        array $config,
        ?object $ledger,
        ?object $approvalStore,
        string $endpointClass,
    ): object {
        $mcp = new McpServiceProvider();
        $app = new class ($ledger, $approvalStore) extends \Waaseyaa\Foundation\ServiceProvider\ServiceProvider {
            public function __construct(
                private readonly ?object $ledger,
                private readonly ?object $approvalStore,
            ) {}

            public function register(): void
            {
                $this->singleton(
                    \Waaseyaa\AI\Tools\ToolRegistryInterface::class,
                    fn(): \Waaseyaa\AI\Tools\ToolRegistryInterface => new \Waaseyaa\AI\Tools\Catalogue\AttributeToolRegistry(
                        new \Waaseyaa\Foundation\Discovery\PackageManifest(),
                        new class implements \Psr\Container\ContainerInterface {
                            public function get(string $id): mixed
                            {
                                throw new \RuntimeException('not used');
                            }

                            public function has(string $id): bool
                            {
                                return false;
                            }
                        },
                    ),
                );
                if ($this->ledger !== null) {
                    $ledger = $this->ledger;
                    $this->singleton(
                        \Waaseyaa\Foundation\Audit\StrictAuditLedgerInterface::class,
                        static fn(): object => $ledger,
                    );
                }
                if ($this->approvalStore !== null) {
                    $approvalStore = $this->approvalStore;
                    // A Closure is a factory (it may throw to simulate a bound
                    // store whose construction fails); anything else is the
                    // instance itself.
                    $this->singleton(
                        \Waaseyaa\Foundation\Audit\Approval\OperationApprovalStoreInterface::class,
                        $approvalStore instanceof \Closure
                            ? $approvalStore
                            : static fn(): object => $approvalStore,
                    );
                }
            }
        };

        $providers = [$mcp, $app];
        $bus = new class (static fn(): array => $providers) implements \Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface {
            public function __construct(private \Closure $providers) {}

            public function get(string $abstract): ?object
            {
                foreach (($this->providers)() as $provider) {
                    if (isset($provider->getBindings()[$abstract])) {
                        return $provider->resolve($abstract);
                    }
                }

                return null;
            }
        };
        foreach ($providers as $provider) {
            $provider->setKernelContext('', $config, []);
            $provider->setKernelServices($bus);
        }
        foreach ($providers as $provider) {
            $provider->register();
        }

        return $mcp->resolve($endpointClass);
    }

    /** A minimal REAL approval store stub for wiring tests. */
    private function approvalStore(): object
    {
        return new class implements \Waaseyaa\Foundation\Audit\Approval\OperationApprovalStoreInterface {
            public function open(
                \Waaseyaa\Foundation\Audit\Approval\ApprovalTuple $tuple,
                string $correlationId,
                array $safeArguments,
            ): \Waaseyaa\Foundation\Audit\Approval\ApprovalRequest {
                throw new \LogicException('not exercised in wiring tests');
            }

            public function find(string $requestId): ?\Waaseyaa\Foundation\Audit\Approval\ApprovalRequest
            {
                return null;
            }

            public function decide(
                string $requestId,
                bool $approved,
                int $operatorUid,
                ?string $reason = null,
            ): \Waaseyaa\Foundation\Audit\Approval\ApprovalRequest {
                throw new \LogicException('not exercised in wiring tests');
            }

            public function consume(string $requestId, string $receiptId, string $retryCorrelationId): bool
            {
                return false;
            }

            public function listPending(
                int $limit = self::PENDING_PAGE_DEFAULT_LIMIT,
                ?string $cursor = null,
            ): \Waaseyaa\Foundation\Audit\Approval\ApprovalRequestPage {
                return new \Waaseyaa\Foundation\Audit\Approval\ApprovalRequestPage([]);
            }
        };
    }

    /** A minimal REAL ledger stub for wiring tests. */
    private function workingLedger(): object
    {
        return new class implements \Waaseyaa\Foundation\Audit\StrictAuditLedgerInterface {
            public function reserve(
                \Waaseyaa\Foundation\Audit\StrictAuditReservation $reservation,
            ): \Waaseyaa\Foundation\Audit\StrictAuditReceipt {
                return new \Waaseyaa\Foundation\Audit\StrictAuditReceipt('r1', $reservation->correlationId);
            }

            public function finalize(
                \Waaseyaa\Foundation\Audit\StrictAuditReceipt $receipt,
                \Waaseyaa\Foundation\Audit\AuditStage $stage,
                array $metadata = [],
            ): void {}

            public function record(
                \Waaseyaa\Foundation\Audit\StrictAuditReservation $reservation,
                \Waaseyaa\Foundation\Audit\AuditStage $stage,
            ): void {}
        };
    }

    /**
     * Durable write auditing defaults ON. A write surface must not be quietly
     * downgraded to best-effort auditing, so an unwireable ledger is a boot
     * failure rather than a silent substitution of NullStrictAuditLedger.
     */
    #[Test]
    public function the_write_tier_fails_closed_when_durable_audit_cannot_be_wired(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/durable_audit/');

        $this->resolveWriteEndpoint([]);
    }

    #[Test]
    public function the_write_tier_wires_cleanly_when_a_ledger_and_store_are_bound(): void
    {
        // A minimal REAL ledger stub: NullStrictAuditLedger is deliberately
        // unusable here — the endpoint's own contract refuses the durable mode
        // when wired to the record-nothing ledger.
        self::assertInstanceOf(
            \Waaseyaa\Mcp\AuthenticatedMcpEndpoint::class,
            $this->resolveWriteEndpoint([], $this->workingLedger(), $this->approvalStore()),
        );
    }

    #[Test]
    public function generic_entity_mutations_are_structurally_blocked_on_the_write_tier_by_default(): void
    {
        $endpoint = $this->resolveWriteEndpoint([], $this->workingLedger(), $this->approvalStore());
        $registry = $this->writeRegistryFrom($endpoint);

        self::assertSame(
            ['entity.create', 'entity.update', 'entity.delete', 'entity.rollback', 'entity.set_current_revision'],
            new \ReflectionProperty($registry, 'blockedToolNames')->getValue($registry),
        );
    }

    #[Test]
    public function generic_entity_mutations_require_an_explicit_strict_boolean_opt_in(): void
    {
        $endpoint = $this->resolveWriteEndpoint(
            ['mcp' => ['write_tier' => ['allow_generic_entity_mutations' => true]]],
            $this->workingLedger(),
            $this->approvalStore(),
        );
        $registry = $this->writeRegistryFrom($endpoint);
        self::assertSame([], new \ReflectionProperty($registry, 'blockedToolNames')->getValue($registry));

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/mcp\.write_tier\.allow_generic_entity_mutations/');
        $this->resolveWriteEndpoint(
            ['mcp' => ['write_tier' => ['allow_generic_entity_mutations' => 'perhaps']]],
            $this->workingLedger(),
            $this->approvalStore(),
        );
    }

    #[Test]
    public function configured_transport_origins_are_passed_to_the_write_endpoint(): void
    {
        $endpoint = $this->resolveWriteEndpoint(
            ['mcp' => ['transport' => ['allowed_origins' => ['https://editor.example']]]],
            $this->workingLedger(),
            $this->approvalStore(),
        );
        $inner = new \ReflectionProperty(\Waaseyaa\Mcp\AuthenticatedMcpEndpoint::class, 'inner')->getValue($endpoint);

        self::assertSame(
            ['https://editor.example'],
            new \ReflectionProperty(\Waaseyaa\Mcp\McpEndpoint::class, 'allowedOrigins')->getValue($inner),
        );
    }

    #[Test]
    public function malformed_transport_origin_configuration_fails_closed(): void
    {
        foreach (['https://editor.example', [42], ['https://editor.example/path']] as $origins) {
            try {
                $this->resolveWriteEndpoint(
                    ['mcp' => ['transport' => ['allowed_origins' => $origins]]],
                    $this->workingLedger(),
                    $this->approvalStore(),
                );
                self::fail('Malformed allowed origins must fail endpoint wiring.');
            } catch (ConfigException $e) {
                self::assertStringContainsString('mcp.transport.allowed_origins', $e->getMessage());
            }
        }
    }

    #[Test]
    public function transport_request_size_is_bounded_and_strictly_configured(): void
    {
        $endpoint = $this->resolveWriteEndpoint(
            ['mcp' => ['transport' => ['max_request_bytes' => 2_000_000]]],
            $this->workingLedger(),
            $this->approvalStore(),
        );
        $inner = new \ReflectionProperty(\Waaseyaa\Mcp\AuthenticatedMcpEndpoint::class, 'inner')->getValue($endpoint);
        self::assertSame(
            2_000_000,
            new \ReflectionProperty(\Waaseyaa\Mcp\McpEndpoint::class, 'maxRequestBytes')->getValue($inner),
        );

        foreach ([0, 1023, 104_857_601, '2000000'] as $invalid) {
            try {
                $this->resolveWriteEndpoint(
                    ['mcp' => ['transport' => ['max_request_bytes' => $invalid]]],
                    $this->workingLedger(),
                    $this->approvalStore(),
                );
                self::fail('Malformed transport size must fail endpoint wiring.');
            } catch (ConfigException $e) {
                self::assertStringContainsString('mcp.transport.max_request_bytes', $e->getMessage());
            }
        }
    }

    /**
     * The endpoint's own fail-closed contract also protects the provider path:
     * an application that binds the record-nothing ledger while durable audit
     * is on gets a boot failure, not a write tier that silently records nothing.
     */
    #[Test]
    public function binding_the_record_nothing_ledger_fails_closed_at_wiring(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/durable/i');

        $this->resolveWriteEndpoint([], new \Waaseyaa\Foundation\Audit\NullStrictAuditLedger(), $this->approvalStore());
    }

    /**
     * Opting out is explicit and supported — it must not throw. The approval
     * gate rides on the durable ledger (its consume step joins the strict-audit
     * receipt), so turning durable audit off requires stating the approval
     * downgrade explicitly too.
     */
    #[Test]
    public function durable_audit_can_be_explicitly_disabled(): void
    {
        self::assertInstanceOf(
            \Waaseyaa\Mcp\AuthenticatedMcpEndpoint::class,
            $this->resolveWriteEndpoint(['mcp' => ['write_tier' => [
                'durable_audit' => false,
                'approval' => ['enabled' => false],
            ]]]),
        );
    }

    // ------------------------------------------------ write-tier approval gate (#2177 F1)

    /**
     * The gate defaults ON: a write tier serving destructive tools without a
     * human-approval store is a silent downgrade, so an unwireable store is a
     * boot failure — exactly the durable-audit precedent.
     */
    #[Test]
    public function the_approval_gate_fails_closed_when_no_store_is_bound(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/mcp\.write_tier\.approval/');

        $this->resolveWriteEndpoint([], $this->workingLedger());
    }

    /**
     * A store that IS bound but fails to construct (schema migration, DB
     * connectivity) is the store's own failure and must surface as such —
     * NOT be swallowed and misreported as "no store bound", which would send
     * the operator to install a package they already have.
     */
    #[Test]
    public function a_bound_store_whose_construction_fails_is_not_misreported_as_unbound(): void
    {
        try {
            $this->resolveWriteEndpoint(
                [],
                $this->workingLedger(),
                static function (): object {
                    throw new \RuntimeException('approval store schema migration failed');
                },
            );
            self::fail('The bound store\'s construction failure must propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('approval store schema migration failed', $e->getMessage());
            self::assertNotInstanceOf(ConfigException::class, $e);
        }
    }

    /** Opting the gate out is explicit and supported. */
    #[Test]
    public function the_approval_gate_can_be_explicitly_disabled(): void
    {
        self::assertInstanceOf(
            \Waaseyaa\Mcp\AuthenticatedMcpEndpoint::class,
            $this->resolveWriteEndpoint(
                ['mcp' => ['write_tier' => ['approval' => ['enabled' => false]]]],
                $this->workingLedger(),
            ),
        );
    }

    /** The flag reuses the same strict boolean contract as its siblings. */
    #[Test]
    public function a_malformed_approval_enabled_flag_throws(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/mcp\.write_tier\.approval\.enabled/');

        $this->resolveWriteEndpoint(
            ['mcp' => ['write_tier' => ['approval' => ['enabled' => 'perhaps']]]],
            $this->workingLedger(),
            $this->approvalStore(),
        );
    }

    /**
     * The gate cannot run without durable audit — consume() joins the approval
     * to the strict-ledger receipt of the executing reservation. Disabling
     * durable audit while leaving the gate on is a contradiction, refused
     * rather than silently resolved in either direction.
     */
    #[Test]
    public function disabling_durable_audit_while_the_gate_is_on_is_refused(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/approval/');

        $this->resolveWriteEndpoint(
            ['mcp' => ['write_tier' => ['durable_audit' => false]]],
            null,
            $this->approvalStore(),
        );
    }

    #[Test]
    public function the_write_tier_gate_is_wired_on_by_default(): void
    {
        $store = $this->approvalStore();
        $endpoint = $this->resolveWriteEndpoint([], $this->workingLedger(), $store);

        $inner = new \ReflectionProperty(\Waaseyaa\Mcp\AuthenticatedMcpEndpoint::class, 'inner')->getValue($endpoint);
        self::assertTrue(new \ReflectionProperty(\Waaseyaa\Mcp\McpEndpoint::class, 'approvalGate')->getValue($inner));
        self::assertSame($store, new \ReflectionProperty(\Waaseyaa\Mcp\McpEndpoint::class, 'approvalStore')->getValue($inner));
    }

    /**
     * The public read-only tier never gets the gate — not even when a store is
     * bound and the write tier's gate is on. Its registry already excludes
     * destructive tools; wiring a gate there would imply approvals can make the
     * public tier destructive.
     */
    #[Test]
    public function the_public_tier_never_gets_the_approval_gate(): void
    {
        $endpoint = $this->resolveThroughProviders(
            [],
            $this->workingLedger(),
            $this->approvalStore(),
            \Waaseyaa\Mcp\McpEndpoint::class,
        );

        self::assertFalse(new \ReflectionProperty(\Waaseyaa\Mcp\McpEndpoint::class, 'approvalGate')->getValue($endpoint));
        self::assertNull(new \ReflectionProperty(\Waaseyaa\Mcp\McpEndpoint::class, 'approvalStore')->getValue($endpoint));
    }

    /** The flag reuses the same strict boolean contract as mcp.public.enabled. */
    #[Test]
    public function a_malformed_durable_audit_flag_throws(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/mcp\.write_tier\.durable_audit/');

        $this->resolveWriteEndpoint(['mcp' => ['write_tier' => ['durable_audit' => 'perhaps']]]);
    }
}
