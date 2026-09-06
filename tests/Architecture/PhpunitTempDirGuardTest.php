<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Waaseyaa\Tests\Support\TempDirGuard;

/**
 * Regression proof for the #2927 producer mechanism.
 *
 * Every `sys_get_temp_dir() . '/waaseyaa_*_' . uniqid()` test helper in this
 * repository lands in the process working directory — the repository root —
 * whenever `TMPDIR` is a relative path: PHP returns the value verbatim
 * (`TMPDIR=.` → `sys_get_temp_dir() === '.'`), so `mkdir('./waaseyaa_loader_test_x')`
 * silently creates the directory in the checkout. The PHPUnit bootstrap now
 * refuses to start the suite under such a temp directory (or one that IS the
 * repository root), so a misconfigured environment fails loudly before the
 * first test writes anything instead of leaving hundreds of empty directories
 * that `git status` never reports.
 */
#[CoversNothing]
final class PhpunitTempDirGuardTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    #[Test]
    public function phpunit_boots_through_the_guarded_bootstrap(): void
    {
        $config = (string) file_get_contents($this->repoRoot . '/phpunit.xml.dist');

        self::assertStringContainsString('bootstrap="tests/bootstrap.php"', $config);
        self::assertFileExists($this->repoRoot . '/tests/bootstrap.php');
    }

    /**
     * @return iterable<string, array{string, bool, bool}> temp dir, Windows
     *                                                    path semantics, safe
     */
    public static function tempDirs(): iterable
    {
        // POSIX: only a leading "/" is absolute. Drive-rooted, UNC and
        // backslash-rooted strings are ordinary relative names there — PHP
        // resolves them against the cwd, i.e. the repository root (#2927).
        yield 'posix: relative dot' => ['.', false, false];
        yield 'posix: relative nested' => ['relative/dir', false, false];
        yield 'posix: empty string' => ['', false, false];
        yield 'posix: repository root itself' => ['__ROOT__', false, false];
        yield 'posix: repository root with trailing slash' => ['__ROOT__/', false, false];
        yield 'posix: absolute' => ['/tmp', false, true];
        yield 'posix: absolute nested' => ['/var/folders/xy/T', false, true];
        yield 'posix: repository-internal scratch (tmp/) is allowed' => ['__ROOT__/tmp', false, true];
        yield 'posix: windows drive path is relative here' => ['C:\\Users\\dev\\AppData\\Local\\Temp', false, false];
        yield 'posix: windows drive path with forward slashes is relative here' => ['C:/Temp', false, false];
        yield 'posix: drive-relative windows path is relative here' => ['C:Temp', false, false];
        yield 'posix: UNC path is relative here' => ['\\\\server\\share\\tmp', false, false];
        yield 'posix: backslash-rooted path is relative here' => ['\\Temp', false, false];

        // Windows: fully qualified means drive-rooted or UNC. Drive-relative
        // ("C:foo") and current-drive-rooted ("\foo", "/foo") names are not.
        yield 'windows: drive-rooted' => ['C:\\Users\\dev\\AppData\\Local\\Temp', true, true];
        yield 'windows: drive-rooted with forward slashes' => ['C:/Temp', true, true];
        yield 'windows: UNC' => ['\\\\server\\share\\tmp', true, true];
        yield 'windows: UNC with forward slashes' => ['//server/share/tmp', true, true];
        yield 'windows: drive-relative' => ['C:Temp', true, false];
        yield 'windows: current-drive-rooted backslash' => ['\\Temp', true, false];
        yield 'windows: current-drive-rooted forward slash' => ['/Temp', true, false];
        yield 'windows: bare drive letter' => ['C:', true, false];
        yield 'windows: relative' => ['Temp', true, false];
        yield 'windows: relative dot' => ['.', true, false];
        yield 'windows: empty string' => ['', true, false];
    }

    #[Test]
    #[DataProvider('tempDirs')]
    public function the_guard_rejects_relative_and_root_temp_dirs(string $tempDir, bool $windows, bool $safe): void
    {
        $tempDir = str_replace('__ROOT__', $this->repoRoot, $tempDir);

        $violation = TempDirGuard::violation($tempDir, $this->repoRoot, $windows);

        if ($safe) {
            self::assertNull($violation, "{$tempDir} must be accepted.");
        } else {
            self::assertIsString($violation, "{$tempDir} must be rejected.");
            self::assertStringContainsString('TMPDIR', $violation);
        }
    }

    #[Test]
    public function the_bootstrap_refuses_to_run_under_a_relative_tmpdir(): void
    {
        $process = new Process(
            [PHP_BINARY, '-r', 'require $argv[1];', $this->repoRoot . '/tests/bootstrap.php'],
            $this->repoRoot,
            ['TMPDIR' => '.'],
        );
        $process->run();

        self::assertSame(1, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        self::assertStringContainsString('TMPDIR', $process->getErrorOutput());
        self::assertStringContainsString('#2927', $process->getErrorOutput());
    }

    #[Test]
    public function the_bootstrap_loads_cleanly_under_an_absolute_tmpdir(): void
    {
        $process = new Process(
            [PHP_BINARY, '-r', 'require $argv[1]; echo "booted";', $this->repoRoot . '/tests/bootstrap.php'],
            $this->repoRoot,
            ['TMPDIR' => sys_get_temp_dir()],
        );
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        self::assertSame('booted', $process->getOutput());
    }
}
