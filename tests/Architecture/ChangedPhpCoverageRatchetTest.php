<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class ChangedPhpCoverageRatchetTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/waaseyaa_changed_coverage_' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    #[Test]
    public function it_passes_when_the_changed_executable_line_ratchet_is_met(): void
    {
        $result = $this->runRatchet([1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 0], 80);

        self::assertSame(0, $result['exit_code'], $result['output']);
        self::assertStringContainsString('4/5 executable changed lines covered (80.00%', $result['output']);
    }

    #[Test]
    public function it_fails_when_changed_executable_coverage_regresses(): void
    {
        $result = $this->runRatchet([1 => 1, 2 => 1, 3 => 1, 4 => 0, 5 => 0], 80);

        self::assertSame(1, $result['exit_code']);
        self::assertStringContainsString('60.00%', $result['output']);
    }

    #[Test]
    public function non_source_hunks_do_not_inherit_the_previous_source_path(): void
    {
        $diff = "diff --git a/packages/demo/src/Example.php b/packages/demo/src/Example.php\n"
            . "+++ b/packages/demo/src/Example.php\n@@ -1 +1 @@\n"
            . "diff --git a/packages/demo/tests/ExampleTest.php b/packages/demo/tests/ExampleTest.php\n"
            . "+++ b/packages/demo/tests/ExampleTest.php\n@@ -5 +5 @@\n"
            . "diff --git a/docs/example.md b/docs/example.md\n"
            . "+++ b/docs/example.md\n@@ -9 +9 @@\n";

        $result = $this->runRatchetFixture($diff, [
            'packages/demo/src/Example.php' => [1 => 1, 5 => 0, 9 => 0],
        ], 100);

        self::assertSame(0, $result['exit_code'], $result['output']);
        self::assertStringContainsString('1/1 executable changed lines covered (100.00%', $result['output']);
    }

    #[Test]
    public function a_deleted_file_does_not_inherit_the_previous_source_path(): void
    {
        $diff = "diff --git a/packages/demo/src/Example.php b/packages/demo/src/Example.php\n"
            . "+++ b/packages/demo/src/Example.php\n@@ -1 +1 @@\n"
            . "diff --git a/packages/demo/tests/DeletedTest.php b/packages/demo/tests/DeletedTest.php\n"
            . "+++ /dev/null\n@@ -5 +0,0 @@\n"
            . "diff --git a/docs/after-deletion.md b/docs/after-deletion.md\n"
            . "+++ b/docs/after-deletion.md\n@@ -5 +5 @@\n";

        $result = $this->runRatchetFixture($diff, [
            'packages/demo/src/Example.php' => [1 => 1, 5 => 0],
        ], 100);

        self::assertSame(0, $result['exit_code'], $result['output']);
        self::assertStringContainsString('1/1 executable changed lines covered (100.00%', $result['output']);
    }

    #[Test]
    public function multiple_source_files_are_counted_without_non_source_contamination(): void
    {
        $diff = "diff --git a/docs/preamble.md b/docs/preamble.md\n"
            . "+++ b/docs/preamble.md\n@@ -7 +7 @@\n"
            . "diff --git a/packages/demo/src/First.php b/packages/demo/src/First.php\n"
            . "+++ b/packages/demo/src/First.php\n@@ -1 +1 @@\n"
            . "diff --git a/packages/demo/tests/FirstTest.php b/packages/demo/tests/FirstTest.php\n"
            . "+++ b/packages/demo/tests/FirstTest.php\n@@ -8 +8 @@\n"
            . "diff --git a/packages/demo/src/Second.php b/packages/demo/src/Second.php\n"
            . "+++ b/packages/demo/src/Second.php\n@@ -2 +2 @@\n";

        $result = $this->runRatchetFixture($diff, [
            'packages/demo/src/First.php' => [1 => 1, 8 => 0],
            'packages/demo/src/Second.php' => [2 => 1],
        ], 100);

        self::assertSame(0, $result['exit_code'], $result['output']);
        self::assertStringContainsString('2/2 executable changed lines covered (100.00%', $result['output']);
    }

    #[Test]
    public function it_reports_package_and_overall_line_baselines(): void
    {
        $clover = '<?xml version="1.0"?><coverage><project>'
            . '<file name="/checkout/packages/access/src/Gate.php">'
            . '<line num="1" type="stmt" count="1"/><line num="2" type="stmt" count="0"/>'
            . '</file><file name="/checkout/packages/mcp/src/Server.php">'
            . '<line num="1" type="stmt" count="1"/><line num="2" type="stmt" count="1"/>'
            . '</file></project></coverage>';
        $path = $this->directory . '/summary-clover.xml';
        file_put_contents($path, $clover);

        // cwd inherited (proc_open received no cwd) and env inherited (no env
        // argument), so both stay null; timeout null keeps the call unbounded.
        $process = new Process(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/summarize-php-coverage', $path],
            null,
            null,
            null,
            null,
        );
        $exitCode = $process->run();
        $output = $process->getOutput();
        $error = $process->getErrorOutput();
        self::assertSame(0, $exitCode, $error);

        $summary = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(75, $summary['overall']['line_percentage']);
        self::assertSame(50, $summary['packages']['access']['line_percentage']);
        self::assertSame(100, $summary['packages']['mcp']['line_percentage']);
    }

    #[Test]
    public function it_enforces_overall_and_critical_package_floors(): void
    {
        $summary = [
            'schema_version' => 1,
            'overall' => ['executable_lines' => 10, 'covered_lines' => 8, 'line_percentage' => 80],
            'packages' => [
                'access' => ['executable_lines' => 5, 'covered_lines' => 4, 'line_percentage' => 80],
            ],
        ];
        $baseline = [
            'schema_version' => 1,
            'overall' => ['line_percentage_floor' => 80],
            'critical_packages' => ['access' => ['line_percentage_floor' => 80]],
        ];
        file_put_contents($this->directory . '/summary.json', json_encode($summary, JSON_THROW_ON_ERROR));
        file_put_contents($this->directory . '/baseline.json', json_encode($baseline, JSON_THROW_ON_ERROR));

        $passing = $this->runProcess([
            PHP_BINARY,
            dirname(__DIR__, 2) . '/bin/check-php-coverage-baseline',
            $this->directory . '/summary.json',
            $this->directory . '/baseline.json',
        ]);
        self::assertSame(0, $passing['exit_code'], $passing['output']);
        self::assertStringContainsString('package access 4/5 lines (80.00%', $passing['output']);

        $summary['packages']['access']['covered_lines'] = 3;
        file_put_contents($this->directory . '/summary.json', json_encode($summary, JSON_THROW_ON_ERROR));
        $failing = $this->runProcess([
            PHP_BINARY,
            dirname(__DIR__, 2) . '/bin/check-php-coverage-baseline',
            $this->directory . '/summary.json',
            $this->directory . '/baseline.json',
        ]);
        self::assertSame(1, $failing['exit_code']);
        self::assertStringContainsString('60.00%', $failing['output']);
    }

    /** @param array<int, int> $counts
     *  @return array{exit_code: int, output: string}
     */
    private function runRatchet(array $counts, int $threshold): array
    {
        $diff = "diff --git a/packages/demo/src/Example.php b/packages/demo/src/Example.php\n"
            . "+++ b/packages/demo/src/Example.php\n@@ -1,5 +1,5 @@\n";

        return $this->runRatchetFixture($diff, ['packages/demo/src/Example.php' => $counts], $threshold);
    }

    /**
     * @param array<string, array<int, int>> $files
     * @return array{exit_code: int, output: string}
     */
    private function runRatchetFixture(string $diff, array $files, int $threshold): array
    {
        file_put_contents($this->directory . '/change.diff', $diff);

        $cloverFiles = '';
        foreach ($files as $path => $counts) {
            $lines = '';
            foreach ($counts as $number => $count) {
                $lines .= sprintf('<line num="%d" type="stmt" count="%d"/>', $number, $count);
            }
            $cloverFiles .= sprintf('<file name="%s">%s</file>', $path, $lines);
        }
        file_put_contents(
            $this->directory . '/clover.xml',
            '<?xml version="1.0"?><coverage><project>' . $cloverFiles . '</project></coverage>',
        );

        $command = [
            PHP_BINARY,
            dirname(__DIR__, 2) . '/bin/check-changed-php-coverage',
            '--clover=' . $this->directory . '/clover.xml',
            '--diff-file=' . $this->directory . '/change.diff',
            '--threshold=' . $threshold,
        ];
        return $this->runProcess($command);
    }

    /** @param list<string> $command
     *  @return array{exit_code: int, output: string}
     */
    private function runProcess(array $command): array
    {
        // Inherited cwd and environment (proc_open was given neither); timeout
        // null preserves the previously unbounded call.
        $process = new Process($command, null, null, null, null);
        $exitCode = $process->run();

        return [
            'exit_code' => $exitCode,
            'output' => $process->getOutput() . $process->getErrorOutput(),
        ];
    }
}
