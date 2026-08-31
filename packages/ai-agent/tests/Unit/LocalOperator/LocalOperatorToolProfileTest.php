<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit\LocalOperator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Agent\LocalOperator\LocalOperatorPrincipal;
use Waaseyaa\AI\Agent\LocalOperator\LocalOperatorRefusal;
use Waaseyaa\AI\Agent\LocalOperator\LocalOperatorToolProfile;
use Waaseyaa\AI\Agent\LocalOperator\LocalOperatorTransportAttestation;
use Waaseyaa\Foundation\Sovereignty\SovereigntyProfile;

/** ADR-022 D-7 (the default catalogue) and D-8 (fail-closed read-side policy). */
#[CoversClass(LocalOperatorToolProfile::class)]
#[CoversClass(LocalOperatorRefusal::class)]
final class LocalOperatorToolProfileTest extends TestCase
{
    /** D-7 — the allowlist opens with exactly these three tools. */
    #[Test]
    public function the_default_allowlist_is_exactly_the_three_structural_tools(): void
    {
        self::assertSame(
            [
                'bimaaji_introspect_graph',
                'bimaaji_introspect_section',
                'bimaaji_search_specs',
            ],
            (new LocalOperatorToolProfile())->toolIds(),
        );
    }

    /**
     * D-7 — `bimaaji_search_specs` stays listed despite being inert until
     * #2661 and #2662 land. Its membership is already reviewed, and re-adding
     * it later would read to the D-7.3 gate as a deliberate widening.
     */
    #[Test]
    public function the_inert_spec_search_tool_stays_on_the_allowlist(): void
    {
        self::assertContains('bimaaji_search_specs', LocalOperatorToolProfile::DEFAULT_TOOL_IDS);
    }

    #[Test]
    public function the_default_capability_grant_is_exactly_bimaaji_read(): void
    {
        self::assertSame(['bimaaji.read'], (new LocalOperatorToolProfile())->capabilities());
    }

    /** D-7.1 — exact string match; never a prefix, pattern, or wildcard. */
    #[Test]
    public function admission_is_exact_membership(): void
    {
        $profile = new LocalOperatorToolProfile();

        self::assertTrue($profile->admits('bimaaji_introspect_graph'));
        self::assertTrue($profile->admits('bimaaji_introspect_section'));
        self::assertTrue($profile->admits('bimaaji_search_specs'));

        foreach ([
            'bimaaji_introspect',
            'bimaaji_introspect_graph_v2',
            'bimaaji_',
            'bimaaji_*',
            '*',
            '',
            'BIMAAJI_INTROSPECT_GRAPH',
            'bimaaji_propose_mutation',
            'entity.read',
        ] as $toolId) {
            self::assertFalse($profile->admits($toolId), $toolId);
        }
    }

    /**
     * D-7 — the exhaustive withheld list. Content and entity values,
     * relationships, vectors, and every mutation are off by default.
     */
    #[Test]
    #[DataProvider('withheldCapabilities')]
    public function the_default_grant_withholds_every_content_and_mutation_capability(string $capability): void
    {
        self::assertNotContains($capability, LocalOperatorToolProfile::DEFAULT_CAPABILITIES);
    }

    /** @return iterable<string, array{0: string}> */
    public static function withheldCapabilities(): iterable
    {
        foreach (LocalOperatorToolProfile::WITHHELD_CAPABILITIES as $capability) {
            yield $capability => [$capability];
        }
    }

    /**
     * D-7.2 — the layered check, from the principal's side: a tool on the
     * allowlist whose capability the principal does not hold is still refused
     * by the capability test. The allowlist narrows; it never widens.
     */
    #[Test]
    public function the_allowlist_never_widens_the_capability_grant(): void
    {
        $principal = LocalOperatorPrincipal::forLocalStdioTransport(
            ['environment' => 'local'],
            LocalOperatorTransportAttestation::STDIO_TRANSPORT_ID,
            // A tool id is allowlisted while its capability is NOT granted.
            new LocalOperatorToolProfile(['bimaaji_introspect_graph'], []),
            'cli',
        );

        self::assertTrue($principal->toolProfile()->admits('bimaaji_introspect_graph'));
        self::assertFalse(
            $principal->hasPermission('bimaaji.read'),
            'Allowlisting a tool must not grant its capability — requireCapability() still refuses.',
        );
    }

    /**
     * D-8.2 — fail closed. Granting a content-bearing capability is refused
     * while the read-side sovereignty evaluation surface does not exist.
     */
    #[Test]
    #[DataProvider('withheldCapabilities')]
    public function granting_a_content_bearing_capability_is_refused(string $capability): void
    {
        try {
            new LocalOperatorToolProfile(
                LocalOperatorToolProfile::DEFAULT_TOOL_IDS,
                ['bimaaji.read', $capability],
                SovereigntyProfile::Local,
            );
            self::fail(sprintf('Capability "%s" must be refused (D-8).', $capability));
        } catch (LocalOperatorRefusal $refusal) {
            self::assertSame('D-8', $refusal->row);
        }
    }

    /**
     * D-8.2 — even the `Local` profile is not consent to ship content values.
     * The fallback to `SovereigntyProfile::Local` is a default for structural
     * introspection only.
     */
    #[Test]
    public function a_local_sovereignty_profile_is_not_consent_to_content_reads(): void
    {
        $this->expectException(LocalOperatorRefusal::class);

        new LocalOperatorToolProfile(
            ['entity.read'],
            ['tool.entity.read'],
            SovereigntyProfile::Local,
        );
    }

    #[Test]
    public function an_unresolved_read_policy_is_a_distinct_digest_input(): void
    {
        self::assertSame('unresolved', (new LocalOperatorToolProfile())->readPolicyToken());
        self::assertSame(
            'local',
            (new LocalOperatorToolProfile(
                LocalOperatorToolProfile::DEFAULT_TOOL_IDS,
                LocalOperatorToolProfile::DEFAULT_CAPABILITIES,
                SovereigntyProfile::Local,
            ))->readPolicyToken(),
        );
        self::assertNull((new LocalOperatorToolProfile())->readPolicy());
    }

    #[Test]
    public function an_empty_tool_id_or_capability_is_refused(): void
    {
        try {
            new LocalOperatorToolProfile(['']);
            self::fail('An empty tool id must be refused.');
        } catch (LocalOperatorRefusal $refusal) {
            self::assertSame('D-7', $refusal->row);
        }

        try {
            new LocalOperatorToolProfile(LocalOperatorToolProfile::DEFAULT_TOOL_IDS, ['  ']);
            self::fail('An empty capability must be refused.');
        } catch (LocalOperatorRefusal $refusal) {
            self::assertSame('D-7', $refusal->row);
        }
    }

    #[Test]
    public function lists_are_normalized_so_that_the_same_authority_digests_identically(): void
    {
        $profile = new LocalOperatorToolProfile(
            ['bimaaji_search_specs', 'bimaaji_introspect_graph', 'bimaaji_search_specs'],
            ['bimaaji.read', 'bimaaji.read'],
        );

        self::assertSame(['bimaaji_introspect_graph', 'bimaaji_search_specs'], $profile->toolIds());
        self::assertSame(['bimaaji.read'], $profile->capabilities());
    }
}
