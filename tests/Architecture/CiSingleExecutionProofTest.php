<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CiSingleExecutionProofTest extends TestCase
{
    #[Test]
    public function pullRequestProofRunsOneTimingBalancedCoverageExecution(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/ci.yml');
        self::assertIsString($workflow);

        self::assertStringContainsString('cancel-in-progress: ${{ github.event_name == \'pull_request\' }}', $workflow);
        self::assertStringContainsString('prepare-test-plan:', $workflow);
        self::assertStringContainsString('php bin/build-phpunit-shards', $workflow);
        self::assertStringContainsString('ci-test-shards:', $workflow);
        self::assertStringContainsString('strategy:', $workflow);
        self::assertStringContainsString('id: [1, 2, 3, 4]', $workflow);
        self::assertStringContainsString('name: phpunit-shard-plan', $workflow);
        self::assertStringContainsString('--coverage-clover build/logs/clover-${{ matrix.id }}.xml', $workflow);
        self::assertStringContainsString('--log-junit build/logs/junit-${{ matrix.id }}.xml', $workflow);
        self::assertStringContainsString('php bin/merge-clover-coverage', $workflow);

        $coverageJob = $this->job($workflow, 'ci-coverage', 'mutation-pilot');
        self::assertStringNotContainsString('vendor/bin/phpunit', $coverageJob);
        // Bounded to the ci-unit-tests job alone: ci-random-order moved
        // below prepare-random-order-plan and ci-random-order-shard, so
        // using 'ci-random-order' as the end marker here would silently
        // widen this assertion's slice to cover those two unrelated jobs
        // as well. prepare-random-order-plan is ci-unit-tests's immediate
        // successor in the workflow file today.
        $unitGate = $this->job($workflow, 'ci-unit-tests', 'prepare-random-order-plan');
        self::assertStringNotContainsString('vendor/bin/phpunit', $unitGate);
        self::assertStringContainsString('needs: [ci-test-shards]', $unitGate);
    }

    #[Test]
    public function randomOrderPreparesOnceAndFansOutToThreeShards(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/ci.yml');

        self::assertStringContainsString('prepare-random-order-plan:', $workflow);
        self::assertStringContainsString('php bin/select-random-order-scope', $workflow);
        self::assertStringContainsString('--shards=3', $workflow);
        // GitHub's needs.<matrix-job>.result aggregate can prove "every leg
        // that ran succeeded" but cannot express "there were exactly three
        // legs" — so this assertion on the matrix declaration itself is the
        // only guard against the shard count silently drifting from 3. Keep
        // it in sync with the aggregator's comment in ci.yml.
        self::assertStringContainsString('id: [1, 2, 3]', $workflow);
        self::assertStringContainsString('bin/test-random-order --plan=', $workflow);
    }

    #[Test]
    public function theRandomOrderAggregatorRefusesIncompleteShardEvidence(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/ci.yml');
        $job = $this->job($workflow, 'ci-random-order', 'ci-package-isolation');

        // Scoped to the aggregator job and matched with a trailing newline:
        // the shard job's `name: ci/random-order-shard-${{ matrix.id }}`
        // line shares `name: ci/random-order` as a literal prefix, so a
        // whole-file or prefix-only match would keep passing even if the
        // aggregator's own name: line were deleted — the exact regression
        // this assertion exists to catch on the repo's protected required
        // context.
        self::assertStringContainsString("name: ci/random-order\n", $job);
        self::assertStringContainsString('needs: [prepare-random-order-plan, ci-random-order-shard]', $job);
        // `if: always()` alone would publish success after skipped shards —
        // it must be paired with the PLAN_RESULT/SHARD_RESULT checks below.
        // Pinned here because nothing else in this suite asserts it, and
        // spec §7.4 names it explicitly.
        self::assertStringContainsString('if: always()', $job);
        self::assertStringContainsString('PLAN_RESULT', $job);
        self::assertStringContainsString('SHARD_RESULT', $job);
        self::assertStringContainsString('test "$PLAN_RESULT" = success', $job);
        self::assertStringContainsString('test "$SHARD_RESULT" = success', $job);
    }

    #[Test]
    public function shardsVerifyTheRunScopedDependencyArtifact(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/ci.yml');
        // Scoped to the random-order jobs only: `restore-keys: composer-v2-`
        // legitimately appears elsewhere in ci.yml (ci-test-shards,
        // ci-package-isolation, etc.) — spec §7.3's broad-restore-key
        // prohibition binds the random-order lane's dependency preparation,
        // not the whole workflow file.
        $randomOrderJobs = $this->job($workflow, 'prepare-random-order-plan', 'ci-package-isolation');

        // The digest/extraction/symlink checks themselves are NOT grepped
        // for here — they are extracted into bin/verify-random-order-vendor-archive
        // and exercised against real fixture archives (good, missing,
        // corrupt digest, dangling symlink) by
        // RandomOrderVendorArchiveIntegrityTest. This test only pins that
        // the workflow actually wires that script in, plus the
        // still-inline platform check and the broad-restore-key ban.
        self::assertStringContainsString('bin/verify-random-order-vendor-archive', $randomOrderJobs);
        self::assertStringContainsString('composer check-platform-reqs', $randomOrderJobs);
        self::assertStringNotContainsString('restore-keys: composer-v2-', $randomOrderJobs);
    }

    private function job(string $workflow, string $name, string $next): string
    {
        $start = strpos($workflow, "  {$name}:");
        $end = strpos($workflow, "  {$next}:", $start === false ? 0 : $start + 1);
        self::assertNotFalse($start, $name);
        self::assertNotFalse($end, $next);

        return substr($workflow, (int) $start, (int) $end - (int) $start);
    }
}
