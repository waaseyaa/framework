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

    #[Test]
    public function onlyRestrictsThenReexpandsToCompleteGroups(): void
    {
        $selection = $this->writeSelection(['packages/genealogy/tests/Unit/GenealogyContentAccessPolicyTest.php']);
        $plan = $this->plan(['--only=' . $selection]);

        self::assertSame('targeted', $plan['mode']);
        $paths = $this->allPaths($plan);
        foreach ($paths as $path) {
            self::assertStringStartsWith('packages/genealogy/', $path);
        }
        self::assertGreaterThan(1, count($paths), 'The planner re-expands to the complete group.');
    }

    #[Test]
    public function onlyRefusesAPathAbsentFromTheInventory(): void
    {
        $selection = $this->writeSelection(['packages/genealogy/tests/Unit/GhostTest.php']);
        $result = $this->runOnly(['--only=' . $selection]);

        self::assertSame(2, $result['exit']);
        self::assertStringContainsString('absent from the discovered inventory', $result['error']);
    }

    #[Test]
    public function everyPathResolvesToExactlyOneSuite(): void
    {
        $plan = $this->plan([]);
        foreach ($plan['include'] as $shard) {
            $fromSuites = [];
            foreach ($shard['suites'] as $paths) {
                array_push($fromSuites, ...$paths);
            }
            sort($fromSuites);
            $declared = $shard['paths'] === '' ? [] : explode("\n", $shard['paths']);
            sort($declared);
            self::assertSame($declared, $fromSuites, 'Suite partition must be total and disjoint.');
        }
    }

    #[Test]
    public function anEmptyShardIsDeclaredRatherThanDropped(): void
    {
        $selection = $this->writeSelection(['packages/genealogy/tests/Unit/GenealogyContentAccessPolicyTest.php']);
        $plan = $this->plan(['--only=' . $selection, '--shards=3']);

        self::assertCount(3, $plan['include'], 'Every matrix leg must be present.');
        $empty = array_values(array_filter($plan['include'], static fn(array $s): bool => $s['empty'] === true));
        self::assertNotSame([], $empty);
        foreach ($empty as $shard) {
            self::assertSame('', $shard['paths']);
            self::assertSame(0, $shard['test_files']);
        }
    }

    #[Test]
    public function thePlanRecordsItsProvenance(): void
    {
        $plan = $this->plan(['--seed=2241']);

        self::assertSame(2241, $plan['seed']);
        self::assertMatchesRegularExpression('/^\d+\.\d+/', $plan['phpunit_version']);
        self::assertSame(1, $plan['selection']['schema_version']);
        self::assertSame(
            'no selection document supplied to the planner',
            $plan['selection']['fallback_reason'],
            'The synthetic no-`--only` selection must never look like a real full decision with a blank reason.',
        );
    }

    #[Test]
    public function onlyRefusesAGarbledMode(): void
    {
        $selection = $this->fixtureRoot . '/garbled-mode-selection.json';
        file_put_contents($selection, json_encode([
            'schema_version' => 1,
            'mode' => 'sideways',
            'selected_paths' => [],
        ], JSON_THROW_ON_ERROR));

        $result = $this->runOnly(['--only=' . $selection]);

        self::assertSame(2, $result['exit']);
        self::assertStringContainsString('mode full or targeted', $result['error']);
    }

    #[Test]
    public function multiplyAssignedSuiteMembershipRefusesCleanly(): void
    {
        // Mirrors the live hazard docs/specs/ci-test-selection.md §5 warns
        // about: a whole-tree suite directory (like packages/analytics/tests
        // or packages/oauth-provider/tests) overlapping a narrower,
        // package-wildcard suite directory.
        file_put_contents($this->fixtureRoot . '/phpunit.xml.dist', <<<'XML'
            <?xml version="1.0"?>
            <phpunit><testsuites>
              <testsuite name="Unit"><directory>packages/demo/tests</directory></testsuite>
              <testsuite name="Integration"><directory>packages/*/tests/Integration</directory></testsuite>
            </testsuites></phpunit>
            XML);
        mkdir($this->fixtureRoot . '/packages/demo/tests/Integration', 0o777, true);
        file_put_contents($this->fixtureRoot . '/packages/demo/tests/Integration/OverlapTest.php', "<?php\n");

        $timings = $this->fixtureRoot . '/overlap-timings.json';
        file_put_contents($timings, json_encode(['schema_version' => 1, 'files' => []], JSON_THROW_ON_ERROR));

        $result = $this->runPlannerRaw([
            '--root=' . $this->fixtureRoot,
            '--timings=' . $timings,
        ]);

        self::assertSame(2, $result['exit']);
        self::assertStringContainsString('assigned to more than one suite', $result['error']);
        // Naming neither suite left the remedy unclear on this exact
        // pre-existing hard-failure path (prepare-test-plan, which gates
        // ci/unit-tests and ci/coverage). Both conflicting suite names must
        // be in the message.
        self::assertStringContainsString('Unit', $result['error']);
        self::assertStringContainsString('Integration', $result['error']);
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

    /**
     * Runs the planner with an arbitrary argument list and does not assert
     * on stderr — for fixture-root refusal tests that expect a non-zero
     * exit and a specific stderr message.
     *
     * @param list<string> $args
     * @return array{exit: int, output: string, error: string}
     */
    private function runPlannerRaw(array $args): array
    {
        $command = array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/bin/build-phpunit-shards'], $args);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return ['exit' => $exit, 'output' => (string) $output, 'error' => (string) $error];
    }

    /**
     * Runs the planner against the real repository root (not the fixture
     * root), so `--only` tests exercise the actual phpunit.xml.dist
     * inventory and the real random-order-scope library.
     *
     * @param list<string> $extraArgs
     * @return array{exit: int, output: string, error: string}
     */
    private function runOnly(array $extraArgs): array
    {
        $command = array_merge([
            PHP_BINARY,
            dirname(__DIR__, 2) . '/bin/build-phpunit-shards',
            '--timings=' . dirname(__DIR__, 2) . '/tools/phpunit-timings.json',
        ], $extraArgs);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return ['exit' => $exit, 'output' => (string) $output, 'error' => (string) $error];
    }

    /**
     * @param list<string> $extraArgs
     * @return array<string, mixed>
     */
    private function plan(array $extraArgs): array
    {
        $result = $this->runOnly($extraArgs);
        self::assertSame(0, $result['exit'], $result['error']);

        return json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $selectedPaths */
    private function writeSelection(array $selectedPaths): string
    {
        $path = $this->fixtureRoot . '/only-selection.json';
        file_put_contents($path, json_encode([
            'schema_version' => 1,
            'mode' => 'targeted',
            'selected_paths' => $selectedPaths,
        ], JSON_THROW_ON_ERROR));

        return $path;
    }

    /** @param array<string, mixed> $plan @return list<string> */
    private function allPaths(array $plan): array
    {
        $paths = [];
        foreach ($plan['include'] as $shard) {
            if ($shard['paths'] !== '') {
                array_push($paths, ...explode("\n", $shard['paths']));
            }
        }

        return $paths;
    }
}
