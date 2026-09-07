<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/** The slow recipe-provider proof must remain an unconditional, failure-propagating CI job. */
#[CoversNothing]
final class SiteRecipeProviderActivationGateTest extends TestCase
{
    private const string HARNESS = 'tests/PackagedForm/check-site-recipe-provider-activation';
    private const string CANDIDATE = '${{ inputs.sha || github.event.pull_request.head.sha || github.sha }}';

    #[Test]
    public function pull_request_ci_runs_the_exact_candidate_proof_without_a_success_escape(): void
    {
        $root = dirname(__DIR__, 2);
        $harness = $root . '/' . self::HARNESS;

        self::assertFileExists($harness);
        self::assertTrue(is_executable($harness), self::HARNESS . ' must be executable.');

        $source = (string) file_get_contents($harness);
        self::assertStringContainsString('CANDIDATE_SHA', $source);
        self::assertStringContainsString('archive --format=tar', $source);
        self::assertStringContainsString('site:init', $source);
        self::assertStringContainsString('install:init', $source);
        self::assertStringContainsString('site-verify', $source);
        self::assertStringContainsString('probe-rival.out', $source);

        $workflow = Yaml::parseFile($root . '/.github/workflows/ci.yml');
        self::assertIsArray($workflow);
        self::assertArrayHasKey('site-recipe-provider-activation', $workflow['jobs']);

        $job = $workflow['jobs']['site-recipe-provider-activation'];
        self::assertSame('ci/site-recipe-provider-activation', $job['name']);
        self::assertArrayNotHasKey('if', $job);
        self::assertFalse($job['continue-on-error'] ?? false);

        $checkoutSteps = array_values(array_filter(
            $job['steps'],
            static fn(array $step): bool => str_starts_with($step['uses'] ?? '', 'actions/checkout@'),
        ));
        self::assertCount(1, $checkoutSteps);
        self::assertSame(self::CANDIDATE, $checkoutSteps[0]['with']['ref'] ?? null);

        $proofSteps = array_values(array_filter(
            $job['steps'],
            static fn(array $step): bool => ($step['run'] ?? '') === 'bash ' . self::HARNESS,
        ));
        self::assertCount(1, $proofSteps);
        self::assertSame(self::CANDIDATE, $proofSteps[0]['env']['CANDIDATE_SHA'] ?? null);
        self::assertArrayNotHasKey('if', $proofSteps[0]);
        self::assertFalse($proofSteps[0]['continue-on-error'] ?? false);
    }
}
