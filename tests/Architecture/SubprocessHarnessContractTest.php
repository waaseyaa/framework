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
        'tests/Integration/Mcp/StdioMcpConformanceTest.php' =>
            'Genuinely needs raw bidirectional pipe control, not run-to-completion or fire-and-forget: it '
            . 'holds an interactive JSON-RPC session open across many request/response round trips against a '
            . 'live mcp:serve child, which Symfony\\Component\\Process\\Process has no API for (it streams '
            . 'output via a callback or blocks until exit; it does not let a caller write one line, then read '
            . 'exactly the next line, repeatedly). Deadlock-safe by the same shape as the two entries above: '
            . 'all three descriptors (stdin, stdout, stderr) are set non-blocking via stream_set_blocking(), '
            . 'and readLine() polls stdout AND stderr together in one stream_select() loop, draining whichever '
            . 'is ready each iteration under a 15s deadline — stderr is buffered for assertion messages rather '
            . 'than ever left undrained. tearDown() closes every pipe before proc_terminate()+proc_close().',
        'tests/PackagedForm/community-events-registered-role-provisioning.php' =>
            'Runs inside a physical packaged consumer whose runtime dependencies do not include symfony/process. '
            . 'Only stdin is a pipe; stdout and stderr are separate tmpfile() resources passed directly as child '
            . 'descriptors, so neither output can fill a bounded pipe or block the other. The runner closes stdin '
            . 'after one bounded request, enforces a 30s deadline with termination, and rejects either output above 8KiB.',
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
    public function allowlisted_runners_have_a_deadlock_safe_output_shape(): void
    {
        // The rationale for each allowlisted file rests on it not using the
        // blocking sequential-drain shape. Assert either multiplexed,
        // non-blocking output pipes or file-backed output descriptors rather
        // than trusting the prose above.
        foreach (array_keys(self::ALLOWED) as $path) {
            $source = (string) file_get_contents(self::repositoryRoot() . '/' . $path);
            $usesNonBlockingPipes = preg_match(
                '/stream_set_blocking\s*\(\s*\$pipes\[[12]\]\s*,\s*false\s*\)|stream_set_blocking\(\$pipe,\s*false\)/',
                self::executableSource($source),
            ) === 1;
            $usesFileBackedOutputs = self::usesFileBackedProcOpenOutputs($source);

            self::assertTrue(
                $usesNonBlockingPipes || $usesFileBackedOutputs,
                "{$path} is allowlisted as safe but no longer has non-blocking pipes or file-backed outputs.",
            );
        }
    }

    #[Test]
    public function file_backed_output_shape_is_bound_to_the_actual_proc_open_descriptors(): void
    {
        $safeRenamedVariables = <<<'PHP'
            <?php
            $capturedOutput = tmpfile();
            $capturedErrors = tmpfile();
            $process = proc_open(
                $command,
                [0 => ['pipe', 'r'], 1 => $capturedOutput, 2 => $capturedErrors],
                $pipes,
            );
            PHP;
        $unsafeBlockingPipesWithDecoys = <<<'PHP'
            <?php
            $stdoutHandle = tmpfile();
            $stderrHandle = tmpfile();
            // Historical safe shape: [1 => $stdoutHandle, 2 => $stderrHandle].
            $process = proc_open(
                $command,
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            PHP;

        self::assertTrue(self::usesFileBackedProcOpenOutputs($safeRenamedVariables));
        self::assertFalse(self::usesFileBackedProcOpenOutputs($unsafeBlockingPipesWithDecoys));
    }

    #[Test]
    public function packaged_file_backed_runner_rejects_oversized_output_from_either_stream(): void
    {
        foreach (['STDOUT', 'STDERR'] as $stream) {
            $process = self::runPackagedRunnerProbe(
                "fwrite({$stream}, str_repeat('X', 8193));",
                5.0,
            );

            self::assertSame(73, $process->getExitCode(), $process->getErrorOutput());
            self::assertSame('The provisioning command exceeded its output limit.', $process->getOutput());
        }
    }

    #[Test]
    public function packaged_file_backed_runner_terminates_a_child_at_its_deadline(): void
    {
        $started = microtime(true);
        $process = self::runPackagedRunnerProbe('sleep(60);', 35.0);
        $elapsed = microtime(true) - $started;

        self::assertSame(73, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('The provisioning command exceeded its time limit.', $process->getOutput());
        self::assertGreaterThanOrEqual(29.0, $elapsed);
        self::assertLessThan(35.0, $elapsed);
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
                // Skip installed dependencies. tests/PackagedForm/skeleton is a
                // separate Composer project, so once ci/packaged-form installs
                // it, phpunit's own JobRunner and four sebastian/* packages sit
                // under tests/ calling proc_open(). They are third-party build
                // output, not harnesses this gate governs, and whether they
                // exist depends on whether the packaged-form job has run --
                // which made this gate fail only on machines that had run it.
                $normalised = str_replace('\\', '/', $file->getPathname());
                if (str_contains($normalised, '/vendor/')) {
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

    private static function runPackagedRunnerProbe(string $child, float $timeout): Process
    {
        $source = (string) file_get_contents(
            self::repositoryRoot() . '/tests/PackagedForm/community-events-registered-role-provisioning.php',
        );
        $function = self::namedFunctionSource($source, 'runBoundedProcess');
        $probe = <<<'PHP'
            <?php

            declare(strict_types=1);

            __FUNCTION_SOURCE__

            $child = base64_decode($argv[1], true);
            if (!is_string($child)) {
                exit(74);
            }
            try {
                runBoundedProcess([PHP_BINARY, '-r', $child], __DIR__, [], '');
            } catch (Throwable $exception) {
                fwrite(STDOUT, $exception->getMessage());
                exit(73);
            }
            PHP;
        $probe = str_replace('__FUNCTION_SOURCE__', $function, $probe);
        $path = tempnam(sys_get_temp_dir(), 'waaseyaa-subprocess-contract-');
        self::assertIsString($path);
        self::assertNotFalse(file_put_contents($path, $probe));

        $process = new Process([PHP_BINARY, $path, base64_encode($child)]);
        $process->setTimeout($timeout);
        try {
            $process->run();
        } finally {
            @unlink($path);
        }

        return $process;
    }

    private static function namedFunctionSource(string $source, string $name): string
    {
        $tokens = token_get_all($source);
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }
            $nameIndex = self::nextSignificantToken($tokens, $index + 1);
            if (
                $nameIndex === null
                || !is_array($tokens[$nameIndex])
                || $tokens[$nameIndex][0] !== T_STRING
                || $tokens[$nameIndex][1] !== $name
            ) {
                continue;
            }
            $function = '';
            $started = false;
            $depth = 0;
            for ($cursor = $index; $cursor < count($tokens); $cursor++) {
                $part = $tokens[$cursor];
                $function .= is_array($part) ? $part[1] : $part;
                if ($part === '{') {
                    $started = true;
                    $depth++;
                } elseif ($part === '}' && $started) {
                    $depth--;
                    if ($depth === 0) {
                        return $function;
                    }
                }
            }
        }

        self::fail("Could not extract {$name}() from the packaged runner.");
    }

    private static function usesFileBackedProcOpenOutputs(string $source): bool
    {
        $tokens = token_get_all($source);
        $found = false;
        foreach ($tokens as $index => $token) {
            if (!self::isProcOpenToken($token)) {
                continue;
            }
            $found = true;
            $open = self::nextSignificantToken($tokens, $index + 1);
            if ($open === null || $tokens[$open] !== '(') {
                return false;
            }
            $arguments = self::callArguments($tokens, $open);
            if ($arguments === null || !isset($arguments[1])) {
                return false;
            }
            $descriptorPattern = <<<'REGEX'
                /^\[0=>\[(?:'pipe'|"pipe"),(?:'r'|"r")\],1=>(\$[A-Za-z_][A-Za-z0-9_]*),2=>(\$[A-Za-z_][A-Za-z0-9_]*)\]$/
                REGEX;
            if (
                preg_match($descriptorPattern, self::compactTokens($arguments[1]), $matches) !== 1
                || $matches[1] === $matches[2]
            ) {
                return false;
            }
            $scopeStart = self::lastFunctionToken($tokens, $index);
            if (
                !self::lastDirectAssignmentIsTmpfile($tokens, $matches[1], $scopeStart, $index)
                || !self::lastDirectAssignmentIsTmpfile($tokens, $matches[2], $scopeStart, $index)
            ) {
                return false;
            }
        }

        return $found;
    }

    private static function executableSource(string $source): string
    {
        $executable = '';
        foreach (token_get_all($source) as $token) {
            if (
                is_array($token)
                && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING], true)
            ) {
                continue;
            }
            $executable .= is_array($token) ? $token[1] : $token;
        }

        return $executable;
    }

    /**
     * @param list<mixed> $tokens
     * @return list<list<mixed>>|null
     */
    private static function callArguments(array $tokens, int $open): ?array
    {
        $arguments = [];
        $current = [];
        $depth = 0;
        for ($index = $open + 1; $index < count($tokens); $index++) {
            $token = $tokens[$index];
            if ($token === ')' && $depth === 0) {
                $arguments[] = $current;

                return $arguments;
            }
            if (in_array($token, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($token, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($token === ',' && $depth === 0) {
                $arguments[] = $current;
                $current = [];
                continue;
            }
            $current[] = $token;
        }

        return null;
    }

    /** @param list<mixed> $tokens */
    private static function compactTokens(array $tokens): string
    {
        $compact = '';
        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $compact .= is_array($token) ? $token[1] : $token;
        }

        return $compact;
    }

    /** @param list<mixed> $tokens */
    private static function lastDirectAssignmentIsTmpfile(
        array $tokens,
        string $variable,
        int $start,
        int $before,
    ): bool {
        $isTmpfile = false;
        for ($index = $start; $index < $before; $index++) {
            $token = $tokens[$index];
            if (!is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== $variable) {
                continue;
            }
            $assignment = self::nextSignificantToken($tokens, $index + 1);
            if ($assignment === null || $tokens[$assignment] !== '=') {
                continue;
            }
            $value = self::nextSignificantToken($tokens, $assignment + 1);
            $open = $value === null ? null : self::nextSignificantToken($tokens, $value + 1);
            $close = $open === null ? null : self::nextSignificantToken($tokens, $open + 1);
            $isTmpfile = $value !== null
                && is_array($tokens[$value])
                && $tokens[$value][0] === T_STRING
                && strtolower($tokens[$value][1]) === 'tmpfile'
                && $open !== null
                && $tokens[$open] === '('
                && $close !== null
                && $tokens[$close] === ')';
        }

        return $isTmpfile;
    }

    /** @param list<mixed> $tokens */
    private static function lastFunctionToken(array $tokens, int $before): int
    {
        for ($index = $before - 1; $index >= 0; $index--) {
            $token = $tokens[$index];
            if (is_array($token) && $token[0] === T_FUNCTION) {
                return $index;
            }
        }

        return 0;
    }

    /** @param list<mixed> $tokens */
    private static function nextSignificantToken(array $tokens, int $start): ?int
    {
        for ($index = $start; $index < count($tokens); $index++) {
            $token = $tokens[$index];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $index;
        }

        return null;
    }

    private static function isProcOpenToken(mixed $token): bool
    {
        return is_array($token)
            && (($token[0] === T_STRING && strtolower($token[1]) === 'proc_open')
                || ($token[0] === T_NAME_FULLY_QUALIFIED && strtolower($token[1]) === '\\proc_open'));
    }

    private static function repositoryRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
