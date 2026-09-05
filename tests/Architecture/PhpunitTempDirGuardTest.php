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

    /** @return iterable<string, array{string, bool}> */
    public static function tempDirs(): iterable
    {
        yield 'relative dot' => ['.', false];
        yield 'relative nested' => ['relative/dir', false];
        yield 'empty string' => ['', false];
        yield 'repository root itself' => ['__ROOT__', false];
        yield 'repository root with trailing slash' => ['__ROOT__/', false];
        yield 'absolute posix' => ['/tmp', true];
        yield 'absolute posix nested' => ['/var/folders/xy/T', true];
        yield 'absolute windows drive' => ['C:\\Users\\dev\\AppData\\Local\\Temp', true];
        yield 'absolute windows forward slashes' => ['C:/Temp', true];
        yield 'repository-internal scratch (tmp/) is allowed' => ['__ROOT__/tmp', true];
    }

    #[Test]
    #[DataProvider('tempDirs')]
    public function the_guard_rejects_relative_and_root_temp_dirs(string $tempDir, bool $safe): void
    {
        $tempDir = str_replace('__ROOT__', $this->repoRoot, $tempDir);

        $violation = TempDirGuard::violation($tempDir, $this->repoRoot);

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
