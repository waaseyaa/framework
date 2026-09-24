<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Pins the Bash that `composer hooks:install` and `composer hooks:doctor`
 * start (#2679). Composer runs a script line through the host shell, and on
 * native Windows a bare `bash` there is C:\Windows\System32\bash.exe, the WSL
 * launcher: its Linux Git cannot read a Windows linked worktree, so install
 * wrote the shims into the worktree root and doctor then approved them. POSIX
 * hosts keep `bash` from PATH. Native Windows uses Git for Windows' Bash, the
 * shell Git runs the installed shims with, and never falls back to PATH.
 *
 * The host-rule cases run everywhere with a simulated Windows installation;
 * only a native Windows run of the end-to-end case is native Windows evidence.
 */
#[CoversNothing]
final class ProjectHooksLauncherTest extends TestCase
{
    private string $root;
    private string $scratch;
    private string|false $pinned;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->scratch = sys_get_temp_dir() . '/waaseyaa-hooks-launcher-' . bin2hex(random_bytes(6));
        mkdir($this->scratch, 0o777, true);
        $this->pinned = getenv('WAASEYAA_SYSTEM_GIT');
    }

    protected function tearDown(): void
    {
        putenv($this->pinned === false ? 'WAASEYAA_SYSTEM_GIT' : 'WAASEYAA_SYSTEM_GIT=' . $this->pinned);
        $this->remove($this->scratch);
    }

    #[Test]
    public function composer_hook_scripts_start_the_php_launcher_instead_of_a_bare_bash(): void
    {
        $scripts = $this->composerScripts();

        self::assertSame('@php bin/project-hooks-launcher install', $scripts['hooks:install']);
        self::assertSame('@php bin/project-hooks-launcher doctor', $scripts['hooks:doctor']);
    }

    /** @return iterable<string, array{string}> */
    public static function posixFamilies(): iterable
    {
        foreach (['Linux', 'Darwin', 'BSD', 'Solaris', 'Unknown'] as $family) {
            yield $family => [$family];
        }
    }

    #[Test]
    #[DataProvider('posixFamilies')]
    public function posix_hosts_keep_bash_from_path(string $family): void
    {
        $this->requireLibrary();

        self::assertSame(['bash'], repository_bash_command('/repo', $family));
        // An exec path is only consulted on Windows.
        self::assertSame(['bash'], repository_bash_command('/repo', $family, '/usr/lib/git-core'));
    }

    #[Test]
    public function native_windows_starts_bash_from_the_git_for_windows_installation(): void
    {
        $this->requireLibrary();
        $installation = $this->scratch . '/Git';
        mkdir($installation . '/mingw64/libexec/git-core', 0o777, true);
        mkdir($installation . '/bin');
        touch($installation . '/bin/bash.exe');
        $expected = [str_replace('\\', '/', $installation) . '/bin/bash.exe'];

        self::assertSame($expected, repository_bash_command('C:\\repo', 'Windows', $installation . '/mingw64/libexec/git-core'));
        self::assertSame(
            $expected,
            repository_bash_command('C:\\repo', 'Windows', str_replace('/', '\\', $installation . '/mingw64/libexec/git-core') . "\n"),
            'Separator spelling and the trailing newline of `git --exec-path` must not matter.',
        );
    }

    #[Test]
    public function native_windows_fails_closed_instead_of_falling_back_to_path_bash(): void
    {
        $this->requireLibrary();
        // MinGit ships git.exe without Bash.
        mkdir($this->scratch . '/MinGit/mingw64/libexec/git-core', 0o777, true);

        self::assertNull(repository_bash_command('C:\\repo', 'Windows', $this->scratch . '/MinGit/mingw64/libexec/git-core'));
        self::assertNull(repository_bash_command('C:\\repo', 'Windows', '/usr/libexec/git-core'), 'A POSIX-shaped exec path names no Windows installation.');
        self::assertNull(repository_bash_command('C:\\repo', 'Windows', ''), 'A Git that reported no exec path must not select PATH bash.');
    }

    /**
     * The exec-path probe must start Git through repository_git_command(),
     * never a bare `git`. On Windows a pinned WAASEYAA_SYSTEM_GIT that cannot
     * start fails the probe, and so the host rule, closed. POSIX hosts start
     * the root's bin/git adapter, here a fixture that answers with a marker.
     */
    #[Test]
    public function the_exec_path_probe_starts_git_through_the_repository_git_entrypoint(): void
    {
        $this->requireLibrary();

        if (PHP_OS_FAMILY === 'Windows') {
            putenv('WAASEYAA_SYSTEM_GIT');
            $discovered = rtrim(str_replace('\\', '/', trim(repository_git_exec_path($this->root))), '/');
            self::assertStringEndsWith('/libexec/git-core', $discovered, 'Positive control: the unpinned probe reaches Git for Windows.');
            self::assertNotNull(repository_bash_command($this->root));

            putenv('WAASEYAA_SYSTEM_GIT=' . $this->scratch . '\\missing\\git.exe');
            self::assertSame('', repository_git_exec_path($this->root), 'The probe must start the pinned Git, not PATH git.');
            self::assertNull(repository_bash_command($this->root), 'A pinned Git that cannot start must fail the host rule closed.');

            return;
        }

        $fixture = $this->scratch . '/adapter';
        mkdir($fixture . '/bin', 0o777, true);
        file_put_contents($fixture . '/bin/git', "#!/bin/sh\n[ \"\$1\" = --exec-path ] || exit 2\necho /fixture/libexec/git-core\n");
        chmod($fixture . '/bin/git', 0o755);
        // The pin is a Windows concern; the POSIX adapter handles its own.
        putenv('WAASEYAA_SYSTEM_GIT=' . $this->scratch . '/missing/git');

        self::assertSame("/fixture/libexec/git-core\n", repository_git_exec_path($fixture), 'The probe must start the root\'s bin/git adapter, not PATH git.');
    }

    #[Test]
    public function bounded_output_returns_stdout_and_fails_closed_on_exit_overflow_start_or_deadline(): void
    {
        $this->requireLibrary();

        self::assertSame('ok', repository_bounded_output([PHP_BINARY, '-r', 'echo "ok";'], 10.0));
        self::assertSame(str_repeat('x', 4096), repository_bounded_output([PHP_BINARY, '-r', 'echo str_repeat("x", 4096);'], 10.0));
        self::assertNull(repository_bounded_output([PHP_BINARY, '-r', 'echo "partial"; exit(3);'], 10.0), 'A failing command has no answer.');
        self::assertNull(
            repository_bounded_output([PHP_BINARY, '-r', 'echo str_repeat("x", 1 << 20);'], 10.0),
            'Output past the cap is rejected, not truncated, and a child writing more than a pipe buffer still finishes.',
        );
        self::assertNull(repository_bounded_output([$this->scratch . '/missing/tool'], 10.0), 'A command that cannot start has no answer.');

        $started = hrtime(true);
        self::assertNull(repository_bounded_output([PHP_BINARY, '-r', 'echo "late"; sleep(30);'], 0.2), 'A command past its deadline has no answer.');
        self::assertLessThan(10.0, (hrtime(true) - $started) / 1e9);
    }

    #[Test]
    public function a_child_past_its_deadline_is_stopped_and_an_exit_code_passes_through(): void
    {
        $this->requireLibrary();
        $null = [0 => ['null'], 1 => ['null'], 2 => ['null']];
        $pipes = [];

        $process = proc_open([PHP_BINARY, '-r', 'exit(7);'], $null, $pipes);
        self::assertIsResource($process);
        self::assertSame([7, true], repository_wait_for_child($process, 10.0));

        $survived = $this->scratch . '/survived';
        $started = hrtime(true);
        $process = proc_open([PHP_BINARY, '-r', 'usleep(2000000); file_put_contents($argv[1], "survived");', $survived], $null, $pipes);
        self::assertIsResource($process);
        self::assertSame([null, true], repository_wait_for_child($process, 0.2));
        self::assertLessThan(1.8, (hrtime(true) - $started) / 1e9, 'The deadline returns before the child would have finished.');

        usleep(max(0, 2_600_000 - intdiv(hrtime(true) - $started, 1000)));
        self::assertFileDoesNotExist($survived, 'The child must be stopped at the deadline, not abandoned.');
    }

    #[Test]
    public function the_launcher_accepts_only_install_and_doctor(): void
    {
        foreach ([[], ['pre-push'], ['claude-session'], ['install', 'doctor']] as $arguments) {
            [$code, $output] = $this->execute([PHP_BINARY, $this->root . '/bin/project-hooks-launcher', ...$arguments], $this->root, []);
            self::assertSame(1, $code, $output);
            self::assertStringContainsString('usage: bin/project-hooks-launcher {install|doctor}', $output);
        }
    }

    /**
     * The real Composer scripts, run from a linked worktree of a disposable
     * repository. A `bash` placed first on PATH stands in for the System32 WSL
     * launcher on Windows, where it must never start, and records that POSIX
     * hosts still take `bash` from PATH.
     */
    #[Test]
    public function composer_hook_scripts_install_and_diagnose_a_linked_worktree_with_the_host_bash(): void
    {
        $windows = PHP_OS_FAMILY === 'Windows';
        $fixture = $this->scratch . '/repo';
        $linked = $this->scratch . '/linked';
        $shadow = $this->scratch . '/shadow';
        $shadowUsed = $shadow . '/used';
        mkdir($fixture . '/bin/lib', 0o777, true);
        mkdir($shadow);

        foreach (glob($this->root . '/bin/project-hooks*') ?: [] as $file) {
            self::assertTrue(copy($file, $fixture . '/bin/' . basename($file)));
        }
        foreach (glob($this->root . '/bin/lib/*.php') ?: [] as $file) {
            self::assertTrue(copy($file, $fixture . '/bin/lib/' . basename($file)));
        }
        $scripts = $this->composerScripts();
        file_put_contents($fixture . '/composer.json', json_encode(
            ['name' => 'waaseyaa/project-hooks-fixture', 'scripts' => ['hooks:install' => $scripts['hooks:install'], 'hooks:doctor' => $scripts['hooks:doctor']]],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n");
        file_put_contents($fixture . '/.gitattributes', "* text eol=lf\n");

        if ($windows) {
            file_put_contents($shadow . '/bash.cmd', "@echo off\r\necho used>>\"%~dp0used\"\r\necho PATH bash started instead of Git for Windows Bash 1>&2\r\nexit /b 97\r\n");
        } else {
            $bash = new ExecutableFinder()->find('bash');
            self::assertIsString($bash, 'POSIX hosts need bash on PATH.');
            file_put_contents($shadow . '/bash', "#!/bin/sh\necho used >> " . escapeshellarg($shadowUsed) . "\nexec " . escapeshellarg($bash) . " \"\$@\"\n");
            chmod($shadow . '/bash', 0o755);
        }

        // Neither the fixture nor the scripts under test may read the runner's Git configuration (core.hooksPath above all).
        file_put_contents($this->scratch . '/gitconfig', "[user]\n\tname = Project Hooks Launcher Test\n\temail = test@example.com\n");
        $gitEnv = ['GIT_CONFIG_GLOBAL' => $this->scratch . '/gitconfig', 'GIT_CONFIG_NOSYSTEM' => '1'];
        foreach ([['init', '--quiet'], ['add', '-A'], ['commit', '--quiet', '--no-verify', '-m', 'fixture'], ['worktree', 'add', '--quiet', '-b', 'linked', $linked]] as $arguments) {
            [$code, $output] = $this->execute(['git', ...$arguments], $fixture, $gitEnv);
            self::assertSame(0, $code, $output);
        }

        $pathKey = 'PATH';
        foreach (array_keys(getenv()) as $name) {
            if (strcasecmp($name, 'PATH') === 0) {
                $pathKey = $name;
            }
        }
        $composerEnv = $gitEnv + [
            $pathKey => $shadow . PATH_SEPARATOR . (string) getenv('PATH'),
            'COMPOSER' => false,
            'COMPOSER_NO_INTERACTION' => '1',
        ];
        $composer = fn(string $cwd, string $script): array => $this->execute(
            [$this->composerBinary(), 'run-script', '--no-ansi', '--no-interaction', $script],
            $cwd,
            $composerEnv,
        );

        [$code, $output] = $composer($linked, 'hooks:doctor');
        self::assertNotSame(0, $code, $output);
        self::assertStringContainsString("pre-commit is missing or stale; run 'composer hooks:install'", $output);

        [$code, $output] = $composer($linked, 'hooks:install');
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Project hooks installed in', $output);
        foreach (['pre-commit', 'pre-push'] as $hook) {
            self::assertFileExists($fixture . '/.git/hooks/' . $hook, 'A linked worktree installs into the common hook directory.');
            self::assertStringContainsString('waaseyaa-project-hooks-v1', (string) file_get_contents($fixture . '/.git/hooks/' . $hook));
        }
        [, $status] = $this->execute(['git', 'status', '--porcelain', '--untracked-files=all'], $linked, $gitEnv);
        self::assertSame('', $status, 'Install must not write shims or directories into the linked worktree.');
        self::assertSame(['.git', '.gitattributes', 'bin', 'composer.json'], $this->entries($linked));

        [$code, $output] = $composer($linked, 'hooks:doctor');
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Project hooks are installed and current.', $output);

        [$code, $output] = $composer($fixture, 'hooks:doctor');
        self::assertSame(0, $code, $output);

        if ($windows) {
            self::assertFileDoesNotExist($shadowUsed, 'Native Windows must not start the PATH bash.');
        } else {
            self::assertFileExists($shadowUsed, 'POSIX hosts must keep starting bash from PATH.');
        }
    }

    private function requireLibrary(): void
    {
        $library = $this->root . '/bin/lib/repository-bash.php';
        self::assertFileExists($library);
        require_once $library;
    }

    /** @return array<string, mixed> */
    private function composerScripts(): array
    {
        $manifest = json_decode((string) file_get_contents($this->root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);

        return $manifest['scripts'];
    }

    /**
     * Composer exports COMPOSER_BINARY to its scripts, so a runner started by
     * `composer test` pins the binary it runs under.
     */
    private function composerBinary(): string
    {
        $override = getenv('COMPOSER_BINARY');
        if (is_string($override) && $override !== '' && is_file($override)) {
            return $override;
        }

        $found = new ExecutableFinder()->find('composer');
        if (is_string($found) && $found !== '') {
            return $found;
        }

        self::fail('Composer must be available to run the hook scripts it declares.');
    }

    /** @return list<string> */
    private function entries(string $directory): array
    {
        $listing = scandir($directory);
        self::assertIsArray($listing, $directory);
        $entries = array_values(array_diff($listing, ['.', '..']));
        sort($entries);

        return $entries;
    }

    /**
     * @param list<string>              $command
     * @param array<string, string|false> $env
     *
     * @return array{int, string}
     */
    private function execute(array $command, string $cwd, array $env): array
    {
        $process = new Process($command, $cwd, $env, null, 120.0);
        $process->run();

        return [(int) $process->getExitCode(), $process->getOutput() . $process->getErrorOutput()];
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @chmod($path, 0o666);
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            $this->remove($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
