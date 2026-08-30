<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit\LocalOperator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\AI\Agent\LocalOperator\LocalOperatorPrincipal;
use Waaseyaa\AI\Agent\LocalOperator\LocalOperatorRefusal;
use Waaseyaa\AI\Agent\LocalOperator\LocalOperatorToolProfile;
use Waaseyaa\AI\Agent\LocalOperator\LocalOperatorTransportAttestation;
use Waaseyaa\Foundation\Sovereignty\SovereigntyProfile;

/**
 * ADR-022 D-3 (identity), D-4 (claims generation), D-5.D (audit identity),
 * and the D-6 refusal rows this class owns.
 */
#[CoversClass(LocalOperatorPrincipal::class)]
#[CoversClass(LocalOperatorTransportAttestation::class)]
#[CoversClass(LocalOperatorRefusal::class)]
final class LocalOperatorPrincipalTest extends TestCase
{
    /** @var array<string, mixed> */
    private const array DEV_CONFIG = ['environment' => 'local'];

    private function principal(?LocalOperatorToolProfile $profile = null): LocalOperatorPrincipal
    {
        return LocalOperatorPrincipal::forLocalStdioTransport(
            self::DEV_CONFIG,
            LocalOperatorTransportAttestation::STDIO_TRANSPORT_ID,
            $profile,
            'cli',
        );
    }

    #[Test]
    public function it_is_an_authorization_principal(): void
    {
        self::assertInstanceOf(AuthorizationPrincipalInterface::class, $this->principal());
    }

    /** D-3.2 — a string sentinel, never an int. */
    #[Test]
    public function id_is_a_fixed_string_sentinel(): void
    {
        $id = $this->principal()->id();

        self::assertIsString($id);
        self::assertSame('local-operator:stdio', $id);
        self::assertSame(LocalOperatorPrincipal::ID, $id);
    }

    /**
     * D-3.2 — the sentinel cannot be confused with either existing sentinel
     * account, and D-6 R-4's hazard is real: `(int)` of it is `0`, which is
     * `AnonymousUser`'s id. That is precisely why it must never be the ambient
     * acting account.
     */
    #[Test]
    public function the_sentinel_collides_with_no_uid_but_casts_to_the_anonymous_id(): void
    {
        $id = $this->principal()->id();

        self::assertNotSame(0, $id, 'must not be AnonymousUser id 0');
        self::assertNotSame(PHP_INT_MAX, $id, 'must not be DevAdminAccount id PHP_INT_MAX');
        self::assertSame(0, (int) $id, 'documented hazard: the int cast is the anonymous id — hence R-4');
    }

    /**
     * D-3.3 — a fixed literal. No OS username, home directory, hostname, or
     * absolute project path may appear anywhere the principal reports itself.
     */
    #[Test]
    public function no_machine_identifying_value_appears_in_the_identity_surface(): void
    {
        $principal = $this->principal();
        $surface = implode('|', [
            $principal->id(),
            $principal->claimsGeneration(),
            implode(',', $principal->getRoles()),
            json_encode($principal->auditMetadata(), JSON_THROW_ON_ERROR),
        ]);

        foreach ($this->machineIdentifyingValues() as $label => $value) {
            self::assertStringNotContainsStringIgnoringCase(
                $value,
                $surface,
                sprintf('The identity surface leaked a machine-identifying value (%s).', $label),
            );
        }
    }

    /** D-3.4 — strict membership; never a blanket grant, prefix, or wildcard. */
    #[Test]
    public function has_permission_is_strict_membership(): void
    {
        $principal = $this->principal();

        self::assertTrue($principal->hasPermission('bimaaji.read'));
        self::assertFalse($principal->hasPermission('bimaaji.mutate'));
        self::assertFalse($principal->hasPermission('administer content'));
        self::assertFalse($principal->hasPermission(''));
        // No prefix or wildcard semantics of any kind.
        self::assertFalse($principal->hasPermission('bimaaji'));
        self::assertFalse($principal->hasPermission('bimaaji.'));
        self::assertFalse($principal->hasPermission('bimaaji.read.extra'));
        self::assertFalse($principal->hasPermission('*'));
        self::assertFalse($principal->hasPermission('bimaaji.*'));
    }

    /**
     * The contrast with `DevAdminAccount::hasPermission()`, which returns
     * `true` for every string (ADR-022 C-2).
     */
    #[Test]
    public function has_permission_refuses_arbitrary_strings(): void
    {
        $principal = $this->principal();

        foreach (['anything', 'administer users', 'tool.entity.read', 'x', "\0"] as $permission) {
            self::assertFalse($principal->hasPermission($permission), $permission);
        }
    }

    /** D-3.5 — no `administrator`, and no blanket-grant role. */
    #[Test]
    public function roles_exclude_administrator(): void
    {
        $roles = $this->principal()->getRoles();

        self::assertSame(['local_operator'], $roles);
        self::assertNotContains('administrator', $roles);
    }

    #[Test]
    public function it_is_authenticated(): void
    {
        self::assertTrue($this->principal()->isAuthenticated());
    }

    /** D-3.6 — unbound to any tenant or community by default. */
    #[Test]
    public function tenant_and_community_are_null_by_default(): void
    {
        $principal = $this->principal();

        self::assertNull($principal->tenantId());
        self::assertNull($principal->communityId());
    }

    /** D-4.2 — never a constant literal, unlike `DevAdminAccount`'s 'dev-admin'. */
    #[Test]
    public function claims_generation_is_a_digest_not_a_constant(): void
    {
        $generation = $this->principal()->claimsGeneration();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $generation);
        self::assertNotSame('dev-admin', $generation);
        self::assertNotSame('local-operator:stdio', $generation);
    }

    #[Test]
    public function claims_generation_is_deterministic_for_the_same_authority(): void
    {
        self::assertSame($this->principal()->claimsGeneration(), $this->principal()->claimsGeneration());
    }

    #[Test]
    public function claims_generation_is_order_insensitive(): void
    {
        $one = $this->principal(new LocalOperatorToolProfile(
            ['bimaaji_introspect_graph', 'bimaaji_search_specs'],
            ['bimaaji.read', 'bimaaji.describe'],
        ));
        $other = $this->principal(new LocalOperatorToolProfile(
            ['bimaaji_search_specs', 'bimaaji_introspect_graph'],
            ['bimaaji.describe', 'bimaaji.read'],
        ));

        self::assertSame($one->claimsGeneration(), $other->claimsGeneration());
    }

    /**
     * D-4's central property, stated in the ADR's corrected form: **the
     * dangerous direction is narrowing**. A reduced grant must not be able to
     * reuse a cache entry computed while the principal still held the broader
     * authority.
     */
    #[Test]
    public function narrowing_the_capability_grant_advances_the_generation(): void
    {
        $broad = $this->principal(new LocalOperatorToolProfile(
            LocalOperatorToolProfile::DEFAULT_TOOL_IDS,
            ['bimaaji.read', 'bimaaji.describe'],
        ));
        $narrow = $this->principal(new LocalOperatorToolProfile(
            LocalOperatorToolProfile::DEFAULT_TOOL_IDS,
            ['bimaaji.read'],
        ));

        self::assertNotSame($broad->claimsGeneration(), $narrow->claimsGeneration());
    }

    #[Test]
    public function narrowing_the_tool_allowlist_advances_the_generation(): void
    {
        $broad = $this->principal(new LocalOperatorToolProfile(LocalOperatorToolProfile::DEFAULT_TOOL_IDS));
        $narrow = $this->principal(new LocalOperatorToolProfile(['bimaaji_introspect_graph']));

        self::assertNotSame($broad->claimsGeneration(), $narrow->claimsGeneration());
    }

    /** D-4.1 — the active read-side sovereignty policy is a digest input. */
    #[Test]
    public function tightening_the_read_side_sovereignty_policy_advances_the_generation(): void
    {
        $generations = [];
        foreach ([null, SovereigntyProfile::Local, SovereigntyProfile::SelfHosted, SovereigntyProfile::NorthOps] as $policy) {
            $generations[] = $this->principal(new LocalOperatorToolProfile(
                LocalOperatorToolProfile::DEFAULT_TOOL_IDS,
                LocalOperatorToolProfile::DEFAULT_CAPABILITIES,
                $policy,
            ))->claimsGeneration();
        }

        self::assertSame($generations, array_values(array_unique($generations)), 'each read policy is a distinct authority');
    }

    #[Test]
    public function binding_a_tenant_or_community_advances_the_generation(): void
    {
        $unbound = $this->principal()->claimsGeneration();

        $tenantBound = LocalOperatorPrincipal::forLocalStdioTransport(
            self::DEV_CONFIG,
            LocalOperatorTransportAttestation::STDIO_TRANSPORT_ID,
            null,
            'cli',
            'tenant-a',
        );
        $communityBound = LocalOperatorPrincipal::forLocalStdioTransport(
            self::DEV_CONFIG,
            LocalOperatorTransportAttestation::STDIO_TRANSPORT_ID,
            null,
            'cli',
            null,
            'community-a',
        );

        self::assertNotSame($unbound, $tenantBound->claimsGeneration());
        self::assertNotSame($unbound, $communityBound->claimsGeneration());
        self::assertNotSame($tenantBound->claimsGeneration(), $communityBound->claimsGeneration());
    }

    /** D-5.D.7 — `actorUid` is `null`, never a coerced `0`. */
    #[Test]
    public function audit_actor_uid_is_null(): void
    {
        self::assertNull($this->principal()->auditActorUid());
    }

    /** D-5.D.7 — the identity travels in metadata instead. */
    #[Test]
    public function audit_metadata_carries_the_sentinel_and_the_generation(): void
    {
        $principal = $this->principal();
        $metadata = $principal->auditMetadata();

        self::assertSame(LocalOperatorPrincipal::ID, $metadata['principal']);
        self::assertSame($principal->claimsGeneration(), $metadata['claims_generation']);
        self::assertSame(LocalOperatorTransportAttestation::STDIO_TRANSPORT_ID, $metadata['transport']);
        self::assertSame(['local_operator'], $metadata['roles']);
    }

    /** R-5 — serialization is refused loudly, not silently allowed. */
    #[Test]
    public function serialization_is_refused(): void
    {
        $principal = $this->principal();

        try {
            serialize($principal);
            self::fail('serialize() must be refused (R-5).');
        } catch (LocalOperatorRefusal $refusal) {
            self::assertSame('R-5', $refusal->row);
        }
    }

    #[Test]
    public function json_encoding_is_refused(): void
    {
        $principal = $this->principal();

        try {
            json_encode($principal, JSON_THROW_ON_ERROR);
            self::fail('json_encode() must be refused (R-5).');
        } catch (LocalOperatorRefusal $refusal) {
            self::assertSame('R-5', $refusal->row);
        }
    }

    #[Test]
    public function var_export_round_tripping_is_refused(): void
    {
        $this->expectException(LocalOperatorRefusal::class);
        $this->expectExceptionMessage('var_export');

        LocalOperatorPrincipal::__set_state(['id' => 'local-operator:stdio']);
    }

    #[Test]
    public function unserializing_a_forged_payload_is_refused(): void
    {
        $forged = 'O:' . strlen(LocalOperatorPrincipal::class) . ':"' . LocalOperatorPrincipal::class . '":0:{}';

        $this->expectException(LocalOperatorRefusal::class);
        unserialize($forged);
    }

    /**
     * R-6 — the environment gate. `RuntimePolicy::isExplicitDevelopment()`
     * classifies only an explicitly configured `environment` key, so an
     * unconfigured runtime is production-like and refused.
     *
     * @param array<string, mixed> $config
     */
    #[Test]
    #[DataProvider('productionShapedConfigurations')]
    public function construction_is_refused_outside_an_explicit_development_runtime(array $config): void
    {
        try {
            LocalOperatorPrincipal::forLocalStdioTransport(
                $config,
                LocalOperatorTransportAttestation::STDIO_TRANSPORT_ID,
                null,
                'cli',
            );
            self::fail('Construction must be refused outside a development runtime (R-6).');
        } catch (LocalOperatorRefusal $refusal) {
            self::assertSame('R-6', $refusal->row);
            self::assertStringContainsString('development environment', $refusal->getMessage());
        }
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function productionShapedConfigurations(): iterable
    {
        yield 'production' => [['environment' => 'production']];
        yield 'staging' => [['environment' => 'staging']];
        yield 'unconfigured (fail closed)' => [[]];
        yield 'non-string environment' => [['environment' => true]];
        yield 'null environment' => [['environment' => null]];
        yield 'debug on, environment absent' => [['debug' => true]];
    }

    /**
     * R-6 / R-1 — every HTTP-served SAPI is refused, including the two the
     * kernel's own dev fallback admits (`cli-server`, `frankenphp`) and the
     * third `DevAdminAccount::ALLOWED_SAPIS` admits. The local operator's SAPI
     * gate is deliberately narrower than either.
     */
    #[Test]
    #[DataProvider('nonCliSapis')]
    public function construction_is_refused_outside_the_cli_sapi(string $sapi): void
    {
        try {
            LocalOperatorPrincipal::forLocalStdioTransport(
                self::DEV_CONFIG,
                LocalOperatorTransportAttestation::STDIO_TRANSPORT_ID,
                null,
                $sapi,
            );
            self::fail(sprintf('Construction must be refused under SAPI %s (R-6).', $sapi));
        } catch (LocalOperatorRefusal $refusal) {
            self::assertSame('R-6', $refusal->row);
            self::assertStringContainsString('cli', $refusal->getMessage());
        }
    }

    /** @return iterable<string, array{0: string}> */
    public static function nonCliSapis(): iterable
    {
        // The HTTP-served SAPIs — this is what makes R-1 and R-2 structural.
        yield 'fpm-fcgi' => ['fpm-fcgi'];
        yield 'apache2handler' => ['apache2handler'];
        yield 'cgi-fcgi' => ['cgi-fcgi'];
        yield 'litespeed' => ['litespeed'];
        // The two SAPIs HttpKernel::DEV_FALLBACK_SAPIS admits, both refused here.
        yield 'cli-server' => ['cli-server'];
        yield 'frankenphp' => ['frankenphp'];
        yield 'phpdbg' => ['phpdbg'];
    }

    /** R-6 — a caller that is not the local stdio transport is refused. */
    #[Test]
    public function construction_is_refused_for_any_other_transport(): void
    {
        foreach (['mcp.write', 'mcp', 'http', '', 'waaseyaa.local.stdio '] as $transportId) {
            try {
                LocalOperatorPrincipal::forLocalStdioTransport(self::DEV_CONFIG, $transportId, null, 'cli');
                self::fail(sprintf('Transport "%s" must be refused (R-6).', $transportId));
            } catch (LocalOperatorRefusal $refusal) {
                self::assertSame('R-6', $refusal->row);
            }
        }
    }

    #[Test]
    public function the_attestation_itself_is_not_serializable(): void
    {
        $attestation = LocalOperatorTransportAttestation::forStdioTransport(
            self::DEV_CONFIG,
            LocalOperatorTransportAttestation::STDIO_TRANSPORT_ID,
            'cli',
        );

        $this->expectException(LocalOperatorRefusal::class);
        serialize($attestation);
    }

    /**
     * Machine-identifying values a leak would most plausibly carry.
     *
     * @return array<string, string>
     */
    private function machineIdentifyingValues(): array
    {
        $values = [
            'project path' => dirname(__DIR__, 5),
            'hostname' => (string) gethostname(),
        ];
        foreach (['USER', 'USERNAME', 'LOGNAME', 'HOME', 'USERPROFILE'] as $variable) {
            $value = getenv($variable);
            if (is_string($value) && strlen($value) > 2) {
                $values[$variable] = $value;
            }
        }

        return array_filter($values, static fn(string $value): bool => strlen($value) > 2);
    }
}
