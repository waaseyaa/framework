<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/** The slow installed-consumer proof must remain an unconditional, blocking CI job. */
#[CoversNothing]
final class CliSyncRulesGateTest extends TestCase
{
    #[Test]
    public function pull_request_ci_runs_the_packaged_rule_proof_without_a_success_escape(): void
    {
        $root = dirname(__DIR__, 2);
        $harness = 'tests/PackagedForm/check-cli-sync-rules';
        self::assertFileExists($root . '/' . $harness);
        self::assertTrue(is_executable($root . '/' . $harness));
        $workflow = Yaml::parseFile($root . '/.github/workflows/ci.yml');
        self::assertArrayHasKey('cli-sync-rules', $workflow['jobs']);
        $job = $workflow['jobs']['cli-sync-rules'];
        self::assertSame('ci/cli-sync-rules', $job['name']);
        self::assertArrayNotHasKey('if', $job);
        self::assertFalse($job['continue-on-error'] ?? false);
        $proofSteps = array_values(array_filter(
            $job['steps'],
            static fn(array $step): bool => ($step['run'] ?? '') === 'bash ' . $harness,
        ));
        self::assertCount(1, $proofSteps);
        self::assertArrayNotHasKey('if', $proofSteps[0]);
        self::assertFalse($proofSteps[0]['continue-on-error'] ?? false);
    }
}
