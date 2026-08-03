<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Exception\ConfigException;
use Waaseyaa\Mcp\Auth\McpAuthInterface;
use Waaseyaa\Mcp\McpServiceProvider;
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

    // ------------------------------------------------ durable write audit (#2177 F4)

    /** Resolve the write endpoint through the real provider with a given config. */
    private function resolveWriteEndpoint(array $config, ?object $ledger = null): object
    {
        $mcp = new McpServiceProvider();
        $app = new class ($ledger) extends \Waaseyaa\Foundation\ServiceProvider\ServiceProvider {
            public function __construct(private readonly ?object $ledger) {}

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

        return $mcp->resolve(\Waaseyaa\Mcp\AuthenticatedMcpEndpoint::class);
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
    public function the_write_tier_wires_cleanly_when_a_ledger_is_bound(): void
    {
        // A minimal REAL ledger stub: NullStrictAuditLedger is deliberately
        // unusable here — the endpoint's own contract refuses the durable mode
        // when wired to the record-nothing ledger.
        $ledger = new class implements \Waaseyaa\Foundation\Audit\StrictAuditLedgerInterface {
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

        self::assertInstanceOf(
            \Waaseyaa\Mcp\AuthenticatedMcpEndpoint::class,
            $this->resolveWriteEndpoint([], $ledger),
        );
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

        $this->resolveWriteEndpoint([], new \Waaseyaa\Foundation\Audit\NullStrictAuditLedger());
    }

    /** Opting out is explicit and supported — it must not throw. */
    #[Test]
    public function durable_audit_can_be_explicitly_disabled(): void
    {
        self::assertInstanceOf(
            \Waaseyaa\Mcp\AuthenticatedMcpEndpoint::class,
            $this->resolveWriteEndpoint(['mcp' => ['write_tier' => ['durable_audit' => false]]]),
        );
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
