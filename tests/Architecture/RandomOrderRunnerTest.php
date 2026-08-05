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
