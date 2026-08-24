<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Locks #2491's subprocess acceptance criterion: "test-only subprocess runners
 * converted to symfony/process; the deadlock-prone sequential-drain shape no
 * longer appears in any test harness."
 *
 * The defect the criterion targets: stdout and stderr opened as blocking pipes
 * and drained sequentially — stream_get_contents($pipes[1]) run to EOF before
 * $pipes[2] is read — so a child that fills the ~64KB stderr buffer wedges both
 * sides and the suite hangs until the CI job is killed.
 *
 * Every remaining proc_open() call in test and benchmark scope must therefore be
 * on the allowlist below WITH a rationale. A new one fails this test, which is
 * the point: the fix is cheap to apply and expensive to rediscover.
 *
 * Production proc_open() call sites under packages/*&#47;src are deliberately out of
 * scope — converting them would promote symfony/process from a dev dependency to
 * a runtime require of waaseyaa/cli and therefore of every consumer installing
 * core, cms, or full.
 */
#[CoversNothing]
final class SubprocessHarnessContractTest extends TestCase
{
    /**
     * The only proc_open() call sites permitted in test/benchmark scope, each
     * with the reason it is safe as written.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [
        'benchmarks/BenchmarkProcessRunner.php' =>
            'Already the fix, and frozen. Sets both pipes non-blocking, multiplexes with stream_select(), '
            . 'drains per descriptor incrementally and closes each on EOF, so neither stream can wedge the '
            . 'other. Converting it is also infeasible: its sha256 is pinned in '
            . 'tests/Integration/FieldReadPagePerformance/fixture-manifest.json and re-checked at benchmark '
            . 'runtime by assertFrozenHarness(), and benchmarks/field-read-pages.php loads no '
            . 'vendor/autoload.php, so a Symfony import would not resolve. Proven at 256KiB on both streams '
            . 'by PagePerformanceHarnessContractTest::benchmark_subprocess_drains_large_stdout_and_stderr_without_deadlock().',
        'tests/Integration/FieldReadPagePerformance/PagePerformanceHarnessContractTest.php' =>
            'Already safe, and deliberately independent. Both pipes are non-blocking and both are drained on '
            . 'every iteration of one loop, under a hard 3.0s deadline that escalates SIGTERM to SIGKILL. It '
            . 'is the collector that runs the deadlock probe for BenchmarkProcessRunner, so keeping it off '
            . 'symfony/process keeps the measurement from becoming self-referential. Proven at 256KiB on both '
            . 'streams by its own retained_probe_collector_drains_large_stdout_and_stderr().',
    ];

    #[Test]
    public function every_test_scope_proc_open_call_is_allowlisted_with_a_rationale(): void
    {
        $found = self::scanForProcOpenCallSites();

        self::assertSame(
            array_keys(self::ALLOWED),
            array_keys($found),
            "A proc_open() call site appeared or moved in test/benchmark scope.\n"
            . 'Convert it to Symfony\\Component\\Process\\Process (see #2491), or, if it is demonstrably '
            . "safe, add it to self::ALLOWED with the reason it cannot deadlock.\n"
            . 'Found: ' . json_encode($found, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        foreach (self::ALLOWED as $path => $rationale) {
            self::assertNotSame('', trim($rationale), "Allowlist entry {$path} must carry a rationale.");
        }
    }

    #[Test]
    public function allowlisted_runners_are_non_blocking(): void
    {
        // The rationale for each allowlisted file rests on it NOT being the
        // blocking sequential-drain shape. Assert the mechanism rather than
        // trusting the prose above.
        foreach (array_keys(self::ALLOWED) as $path) {
            $source = (string) file_get_contents(self::repositoryRoot() . '/' . $path);
            self::assertMatchesRegularExpression(
                '/stream_set_blocking\s*\(\s*\$pipes\[[12]\]\s*,\s*false\s*\)|stream_set_blocking\(\$pipe,\s*false\)/',
                $source,
                "{$path} is allowlisted as safe but no longer sets its pipes non-blocking.",
            );
        }
    }

    #[Test]
    public function converted_runner_shape_survives_high_volume_on_both_streams(): void
    {
        // The house pattern every converted harness now uses. 256KiB on BOTH
        // streams is four times the ~64KB pipe buffer in either direction, so
        // the pre-conversion sequential drain deadlocks here and this assertion
        // would never return. Bounded so a regression fails instead of hanging
        // the suite.
        $bytes = 262_144;
        $child = <<<'PHP'
            $bytes = (int) $argv[1];
            fwrite(STDERR, str_repeat('E', $bytes));
            fwrite(STDOUT, str_repeat('O', $bytes));
            exit(41);
            PHP;

        $process = new Process([PHP_BINARY, '-r', $child, (string) $bytes], null, null, null, null);
        $process->setTimeout(30.0);
        $exit = $process->run();

        self::assertSame(41, $exit);
        self::assertSame($bytes, strlen($process->getOutput()));
        self::assertSame($bytes, strlen($process->getErrorOutput()));
        self::assertSame(hash('sha256', str_repeat('O', $bytes)), hash('sha256', $process->getOutput()));
        self::assertSame(hash('sha256', str_repeat('E', $bytes)), hash('sha256', $process->getErrorOutput()));
    }

    /**
     * Find real proc_open() call sites via the PHP tokenizer.
     *
     * Tokenizing rather than grepping is what makes this gate trustworthy: it
     * cannot be fooled by the word appearing in a comment, in a skip message
     * ("proc_open() not available"), or inside a function_exists('proc_open')
     * guard — those are T_COMMENT and T_CONSTANT_ENCAPSED_STRING tokens, never
     * a T_STRING followed by an opening parenthesis.
     *
     * @return array<string, list<int>> repo-relative path => 1-indexed lines
     */
    private static function scanForProcOpenCallSites(): array
    {
        $root = self::repositoryRoot();
        $roots = array_merge(
            [$root . '/tests', $root . '/benchmarks'],
            glob($root . '/packages/*/tests', GLOB_ONLYDIR) ?: [],
        );

        $sites = [];
        foreach ($roots as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $lines = self::procOpenLines((string) file_get_contents($file->getPathname()));
                if ($lines === []) {
                    continue;
                }
                $relative = str_replace($root . '/', '', str_replace('\\', '/', $file->getPathname()));
                $sites[$relative] = $lines;
            }
        }

        ksort($sites);

        return $sites;
    }

    /** @return list<int> */
    private static function procOpenLines(string $source): array
    {
        $tokens = token_get_all($source);
        $lines = [];
        foreach ($tokens as $index => $token) {
            if (!is_array($token)) {
                continue;
            }
            // `proc_open(...)` tokenizes as T_STRING, but `\proc_open(...)` —
            // the form several harnesses use inside a namespace — tokenizes as
            // T_NAME_FULLY_QUALIFIED. Matching only T_STRING silently misses
            // those and leaves a hole in this gate.
            $isCall = ($token[0] === T_STRING && strtolower($token[1]) === 'proc_open')
                || ($token[0] === T_NAME_FULLY_QUALIFIED && strtolower($token[1]) === '\\proc_open');
            if (!$isCall) {
                continue;
            }
            for ($next = $index + 1; $next < count($tokens); $next++) {
                $candidate = $tokens[$next];
                if (is_array($candidate) && in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if ($candidate === '(') {
                    $lines[] = $token[2];
                }
                break;
            }
        }

        return $lines;
    }

    private static function repositoryRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
