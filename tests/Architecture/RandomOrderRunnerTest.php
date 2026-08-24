<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class RandomOrderRunnerTest extends TestCase
{
    private string $repoRoot = '';

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    #[Test]
    public function it_rejects_an_invalid_replay_seed(): void
    {
        $result = $this->runCommand(['--seed=not-an-integer', '--list-suites']);

        self::assertSame(2, $result['exit_code']);
        self::assertStringContainsString('positive integer', $result['output']);
    }

    #[Test]
    public function it_logs_a_fixed_seed_and_forwards_phpunit_arguments(): void
    {
        $result = $this->runCommand(['--seed=2241', '--list-suites']);

        self::assertSame(0, $result['exit_code'], $result['output']);
        self::assertStringContainsString('PHPUnit random-order seed: 2241', $result['output']);
        self::assertStringContainsString('TEST_RANDOM_SEED=2241 composer test:random', $result['output']);
        self::assertStringContainsString('Available test suite', $result['output']);

        $runner = (string) file_get_contents($this->repoRoot . '/bin/test-random-order');
        self::assertStringContainsString("putenv('PATH=' . dirname(PHP_BINARY)", $runner);
        self::assertStringContainsString("['Unit', 'Integration', 'Architecture']", $runner);
        self::assertStringContainsString("\$command[] = '--testsuite';", $runner);
        self::assertStringContainsString('PHPUnit random-order suite:', $runner);
    }

    #[Test]
    public function ci_derives_and_logs_a_replayable_seed_for_a_dedicated_lane(): void
    {
        // The seed is now derived once in prepare-random-order-plan and baked
        // into the plan document (Task 7, #2404 slice 3+); shards replay it
        // via bin/test-random-order --plan=… rather than TEST_RANDOM_SEED +
        // composer test:random.
        $workflow = (string) file_get_contents($this->repoRoot . '/.github/workflows/ci.yml');

        self::assertStringContainsString('name: ci/random-order', $workflow);
        self::assertStringContainsString('GITHUB_RUN_ID', $workflow);
        self::assertStringContainsString('--seed="$seed"', $workflow);
        self::assertStringContainsString('bin/test-random-order --plan=', $workflow);
    }

    #[Test]
    public function it_replays_a_saved_plan_shard_per_suite(): void
    {
        $plan = $this->writePlan(3, 2241, [
            1 => ['Unit' => ['packages/genealogy/tests/Unit/OneTest.php'], 'Architecture' => ['tests/Architecture/CiContractOrderingTest.php']],
            2 => [],
            3 => [],
        ]);

        $result = $this->runCommand(['--plan=' . $plan, '--shard=1', '--list-suites']);

        self::assertSame(0, $result['exit_code'], $result['output']);
        self::assertStringContainsString('PHPUnit random-order seed: 2241', $result['output']);
        self::assertStringContainsString('PHPUnit random-order suite: Unit', $result['output']);
        self::assertStringContainsString('PHPUnit random-order suite: Architecture', $result['output']);
    }

    #[Test]
    public function an_empty_shard_succeeds_without_invoking_phpunit(): void
    {
        $plan = $this->writePlan(3, 2241, [1 => ['Unit' => ['packages/genealogy/tests/Unit/OneTest.php']], 2 => [], 3 => []]);

        $result = $this->runCommand(['--plan=' . $plan, '--shard=2']);

        self::assertSame(0, $result['exit_code'], $result['output']);
        self::assertStringContainsString('shard 2 is empty', $result['output']);
        self::assertStringNotContainsString('Available test suite', $result['output']);
    }

    #[Test]
    public function an_unknown_shard_is_rejected(): void
    {
        $plan = $this->writePlan(3, 2241, [1 => [], 2 => [], 3 => []]);

        $result = $this->runCommand(['--plan=' . $plan, '--shard=9']);

        self::assertSame(2, $result['exit_code']);
        self::assertStringContainsString('absent from the exact plan', $result['output']);
    }

    #[Test]
    public function a_malformed_plan_is_rejected(): void
    {
        $path = sys_get_temp_dir() . '/waaseyaa_test_' . uniqid('plan', true) . '.json';
        file_put_contents($path, '{ not json');

        $result = $this->runCommand(['--plan=' . $path, '--shard=1']);

        self::assertSame(2, $result['exit_code']);
        self::assertStringContainsString('plan', $result['output']);
    }

    #[Test]
    public function it_forwards_the_shards_explicit_paths_to_phpunit(): void
    {
        $plan = $this->writePlan(1, 2241, [
            1 => ['Architecture' => ['tests/Architecture/CiContractOrderingTest.php']],
        ]);

        // --testdox prints the running class's FQCN, which is what makes
        // "the forwarded file actually ran" and "the fallback did not run
        // the whole suite" independently checkable, without coupling this
        // test to CiContractOrderingTest's exact test/assertion counts (a
        // file this very branch edits).
        $result = $this->runCommand(['--plan=' . $plan, '--shard=1', '--testdox']);

        self::assertSame(0, $result['exit_code'], $result['output']);
        self::assertStringContainsString(
            'Waaseyaa\Tests\Architecture\CiContractOrdering',
            $result['output'],
            'The forwarded file must actually have run.',
        );
        // If the shard's explicit paths stop being forwarded, PHPUnit falls
        // back to the complete configured Architecture suite, which would
        // additionally run PhpUnitShardPlannerTest (and every other
        // Architecture file) — its presence is the regression signal.
        self::assertStringNotContainsString(
            'Waaseyaa\Tests\Architecture\PhpUnitShardPlanner',
            $result['output'],
            'Only the forwarded file may run; the fallback-to-full-suite regression would pull in other files too.',
        );
    }

    #[Test]
    public function plan_mode_prints_a_plan_scoped_replay_hint_instead_of_the_composer_shortcut(): void
    {
        $plan = $this->writePlan(1, 2241, [
            1 => ['Architecture' => ['tests/Architecture/CiContractOrderingTest.php']],
        ]);

        $result = $this->runCommand(['--plan=' . $plan, '--shard=1']);

        self::assertSame(0, $result['exit_code'], $result['output']);
        self::assertStringContainsString("Replay: bin/test-random-order --plan={$plan} --shard=1", $result['output']);
        self::assertStringNotContainsString('composer test:random', $result['output']);
    }

    #[Test]
    public function a_shard_missing_suites_is_rejected_not_silently_run_unsharded(): void
    {
        $path = sys_get_temp_dir() . '/waaseyaa_test_' . uniqid('plan', true) . '.json';
        file_put_contents($path, json_encode([
            'schema_version' => 1,
            'mode' => 'targeted',
            'seed' => 2241,
            'include' => [
                ['id' => 1, 'paths' => '', 'empty' => false, 'test_files' => 0, 'expected_seconds' => 0.0, 'fallback_files' => 0],
            ],
        ], JSON_THROW_ON_ERROR));

        $result = $this->runCommand(['--plan=' . $path, '--shard=1']);

        self::assertSame(2, $result['exit_code'], $result['output']);
        self::assertStringContainsString('suites', $result['output']);
        // The regression this guards against: falling through to the
        // unsharded default loop, which lists (or runs) the entire
        // Unit/Integration/Architecture inventory and exits 0.
        self::assertStringNotContainsString('Available test suite', $result['output']);
    }

    #[Test]
    public function a_shard_with_a_malformed_suite_entry_is_rejected(): void
    {
        $path = sys_get_temp_dir() . '/waaseyaa_test_' . uniqid('plan', true) . '.json';
        file_put_contents($path, json_encode([
            'schema_version' => 1,
            'mode' => 'targeted',
            'seed' => 2241,
            'include' => [
                ['id' => 1, 'paths' => '', 'suites' => ['Unit' => []], 'empty' => false, 'test_files' => 0, 'expected_seconds' => 0.0, 'fallback_files' => 0],
            ],
        ], JSON_THROW_ON_ERROR));

        $result = $this->runCommand(['--plan=' . $path, '--shard=1']);

        self::assertSame(2, $result['exit_code'], $result['output']);
        self::assertStringContainsString('malformed suite entry', $result['output']);
    }

    #[Test]
    public function a_shard_declared_empty_with_a_nonempty_suites_map_is_rejected(): void
    {
        // Guards against trusting "empty" without cross-checking "suites":
        // a plan that says a shard is empty while still carrying real
        // suite/path data must fail closed, not silently skip the shard's
        // actual tests.
        $path = sys_get_temp_dir() . '/waaseyaa_test_' . uniqid('plan', true) . '.json';
        file_put_contents($path, json_encode([
            'schema_version' => 1,
            'mode' => 'targeted',
            'seed' => 2241,
            'include' => [
                [
                    'id' => 1,
                    'paths' => 'packages/genealogy/tests/Unit/OneTest.php',
                    'suites' => ['Unit' => ['packages/genealogy/tests/Unit/OneTest.php']],
                    'empty' => true,
                    'test_files' => 1,
                    'expected_seconds' => 0.0,
                    'fallback_files' => 0,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $result = $this->runCommand(['--plan=' . $path, '--shard=1']);

        self::assertSame(2, $result['exit_code'], $result['output']);
        self::assertStringContainsString('inconsistent', $result['output']);
        self::assertStringNotContainsString('no PHPUnit process is required', $result['output']);
    }

    #[Test]
    public function a_plan_with_a_null_seed_is_rejected(): void
    {
        $path = sys_get_temp_dir() . '/waaseyaa_test_' . uniqid('plan', true) . '.json';
        file_put_contents($path, json_encode([
            'schema_version' => 1,
            'mode' => 'targeted',
            'seed' => null,
            'include' => [
                ['id' => 1, 'paths' => '', 'suites' => [], 'empty' => true, 'test_files' => 0, 'expected_seconds' => 0.0, 'fallback_files' => 0],
            ],
        ], JSON_THROW_ON_ERROR));

        $result = $this->runCommand(['--plan=' . $path, '--shard=1']);

        self::assertSame(2, $result['exit_code'], $result['output']);
        self::assertStringContainsString('no seed', $result['output']);
    }

    #[Test]
    public function a_plan_with_a_non_numeric_seed_is_rejected(): void
    {
        $path = sys_get_temp_dir() . '/waaseyaa_test_' . uniqid('plan', true) . '.json';
        file_put_contents($path, json_encode([
            'schema_version' => 1,
            'mode' => 'targeted',
            'seed' => 'not-an-integer',
            'include' => [
                ['id' => 1, 'paths' => '', 'suites' => [], 'empty' => true, 'test_files' => 0, 'expected_seconds' => 0.0, 'fallback_files' => 0],
            ],
        ], JSON_THROW_ON_ERROR));

        $result = $this->runCommand(['--plan=' . $path, '--shard=1']);

        self::assertSame(2, $result['exit_code'], $result['output']);
        self::assertStringContainsString('positive integer', $result['output']);
    }

    #[Test]
    public function a_missing_plan_file_is_rejected_cleanly(): void
    {
        $path = sys_get_temp_dir() . '/waaseyaa_test_' . uniqid('plan', true) . '.json';

        $result = $this->runCommand(['--plan=' . $path, '--shard=1']);

        self::assertSame(2, $result['exit_code'], $result['output']);
        self::assertStringContainsString('missing or unreadable', $result['output']);
        self::assertStringNotContainsString('Warning', $result['output']);
        self::assertStringNotContainsString('failed to open stream', $result['output']);
    }

    /** @param array<int, array<string, list<string>>> $shards */
    private function writePlan(int $count, int $seed, array $shards): string
    {
        $include = [];
        for ($id = 1; $id <= $count; ++$id) {
            $suites = $shards[$id] ?? [];
            $paths = [];
            foreach ($suites as $suitePaths) {
                array_push($paths, ...$suitePaths);
            }
            $include[] = [
                'id' => $id,
                'paths' => implode("\n", $paths),
                'suites' => $suites,
                'empty' => $paths === [],
                'test_files' => count($paths),
                'expected_seconds' => 0.0,
                'fallback_files' => 0,
            ];
        }
        $path = sys_get_temp_dir() . '/waaseyaa_test_' . uniqid('plan', true) . '.json';
        file_put_contents($path, json_encode(
            ['schema_version' => 1, 'mode' => 'targeted', 'seed' => $seed, 'include' => $include],
            JSON_THROW_ON_ERROR,
        ));

        return $path;
    }

    /** @param list<string> $arguments
     *  @return array{exit_code: int, output: string}
     */
    private function runCommand(array $arguments): array
    {
        $command = [PHP_BINARY, $this->repoRoot . '/bin/test-random-order', ...$arguments];
        // proc_open drained stdout to EOF before reading stderr, so a runner
        // that fills the ~64KB stderr buffer wedged both sides (#2491). No env
        // argument was passed, so the child inherited the parent environment:
        // null, never []. timeout null because this runner was never
        // time-bounded and legitimately exceeds Symfony's 60-second default.
        $process = new Process($command, $this->repoRoot, null, null, null);
        $exit = $process->run();

        return [
            'exit_code' => $exit,
            'output' => $process->getOutput() . $process->getErrorOutput(),
        ];
    }
}
