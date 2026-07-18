<?php

declare(strict_types=1);

namespace Waaseyaa\Testing\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 4) . '/benchmarks/FieldReadBenchmark.php';

#[CoversNothing]
#[Group('benchmark')]
final class FieldReadPerformanceDiagnosticsTest extends TestCase
{
    #[Test]
    public function synthetic_fixture_reports_diagnostics_without_acting_as_the_release_gate(): void
    {
        $report = \Waaseyaa\Benchmarks\FieldReadBenchmark::run(iterations: 100_000, samples: 9);

        self::assertSame([
            'unbooted_public_baseline',
            'booted_class_definition_public',
            'booted_bundle_definition_public',
            'translation_and_revision_public',
            'config_and_audit_read_model_public',
            'principal_creation',
            'protected_cold',
            'protected_warm',
            'strict_audited_read',
            'fifty_field_projection',
        ], array_keys($report['fixtures']));

        foreach ($report['fixtures'] as $fixture) {
            self::assertGreaterThan(0.0, $fixture['median_nanoseconds_per_operation']);
            self::assertGreaterThanOrEqual(0, $fixture['peak_memory_bytes']);
            self::assertGreaterThanOrEqual(0, $fixture['allocation_proxy_bytes']);
        }

        self::assertSame([
            'warm_public_to_matched_baseline',
            'warm_protected_to_guarded_public',
        ], array_keys($report['diagnostics']));
        foreach ($report['diagnostics'] as $diagnostic) {
            self::assertGreaterThan(0.0, $diagnostic['ratio']);
            self::assertGreaterThan(0.0, $diagnostic['reference_ratio']);
        }
        self::assertArrayNotHasKey('passed', $report['diagnostics']);
    }
}
