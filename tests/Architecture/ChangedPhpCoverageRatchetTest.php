<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/summarize-php-coverage', $path],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);

        $summary = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(75, $summary['overall']['line_percentage']);
        self::assertSame(50, $summary['packages']['access']['line_percentage']);
        self::assertSame(100, $summary['packages']['mcp']['line_percentage']);
    }

    /** @param array<int, int> $counts
     *  @return array{exit_code: int, output: string}
     */
    private function runRatchet(array $counts, int $threshold): array
    {
        $diff = "diff --git a/packages/demo/src/Example.php b/packages/demo/src/Example.php\n"
            . "+++ b/packages/demo/src/Example.php\n@@ -1,5 +1,5 @@\n";
        file_put_contents($this->directory . '/change.diff', $diff);

        $lines = '';
        foreach ($counts as $number => $count) {
            $lines .= sprintf('<line num="%d" type="stmt" count="%d"/>', $number, $count);
        }
        file_put_contents(
            $this->directory . '/clover.xml',
            '<?xml version="1.0"?><coverage><project><file name="packages/demo/src/Example.php">'
            . $lines . '</file></project></coverage>',
        );

        $command = [
            PHP_BINARY,
            dirname(__DIR__, 2) . '/bin/check-changed-php-coverage',
            '--clover=' . $this->directory . '/clover.xml',
            '--diff-file=' . $this->directory . '/change.diff',
            '--threshold=' . $threshold,
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit_code' => proc_close($process), 'output' => $output];
    }
}
