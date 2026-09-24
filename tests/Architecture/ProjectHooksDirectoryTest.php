<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Pins how bin/project-hooks resolves the Git hook directory (#2679).
 *
 * `hooks:install` and `hooks:doctor` take the directory from
 * `git rev-parse --git-path hooks`. When Git failed, answered nothing, or
 * answered more than one line, the runner used to join that answer to the
 * checkout root: install exited 0 after writing its shims into the working
 * tree, and doctor then approved them. An unusable answer must now fail both
 * actions closed, with actionable stderr and nothing created or changed. Valid
 * relative, linked-worktree and absolute answers keep resolving as before.
 *
 * Each case runs the Composer entrypoint, bin/project-hooks-launcher, so the
 * runner starts under the host's supported Bash (Git for Windows Bash on
 * native Windows). Real Git produces the failure and the multi-line answer;
 * a `git` function loaded through BASH_ENV produces the empty answer, which
 * Git itself never prints.
 */
#[CoversNothing]
final class ProjectHooksDirectoryTest extends TestCase
{
    private string $root;
    private string $scratch;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->scratch = sys_get_temp_dir() . '/waaseyaa hooks dir ' . bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->scratch);
    }

    protected function tearDown(): void
    {
        $filesystem = new Filesystem();
        // Git writes its objects read-only; Windows refuses to unlink those.
        $filesystem->chmod($this->scratch, 0o755, 0o000, true);
        $filesystem->remove($this->scratch);
    }

    /** @return iterable<string, array{string, string}> */
    public static function unusableHookDirectoryAnswers(): iterable
    {
        foreach (['install', 'doctor'] as $action) {
            yield "{$action}: git fails" => [$action, 'fails'];
            yield "{$action}: git answers nothing" => [$action, 'empty'];
            yield "{$action}: git answers more than one line" => [$action, 'multiline'];
        }
    }

    #[Test]
    #[DataProvider('unusableHookDirectoryAnswers')]
    public function an_unusable_hook_directory_answer_fails_closed_without_writing(string $action, string $answer): void
    {
        $checkout = $this->checkout();
        $before = $this->snapshot($checkout);

        [$code, $stdout, $stderr] = $this->runHooks($checkout, $action, $this->unusableAnswerEnvironment($answer));

        self::assertSame(1, $code, $stdout . $stderr);
        self::assertStringContainsString('project-hooks: could not resolve the Git hook directory', $stderr);
        self::assertStringContainsString(match ($answer) {
            'fails' => '`git rev-parse --git-path hooks` failed',
            'empty' => '`git rev-parse --git-path hooks` answered nothing',
            'multiline' => '`git rev-parse --git-path hooks` answered more than one line',
        }, $stderr);
        self::assertStringContainsString('then retry', $stderr);
        self::assertStringNotContainsString('Project hooks installed', $stdout);
        self::assertStringNotContainsString('installed and current', $stdout);
        self::assertSame($before, $this->snapshot($checkout), 'Nothing may be created or changed when the hook directory is unknown.');
    }

    /** @return iterable<string, array{string}> */
    public static function usableHookDirectoryAnswers(): iterable
    {
        yield 'relative answer in a normal checkout' => ['normal'];
        yield 'absolute common directory from a linked worktree' => ['linked'];
        // Git echoes core.hooksPath verbatim: C:\... in native Windows spelling.
        yield 'absolute core.hooksPath in native spelling' => ['absolute'];
        yield 'absolute core.hooksPath with forward slashes' => ['absolute-slashes'];
    }

    #[Test]
    #[DataProvider('usableHookDirectoryAnswers')]
    public function a_usable_hook_directory_answer_keeps_resolving(string $answer): void
    {
        $checkout = $this->checkout();
        $environment = [];
        $hooks = $checkout . '/.git/hooks';
        $from = $checkout;
        if ($answer === 'linked') {
            $from = $this->scratch . '/linked worktree';
            $this->git($checkout, ['worktree', 'add', '--quiet', '-b', 'linked', $from]);
        } elseif ($answer === 'absolute' || $answer === 'absolute-slashes') {
            // Git answers a drive-letter path on Windows and a /-rooted path on POSIX.
            $hooks = $this->scratch . '/absolute hooks';
            $configured = $answer === 'absolute' ? str_replace('/', \DIRECTORY_SEPARATOR, $hooks) : str_replace('\\', '/', $hooks);
            $environment = self::configEnvironment('core.hooksPath', $configured);
        }
        $before = $this->snapshot($from);

        [$code, $stdout, $stderr] = $this->runHooks($from, 'install', $environment);
        self::assertSame(0, $code, $stdout . $stderr);
        self::assertStringContainsString('Project hooks installed in ', $stdout);
        foreach (['pre-commit', 'pre-push'] as $hook) {
            self::assertFileExists($hooks . '/' . $hook);
            self::assertStringContainsString('waaseyaa-project-hooks-v1', (string) file_get_contents($hooks . '/' . $hook));
        }
        if ($answer !== 'normal') {
            self::assertSame($before, $this->snapshot($from), 'Install must not write into the checkout it was run from.');
        }

        [$code, $stdout, $stderr] = $this->runHooks($from, 'doctor', $environment);
        self::assertSame(0, $code, $stdout . $stderr);
        self::assertStringContainsString('Project hooks are installed and current.', $stdout);
    }

    /** @return array<string, string> */
    private function unusableAnswerEnvironment(string $answer): array
    {
        if ($answer === 'fails') {
            return ['GIT_DIR' => $this->scratch . '/missing git dir'];
        }
        if ($answer === 'multiline') {
            return self::configEnvironment('core.hooksPath', "hooks\nsecond");
        }

        $fake = $this->scratch . '/empty-answer-git.sh';
        file_put_contents($fake, "git() { if [[ \"\$*\" == *'rev-parse --git-path hooks'* ]]; then return 0; fi; command git \"\$@\"; }\n");

        return ['BASH_ENV' => str_replace('\\', '/', $fake)];
    }

    /** @return array<string, string> */
    private static function configEnvironment(string $key, string $value): array
    {
        return ['GIT_CONFIG_COUNT' => '1', 'GIT_CONFIG_KEY_0' => $key, 'GIT_CONFIG_VALUE_0' => $value];
    }

    /** A committed disposable checkout carrying the runner and its launcher. */
    private function checkout(): string
    {
        $checkout = $this->scratch . '/checkout';
        $filesystem = new Filesystem();
        foreach (['bin/project-hooks', 'bin/project-hooks-launcher', 'bin/lib/repository-bash.php', 'bin/lib/repository-git.php'] as $path) {
            $filesystem->copy($this->root . '/' . $path, $checkout . '/' . $path);
        }
        file_put_contents($checkout . '/.gitattributes', "* text eol=lf\n");
        $this->git($checkout, ['init', '--quiet']);
        $this->git($checkout, ['add', '-A']);
        $this->git($checkout, ['commit', '--quiet', '--no-verify', '-m', 'fixture']);

        return $checkout;
    }

    /** @param list<string> $arguments */
    private function git(string $cwd, array $arguments): void
    {
        $process = new Process(['git', ...$arguments], $cwd, $this->isolation(), null, 60.0);
        self::assertSame(0, $process->run(), $process->getErrorOutput());
    }

    /**
     * @param array<string, string> $environment
     *
     * @return array{int, string, string}
     */
    private function runHooks(string $cwd, string $action, array $environment): array
    {
        $process = new Process([PHP_BINARY, 'bin/project-hooks-launcher', $action], $cwd, $environment + $this->isolation(), null, 120.0);
        $process->run();

        return [(int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput()];
    }

    /**
     * Neither the fixture nor the runner may read the runner's own Git
     * configuration (a global core.hooksPath above all).
     *
     * @return array<string, string>
     */
    private function isolation(): array
    {
        $config = $this->scratch . '/gitconfig';
        if (!is_file($config)) {
            file_put_contents($config, "[user]\n\tname = Project Hooks Directory Test\n\temail = test@example.com\n[core]\n\tautocrlf = false\n");
        }

        return ['GIT_CONFIG_GLOBAL' => $config, 'GIT_CONFIG_NOSYSTEM' => '1'];
    }

    /** @return array<string, string> relative path => content hash, or 'dir' */
    private function snapshot(string $directory): array
    {
        $entries = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $path => $info) {
            $relative = str_replace('\\', '/', substr((string) $path, strlen($directory) + 1));
            $entries[$relative] = $info->isDir() ? 'dir' : (string) sha1_file((string) $path);
        }
        ksort($entries);

        return $entries;
    }
}
