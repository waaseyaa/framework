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
    private const string BRANCH_SOURCE = <<<'PHP'
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
        PHP;

    private const string BASE_SOURCE = <<<'PHP'
        <?php
        namespace Waaseyaa\Demo;
        final class Example
        {
            public function answer(bool $allowed): int
            {
                return 200;
            }
        }
        PHP;

    private string $fixture;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/bin/lib/repository-files.php';
        $this->fixture = sys_get_temp_dir() . '/waaseyaa_covers_nothing_' . bin2hex(random_bytes(6));
        mkdir($this->fixture . '/packages/demo/src', 0o755, true);
        mkdir($this->fixture . '/packages/demo/tests/Unit', 0o755, true);
        mkdir($this->fixture . '/tests/Integration', 0o755, true);
        file_put_contents($this->fixture . '/packages/demo/src/Example.php', self::BRANCH_SOURCE);
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
        $filesystem = new Filesystem();
        // Git writes its objects read-only; Windows refuses to unlink those.
        $filesystem->chmod($this->fixture, 0o755, 0o000, true);
        $filesystem->remove($this->fixture);
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
        $this->writeCompanion();

        $result = $this->runDiagnostic(includeCompanion: true);

        self::assertSame(0, $result['exit_code'], $result['output']);
        self::assertStringContainsString('coverage-bearing companion', $result['output']);
    }

    #[Test]
    public function it_reproduces_the_gap_from_the_repository_git_diff_on_this_host(): void
    {
        $base = $this->commitHistory(includeCompanion: false);

        $result = $this->runGate(['--base=' . $base]);

        self::assertSame(1, $result['exit_code'], $result['output']);
        self::assertStringContainsString('packages/demo/src/Example.php changed executable line(s) 7, 8', $result['output']);
        self::assertStringContainsString('tests/Integration/ExampleFlowTest.php', $result['output']);
    }

    #[Test]
    public function a_companion_in_the_repository_git_diff_satisfies_the_guard_on_this_host(): void
    {
        $base = $this->commitHistory(includeCompanion: true);

        $result = $this->runGate(['--base=' . $base]);

        self::assertSame(0, $result['exit_code'], $result['output']);
        self::assertStringContainsString('coverage-bearing companion', $result['output']);
    }

    #[Test]
    public function an_unresolvable_base_fails_closed_with_the_git_error(): void
    {
        $this->commitHistory(includeCompanion: true);

        $result = $this->runGate(['--base=refs/heads/covers-nothing-missing-base']);

        self::assertSame(2, $result['exit_code'], $result['output']);
        self::assertStringContainsString('covers-nothing-missing-base', $result['output']);
        self::assertStringNotContainsString('no changed source/test pair', $result['output']);
    }

    #[Test]
    public function a_selected_git_executable_that_cannot_start_fails_closed(): void
    {
        $base = $this->commitHistory(includeCompanion: true);

        $result = $this->runGate(['--base=' . $base], ['WAASEYAA_SYSTEM_GIT' => $this->fixture . '/missing-git']);

        self::assertSame(2, $result['exit_code'], $result['output']);
        self::assertStringNotContainsString('coverage-bearing companion', $result['output']);
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

        return $this->runGate(['--diff-file=' . $this->fixture . '/change.diff']);
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $environment
     * @return array{exit_code: int, output: string}
     */
    private function runGate(array $arguments, array $environment = []): array
    {
        $process = new Process(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/check-covers-nothing-companions', '--root=' . $this->fixture, ...$arguments],
            $this->fixture,
            $environment + array_fill_keys(REPOSITORY_LOCAL_GIT_ENVIRONMENT, false),
        );
        $exitCode = $process->run();

        return ['exit_code' => $exitCode, 'output' => $process->getOutput() . $process->getErrorOutput()];
    }

    /**
     * Commits the base source, then the branch change (and optionally its
     * companion), and returns the base commit the gate diffs against.
     */
    private function commitHistory(bool $includeCompanion): string
    {
        // On POSIX hosts the gate starts the repository adapter under --root.
        mkdir($this->fixture . '/bin', 0o755, true);
        copy(dirname(__DIR__, 2) . '/bin/git', $this->fixture . '/bin/git');
        chmod($this->fixture . '/bin/git', 0o755);

        $this->git('init', '--quiet', '--initial-branch=main');
        foreach (['user.name' => 'Fixture', 'user.email' => 'fixture@example.invalid', 'commit.gpgsign' => 'false', 'core.autocrlf' => 'false'] as $key => $value) {
            $this->git('config', $key, $value);
        }
        file_put_contents($this->fixture . '/packages/demo/src/Example.php', self::BASE_SOURCE);
        $this->git('add', '--', 'packages/demo/src/Example.php');
        $this->git('commit', '--quiet', '-m', 'base');
        $base = trim($this->git('rev-parse', 'HEAD'));

        file_put_contents($this->fixture . '/packages/demo/src/Example.php', self::BRANCH_SOURCE);
        if ($includeCompanion) {
            $this->writeCompanion();
        }
        $this->git('add', '--', 'packages', 'tests');
        $this->git('commit', '--quiet', '-m', 'change');

        return $base;
    }

    private function writeCompanion(): void
    {
        file_put_contents($this->fixture . '/packages/demo/tests/Unit/ExampleTest.php', <<<'PHP'
            <?php
            use PHPUnit\Framework\Attributes\CoversClass;
            use Waaseyaa\Demo\Example;
            #[CoversClass(Example::class)]
            final class ExampleTest {}
            PHP);
    }

    private function git(string ...$arguments): string
    {
        $process = new Process(['git', '-C', $this->fixture, ...$arguments], null, array_fill_keys(REPOSITORY_LOCAL_GIT_ENVIRONMENT, false));
        $process->mustRun();

        return $process->getOutput();
    }
}
