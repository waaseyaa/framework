<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the host rule for starting the repository Git entrypoint from PHP
 * gates (docs/governance/agent-contract.md, "Starting and isolating work";
 * #3096): POSIX hosts use the stash-refusing bin/git adapter, while native
 * Windows, which cannot start that Bash script, uses the Windows Git
 * executable. The last case really executes the selected entrypoint.
 */
#[CoversNothing]
final class RepositoryGitEntrypointTest extends TestCase
{
    private string $root;
    private string|false $pinned;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/bin/lib/repository-git.php';
        $this->pinned = getenv('WAASEYAA_SYSTEM_GIT');
    }

    protected function tearDown(): void
    {
        putenv($this->pinned === false ? 'WAASEYAA_SYSTEM_GIT' : 'WAASEYAA_SYSTEM_GIT=' . $this->pinned);
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
    public function posix_hosts_keep_the_stash_refusing_repository_adapter(string $family): void
    {
        putenv('WAASEYAA_SYSTEM_GIT');
        self::assertSame(['/repo/bin/git'], repository_git_command('/repo', $family));

        // A pinned system Git is the adapter's own concern; it never bypasses it.
        putenv('WAASEYAA_SYSTEM_GIT=/usr/local/bin/git');
        self::assertSame(['/repo/bin/git'], repository_git_command('/repo', $family));
    }

    #[Test]
    public function native_windows_uses_the_windows_git_executable_instead_of_the_bash_adapter(): void
    {
        putenv('WAASEYAA_SYSTEM_GIT');
        self::assertSame(['git'], repository_git_command('C:\\repo', 'Windows'));

        putenv('WAASEYAA_SYSTEM_GIT=C:\\Program Files\\Git\\cmd\\git.exe');
        self::assertSame(['C:\\Program Files\\Git\\cmd\\git.exe'], repository_git_command('C:\\repo', 'Windows'));
    }

    #[Test]
    public function the_host_entrypoint_reads_repository_history_on_this_host(): void
    {
        $pipes = [];
        $process = proc_open(
            [...repository_git_command($this->root), '-C', $this->root, 'rev-parse', '--verify', 'HEAD^{commit}'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process, sprintf('The repository Git entrypoint must start on %s.', PHP_OS_FAMILY));
        $output = trim((string) stream_get_contents($pipes[1]));
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), $error);
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/D', $output);
    }
}
