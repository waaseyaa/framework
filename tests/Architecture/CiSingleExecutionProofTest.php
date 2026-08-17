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
    public function prepareTestPlanIsTheSingleComposerInstallAuthorityForTheRun(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/ci.yml');
        $prepareJob = $this->job($workflow, 'prepare-test-plan', 'ci-test-shards');

        // prepare-test-plan is the one authoritative `composer install` per
        // run: it installs for the exact head, tars vendor/ preserving
        // symlinks, records a SHA-256, and publishes a run-scoped artifact
        // that both the ci-test-shards matrix and the random-order shard
        // matrix consume instead of each running their own network install.
        // It is also the single highest-consequence install in the run
        // (round 2 review, item 1), so it is retry-wrapped like every other
        // install in this file, not a bare `run: composer install`.
        self::assertStringContainsString('uses: ./.github/actions/composer-install-retry', $prepareJob);
        self::assertStringContainsString("args: '--no-interaction --prefer-dist --no-progress'", $prepareJob);
        self::assertStringContainsString('tar --create --file build/ci/vendor.tar vendor', $prepareJob);
        self::assertStringContainsString('sha256sum build/ci/vendor.tar', $prepareJob);
        self::assertStringContainsString('installed.sha256', $prepareJob);
        self::assertStringContainsString('name: vendor-archive', $prepareJob);
    }

    #[Test]
    public function testShardsConsumeTheSharedVendorArchiveInsteadOfInstallingDirectly(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/ci.yml');
        $testShardsJob = $this->job($workflow, 'ci-test-shards', 'ci-unit-tests');

        self::assertStringContainsString('name: vendor-archive', $testShardsJob);
        self::assertStringContainsString('bin/verify-random-order-vendor-archive', $testShardsJob);
        self::assertStringContainsString('composer check-platform-reqs', $testShardsJob);
        // The locked install is the fallback safety net, not the primary
        // path — it must stay, guarded behind verification failure.
        self::assertStringContainsString('falling back to a locked install', $testShardsJob);
    }

    #[Test]
    public function randomOrderPreparesOnceAndFansOutToTwoShards(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/ci.yml');

        self::assertStringContainsString('prepare-random-order-plan:', $workflow);
        self::assertStringContainsString('php bin/build-phpunit-shards --shards=2', $workflow);
        // GitHub's needs.<matrix-job>.result aggregate can prove "every leg
        // that ran succeeded" but cannot express "there were exactly two
        // legs" — so this assertion on the matrix declaration itself is the
        // only guard against the shard count silently drifting from 2. Keep
        // it in sync with the aggregator's comment in ci.yml.
        self::assertStringContainsString('id: [1, 2]', $workflow);
        self::assertStringContainsString('bin/test-random-order --plan=', $workflow);

        // The shard matrix now also depends on the single install authority
        // (prepare-test-plan) to consume the shared vendor archive, in
        // addition to prepare-random-order-plan for the replay plan itself.
        $shardJob = $this->job($workflow, 'ci-random-order-shard', 'ci-random-order');
        self::assertStringContainsString('needs: [prepare-random-order-plan, prepare-test-plan]', $shardJob);
        self::assertStringContainsString('name: vendor-archive', $shardJob);
    }

    #[Test]
    public function prepareRandomOrderPlanNoLongerInstallsDependenciesItself(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/ci.yml');
        $planJob = $this->job($workflow, 'prepare-random-order-plan', 'ci-random-order-shard');

        // Single install authority: prepare-random-order-plan builds the
        // replay plan only. It no longer runs its own `composer install` —
        // that network call, and the vendor archive it produces, live
        // solely in prepare-test-plan now.
        self::assertStringNotContainsString('composer install', $planJob);
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
