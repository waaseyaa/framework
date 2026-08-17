<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the fast-contracts-gate-the-long-jobs ordering
 * (docs/specs/governed-gates.md §5): the ~30s support/s1-contract job is a
 * `needs:` prerequisite of the shard execution and random-order job, so a stale recorded
 * roster is one fast red job with a precise message — never the same failure
 * re-reported by test, random-order, and coverage minutes later (the PR #2399
 * failure shape).
 */
#[CoversNothing]
final class CiContractOrderingTest extends TestCase
{
    #[Test]
    public function fast_contract_job_runs_every_s1_roster_gate(): void
    {
        $ci = (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/ci.yml');
        $supportJob = $this->job($ci, 'support-contract');

        foreach ([
            'php bin/check-support-contract --ci',
            'php bin/check-s1-sqlite-contract --ci',
            'php bin/check-s1-configuration-activation',
            'php bin/check-s1-configuration-authority',
            'php bin/check-s1-schema-authority',
        ] as $command) {
            $this->assertStringContainsString($command, $supportJob);
        }
    }

    #[Test]
    public function spec_drift_job_completes_on_non_pull_request_events(): void
    {
        $ci = (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/ci.yml');
        $specDriftJob = $this->job($ci, 'spec-drift');

        $this->assertStringContainsString("if: github.event_name == 'pull_request'", $specDriftJob);
        $this->assertStringContainsString("if: github.event_name != 'pull_request'", $specDriftJob);
        $this->assertStringContainsString('spec-drift is a pull-request coupling check', $specDriftJob);
    }

    #[Test]
    public function test_execution_waits_for_fast_contracts_and_aggregators_wait_for_shards(): void
    {
        $ci = (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/ci.yml');

        foreach (['prepare-test-plan', 'ci-test-shards', 'prepare-random-order-plan'] as $job) {
            $this->assertSame(
                1,
                preg_match(
                    '/^  ' . preg_quote($job, '/') . ':\n(?:.*\n)*?    needs: \[support-contract, spec-drift\]$/m',
                    $ci,
                ),
                sprintf('Job %s must wait for support-contract and spec-drift so governance failures fail fast.', $job),
            );
        }

        foreach (['ci-unit-tests', 'ci-coverage'] as $job) {
            $this->assertStringContainsString(
                'needs: [ci-test-shards]',
                $this->job($ci, $job),
                sprintf('Job %s must consume the shared shard result rather than execute tests.', $job),
            );
        }
    }

    #[Test]
    public function both_shard_matrices_wait_for_the_single_install_authority(): void
    {
        $ci = (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/ci.yml');

        // prepare-test-plan is the sole authoritative `composer install` for
        // the run (#2404 revision: codeload 429s were caused by every job
        // installing independently). ci-test-shards already needed
        // prepare-test-plan for its shard plan; it must keep that edge.
        // ci-random-order-shard did not previously need prepare-test-plan at
        // all — it must gain the edge to consume the shared vendor archive.
        $this->assertStringContainsString(
            'needs: [support-contract, spec-drift, prepare-test-plan]',
            $this->job($ci, 'ci-test-shards'),
        );
        $this->assertStringContainsString(
            'needs: [prepare-random-order-plan, prepare-test-plan]',
            $this->job($ci, 'ci-random-order-shard'),
        );
    }

    private function job(string $workflow, string $id): string
    {
        $matched = preg_match(
            '/^  ' . preg_quote($id, '/') . ':\n(?:(?!^  [a-zA-Z0-9_-]+:).*(?:\n|$))*/m',
            $workflow,
            $matches,
        );
        $this->assertSame(1, $matched, sprintf('Workflow job %s must exist.', $id));

        return $matches[0];
    }
}
