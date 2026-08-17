<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
        $workflow = (string) file_get_contents($this->repoRoot . '/.github/workflows/ci.yml');

        self::assertStringContainsString('name: ci/random-order', $workflow);
        self::assertStringContainsString('GITHUB_RUN_ID', $workflow);
        self::assertStringContainsString('TEST_RANDOM_SEED', $workflow);
        self::assertStringContainsString('composer test:random', $workflow);
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
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->repoRoot,
        );
        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit_code' => proc_close($process),
            'output' => (string) $stdout . (string) $stderr,
        ];
    }
}
