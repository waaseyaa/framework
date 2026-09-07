<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The installed-consumer proof for the public `Waaseyaa\CLI\Io\StdinSource`
 * interface must remain an unconditional, blocking CI job (#2961).
 *
 * Composer honours `autoload-dev` for the root package only, so a mapping
 * regression that makes a declared-public symbol unreachable downstream is
 * invisible in the monorepo and invisible to every other job in this
 * workflow. An unreferenced harness is manual evidence, not a regression
 * guard; this test is what makes it the latter.
 */
#[CoversNothing]
final class CliIoConsumerContractGateTest extends TestCase
{
    #[Test]
    public function pull_request_ci_runs_the_packaged_io_contract_proof_without_a_success_escape(): void
    {
        $root = dirname(__DIR__, 2);
        $harness = 'tests/PackagedForm/check-cli-io-consumer-contract';
        self::assertFileExists($root . '/' . $harness);
        self::assertTrue(is_executable($root . '/' . $harness));
        $workflow = Yaml::parseFile($root . '/.github/workflows/ci.yml');
        self::assertArrayHasKey('cli-io-consumer-contract', $workflow['jobs']);
        $job = $workflow['jobs']['cli-io-consumer-contract'];
        self::assertSame('ci/cli-io-consumer-contract', $job['name']);
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

    #[Test]
    public function the_proof_checks_out_the_immutable_candidate_head(): void
    {
        // Every consumer-shaped job in this workflow binds to the exact
        // candidate SHA rather than a branch tip, so the evidence names the
        // bytes it actually tested.
        $root = dirname(__DIR__, 2);
        $workflow = Yaml::parseFile($root . '/.github/workflows/ci.yml');
        $checkouts = array_values(array_filter(
            $workflow['jobs']['cli-io-consumer-contract']['steps'],
            static fn(array $step): bool => str_starts_with((string) ($step['uses'] ?? ''), 'actions/checkout@'),
        ));
        self::assertCount(1, $checkouts);
        self::assertSame('${{ inputs.sha }}', $checkouts[0]['with']['ref'] ?? null);
    }
}
