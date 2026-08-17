<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PhpUnitShardPlannerTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        $this->fixtureRoot = sys_get_temp_dir() . '/waaseyaa-shards-' . bin2hex(random_bytes(6));
        mkdir($this->fixtureRoot . '/packages/demo/tests/Unit', 0o777, true);
        mkdir($this->fixtureRoot . '/packages/other/tests/Unit', 0o777, true);
        file_put_contents($this->fixtureRoot . '/phpunit.xml.dist', <<<'XML'
            <?xml version="1.0"?>
            <phpunit><testsuites><testsuite name="Unit"><directory>packages/*/tests/Unit</directory></testsuite></testsuites></phpunit>
            XML);
        foreach (['Alpha', 'Delta'] as $name) {
            file_put_contents($this->fixtureRoot . "/packages/demo/tests/Unit/{$name}Test.php", "<?php\n");
        }
        foreach (['Beta', 'Gamma'] as $name) {
            file_put_contents($this->fixtureRoot . "/packages/other/tests/Unit/{$name}Test.php", "<?php\n");
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->fixtureRoot) || !is_dir($this->fixtureRoot)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->fixtureRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->fixtureRoot);
    }

    #[Test]
    public function itBuildsDeterministicTimingBalancedShardsWithoutDuplication(): void
    {
        $timings = $this->fixtureRoot . '/timings.json';
        file_put_contents($timings, json_encode([
            'schema_version' => 1,
            'source' => ['run_id' => 'fixture', 'head_sha' => str_repeat('a', 40)],
            'files' => [
                'packages/demo/tests/Unit/AlphaTest.php' => 10,
                'packages/other/tests/Unit/BetaTest.php' => 9,
                'packages/other/tests/Unit/GammaTest.php' => 2,
                'packages/demo/tests/Unit/DeltaTest.php' => 1,
            ],
        ], JSON_THROW_ON_ERROR));

        $first = $this->runPlanner($timings, 2);
        $second = $this->runPlanner($timings, 2);

        self::assertSame(0, $first['exit']);
        self::assertSame($first['output'], $second['output']);
        $plan = json_decode($first['output'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(4, $plan['test_files']);
        self::assertSame(0, $plan['fallback_files']);
        self::assertSame([11, 11], array_column($plan['include'], 'expected_seconds'));
        $paths = [];
        foreach ($plan['include'] as $shard) {
            array_push($paths, ...explode("\n", $shard['paths']));
        }
        sort($paths);
        self::assertSame([
            'packages/demo/tests/Unit/AlphaTest.php',
            'packages/demo/tests/Unit/DeltaTest.php',
            'packages/other/tests/Unit/BetaTest.php',
            'packages/other/tests/Unit/GammaTest.php',
        ], $paths);
    }

    #[Test]
    public function missingTimingEvidenceUsesADeterministicMedianFallback(): void
    {
        $timings = $this->fixtureRoot . '/timings.json';
        file_put_contents($timings, json_encode([
            'schema_version' => 1,
            'files' => [
                'packages/demo/tests/Unit/AlphaTest.php' => 4,
                'packages/other/tests/Unit/BetaTest.php' => 2,
            ],
        ], JSON_THROW_ON_ERROR));

        $result = $this->runPlanner($timings, 2);
        $plan = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $result['exit']);
        self::assertSame(4, $plan['fallback_seconds']);
        self::assertSame(2, $plan['fallback_files']);
    }

    #[Test]
    public function timingEvidenceIsRefreshedFromRetainedJunitByRepositoryFile(): void
    {
        $junit = $this->fixtureRoot . '/junit.xml';
        file_put_contents($junit, <<<'XML'
            <?xml version="1.0"?>
            <testsuites><testsuite>
              <testcase file="/checkout/packages/demo/tests/Unit/AlphaTest.php" time="1.25" />
              <testcase file="/checkout/packages/demo/tests/Unit/AlphaTest.php" time="0.75" />
              <testcase file="/checkout/packages/other/tests/Unit/BetaTest.php" time="3" />
            </testsuite></testsuites>
            XML);
        $output = $this->fixtureRoot . '/refreshed.json';
        $command = [
            PHP_BINARY,
            dirname(__DIR__, 2) . '/bin/refresh-phpunit-timings',
            '--output=' . $output,
            '--run-id=fixture-run',
            '--head-sha=' . str_repeat('b', 40),
            $junit,
        ];
        exec(implode(' ', array_map('escapeshellarg', $command)), $messages, $exit);

        self::assertSame(0, $exit, implode("\n", $messages));
        $document = json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('fixture-run', $document['source']['run_id']);
        self::assertSame([
            'packages/demo/tests/Unit/AlphaTest.php' => 2,
            'packages/other/tests/Unit/BetaTest.php' => 3,
        ], $document['files']);
    }

    /** @return array{exit: int, output: string} */
    private function runPlanner(string $timings, int $shards): array
    {
        $command = [
            PHP_BINARY,
            dirname(__DIR__, 2) . '/bin/build-phpunit-shards',
            '--root=' . $this->fixtureRoot,
            '--timings=' . $timings,
            '--shards=' . $shards,
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        self::assertSame('', $error);

        return ['exit' => $exit, 'output' => (string) $output];
    }
}
