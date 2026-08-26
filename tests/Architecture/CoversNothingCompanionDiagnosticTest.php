<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class CoversNothingCompanionDiagnosticTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/waaseyaa_covers_nothing_' . bin2hex(random_bytes(6));
        mkdir($this->fixture . '/packages/demo/src', 0o755, true);
        mkdir($this->fixture . '/packages/demo/tests/Unit', 0o755, true);
        mkdir($this->fixture . '/tests/Integration', 0o755, true);
        file_put_contents($this->fixture . '/packages/demo/src/Example.php', <<<'PHP'
            <?php
            namespace Waaseyaa\Demo;
            final class Example
            {
                public function answer(bool $allowed): int
                {
                    if (!$allowed) {
                        return 403;
                    }
                    return 200;
                }
            }
            PHP);
        file_put_contents($this->fixture . '/tests/Integration/ExampleFlowTest.php', <<<'PHP'
            <?php
            use PHPUnit\Framework\Attributes\CoversNothing;
            use Waaseyaa\Demo\Example;
            #[CoversNothing]
            final class ExampleFlowTest {}
            PHP);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->fixture);
    }

    #[Test]
    public function it_reproduces_the_covers_nothing_only_gap_from_pr_2408(): void
    {
        $result = $this->runDiagnostic();

        self::assertSame(1, $result['exit_code']);
        self::assertStringContainsString('packages/demo/src/Example.php changed executable line(s) 7, 8', $result['output']);
        self::assertStringContainsString('tests/Integration/ExampleFlowTest.php', $result['output']);
        self::assertStringContainsString('#[CoversClass(Example::class)]', $result['output']);
        self::assertStringContainsString('real public dispatcher/service boundary', $result['output']);
    }

    #[Test]
    public function a_legitimate_coverage_bearing_companion_satisfies_the_guard(): void
    {
        file_put_contents($this->fixture . '/packages/demo/tests/Unit/ExampleTest.php', <<<'PHP'
            <?php
            use PHPUnit\Framework\Attributes\CoversClass;
            use Waaseyaa\Demo\Example;
            #[CoversClass(Example::class)]
            final class ExampleTest {}
            PHP);

        $result = $this->runDiagnostic(includeCompanion: true);

        self::assertSame(0, $result['exit_code'], $result['output']);
        self::assertStringContainsString('coverage-bearing companion', $result['output']);
    }

    /** @return array{exit_code: int, output: string} */
    private function runDiagnostic(bool $includeCompanion = false): array
    {
        $diff = "diff --git a/packages/demo/src/Example.php b/packages/demo/src/Example.php\n"
            . "+++ b/packages/demo/src/Example.php\n@@ -6,0 +7,2 @@\n"
            . "diff --git a/tests/Integration/ExampleFlowTest.php b/tests/Integration/ExampleFlowTest.php\n"
            . "+++ b/tests/Integration/ExampleFlowTest.php\n@@ -0,0 +1,5 @@\n";
        if ($includeCompanion) {
            $diff .= "diff --git a/packages/demo/tests/Unit/ExampleTest.php b/packages/demo/tests/Unit/ExampleTest.php\n"
                . "+++ b/packages/demo/tests/Unit/ExampleTest.php\n@@ -0,0 +1,5 @@\n";
        }
        file_put_contents($this->fixture . '/change.diff', $diff);

        $process = new Process([
            PHP_BINARY,
            dirname(__DIR__, 2) . '/bin/check-covers-nothing-companions',
            '--root=' . $this->fixture,
            '--diff-file=' . $this->fixture . '/change.diff',
        ]);
        $exitCode = $process->run();
        return ['exit_code' => $exitCode, 'output' => $process->getOutput() . $process->getErrorOutput()];
    }
}
