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
 * Fixture-driven proof that `bin/check-repo-root-hygiene` (#2927) fails when
 * the repository root holds any entry that is neither Git-tracked nor on its
 * explicit allowlist of expected local entries.
 *
 * The gate exists because 734 stray test artifacts (~11 MB) accumulated in the
 * repository root undetected: 363 of them were EMPTY untracked directories,
 * which `git status` never reports, and the rest were hidden by `.gitignore`
 * leak-family patterns. The gate therefore enumerates the literal filesystem
 * (not `git status`) and is deliberately stricter than `.gitignore`: a
 * gitignored leak family is still a finding.
 *
 * Each case builds a throwaway Git repository under the system temp directory
 * (never the real repo root — scratch hygiene) and runs the real script
 * against it with `--root=DIR`.
 */
#[CoversNothing]
final class CheckRepoRootHygieneGateTest extends TestCase
{
    private string $repoRoot;
    private string $gate;
    private string $tmpRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        $this->gate = $this->repoRoot . '/bin/check-repo-root-hygiene';
        self::assertFileExists($this->gate, 'The root-hygiene gate script must exist.');
        $this->tmpRoot = sys_get_temp_dir() . '/waaseyaa_roothygiene_' . uniqid('', true);
        mkdir($this->tmpRoot, 0o755, true);
        $this->initFixtureRepository();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->tmpRoot);
    }

    #[Test]
    public function a_tree_holding_only_tracked_entries_passes(): void
    {
        [$exit, $out] = $this->runGate();

        self::assertSame(0, $exit, "A tracked-only root must pass.\n{$out}");
        self::assertStringContainsString('OK', $out);
    }

    #[Test]
    public function an_untracked_file_fails(): void
    {
        file_put_contents($this->tmpRoot . '/stray-notes.txt', "scratch\n");

        [$exit, $out] = $this->runGate();

        self::assertSame(1, $exit, "An untracked root file must fail.\n{$out}");
        self::assertStringContainsString('stray-notes.txt', $out);
        self::assertStringContainsString('rm -rf', $out);
        self::assertStringContainsString('bin/check-repo-root-hygiene', $out, 'The failure must name the allowlist repair path.');
    }

    #[Test]
    public function an_empty_untracked_directory_fails_even_though_git_status_is_silent(): void
    {
        // The #2927 blind spot: 363 empty `waaseyaa_loader_test_*` directories
        // sat in the root with `git status --porcelain` completely empty.
        mkdir($this->tmpRoot . '/waaseyaa_loader_test_6a0d1234');
        mkdir($this->tmpRoot . '/waaseyaa_loader_test_6a0d1234/migrations');

        self::assertSame('', trim($this->git(['status', '--porcelain'])), 'Precondition: git status must be blind to the empty directory.');

        [$exit, $out] = $this->runGate();

        self::assertSame(1, $exit, "An empty untracked directory must fail.\n{$out}");
        self::assertStringContainsString('waaseyaa_loader_test_6a0d1234', $out);
        self::assertStringContainsString('empty directory', $out);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function gitignoredLeakFamilies(): iterable
    {
        // Entries `.gitignore` hides from `git status` (lines 110-135 and the
        // older `waaseyaa-sync-*` / `waaseyaa_oidc_jwks_*` rules) — every one
        // of these is a leak, not an expected entry.
        yield 'import lock dir' => ['waaseyaa_test_lock_6a0d5678', true];
        yield 'e2e lock dir' => ['waaseyaa_e2e_lock_abc', true];
        yield 'idmap sqlite wal' => ['waaseyaa_idmap_abc.sqlite-wal', false];
        yield 'csrf upload' => ['csrf_upload_abc', false];
        yield 'ssr http scratch' => ['waaseyaa_ssr_http_abc', false];
        yield 'audit handler scratch' => ['waaseyaa_audit_handler_abc', false];
        yield 'monitor test dir' => ['waaseyaa_monitor_test_abc', true];
        yield 'sync working dir' => ['waaseyaa-sync-abc', true];
        yield 'oidc jwks dir' => ['waaseyaa_oidc_jwks_abc', true];
        yield 'tempnam output' => ['phpAbCdEf0123456789xyz', false];
        yield 'media scratch' => ['imgAbCdEf0123456789xyz', false];
        yield 'dev-server session' => ['sess_0123456789abcdef0123456789abcdef', false];
        yield 'root sqlite' => ['scratch.sqlite', false];
    }

    #[Test]
    #[DataProvider('gitignoredLeakFamilies')]
    public function a_gitignored_leak_family_entry_still_fails(string $name, bool $isDirectory): void
    {
        if ($isDirectory) {
            mkdir($this->tmpRoot . '/' . $name);
        } else {
            file_put_contents($this->tmpRoot . '/' . $name, "x\n");
        }

        self::assertSame(
            '',
            trim($this->git(['status', '--porcelain'])),
            "Precondition: .gitignore must hide {$name} from git status, proving the gate is stricter than .gitignore.",
        );

        [$exit, $out] = $this->runGate();

        self::assertSame(1, $exit, "Gitignored leak {$name} must still fail.\n{$out}");
        self::assertStringContainsString($name, $out);
        self::assertStringContainsString('known leak family', $out);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function expectedLocalEntries(): iterable
    {
        // Derived from .gitignore + ci.yml: dependency installs, tool caches,
        // CI build output, worktrees, agent CLI state, and CI-generated evidence.
        yield 'vendor' => ['vendor', true];
        yield 'node_modules' => ['node_modules', true];
        yield 'tmp' => ['tmp', true];
        yield 'build (CI evidence)' => ['build', true];
        yield 'artifacts' => ['artifacts', true];
        yield 'phpstan (CI cache variant)' => ['phpstan', true];
        yield 'node-compile-cache' => ['node-compile-cache', true];
        yield '.worktrees' => ['.worktrees', true];
        yield '.kittify' => ['.kittify', true];
        yield '.idea' => ['.idea', true];
        yield '.vscode' => ['.vscode', true];
        yield '.phpunit.cache' => ['.phpunit.cache', true];
        yield '.env' => ['.env', false];
        yield '.env.local' => ['.env.local', false];
        yield '.php-cs-fixer.cache' => ['.php-cs-fixer.cache', false];
        yield '.phpunit.result.cache' => ['.phpunit.result.cache', false];
        yield '.DS_Store' => ['.DS_Store', false];
        yield 'support-contract-evidence.json (CI support-contract job)' => ['support-contract-evidence.json', false];
        yield '.codex' => ['.codex', true];
        yield '.cursor' => ['.cursor', true];
    }

    #[Test]
    #[DataProvider('expectedLocalEntries')]
    public function an_allowlisted_local_entry_passes(string $name, bool $isDirectory): void
    {
        if ($isDirectory) {
            mkdir($this->tmpRoot . '/' . $name);
        } else {
            file_put_contents($this->tmpRoot . '/' . $name, "x\n");
        }

        [$exit, $out] = $this->runGate();

        self::assertSame(0, $exit, "Allowlisted entry {$name} must pass.\n{$out}");
    }

    #[Test]
    public function every_finding_is_reported_in_one_pass(): void
    {
        mkdir($this->tmpRoot . '/waaseyaa_loader_test_a');
        mkdir($this->tmpRoot . '/waaseyaa_test_lock_b');
        file_put_contents($this->tmpRoot . '/wholly-unexpected.log', "x\n");

        [$exit, $out] = $this->runGate();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('waaseyaa_loader_test_a', $out);
        self::assertStringContainsString('waaseyaa_test_lock_b', $out);
        self::assertStringContainsString('wholly-unexpected.log', $out);
        self::assertStringContainsString('3 stray', $out);
    }

    #[Test]
    public function an_inherited_hook_git_environment_never_selects_another_repository(): void
    {
        // A pre-push hook run from a linked worktree exports
        // GIT_DIR=<main>/.git/worktrees/<name>, and bin/project-hooks runs
        // check-pr-preflight — hence this gate — with that environment
        // intact. With GIT_DIR set, `git -C $root` no longer selects the
        // repository by discovery: it enumerates the hook's repository, so a
        // stray file that happens to be tracked over there is silently
        // accepted here. The fixture plays the developer's main checkout; its
        // linked worktree gitdir is the hostile GIT_DIR, and the linked branch
        // tracks a file that is only a stray in the main root.
        $linked = $this->tmpRoot . '/.worktrees/pushing-from-here';
        $this->git(['worktree', 'add', '-q', $linked, '-b', 'pushing']);
        file_put_contents($linked . '/hook-side-only.txt', "tracked on the hook side\n");
        $this->gitIn($linked, ['add', '-A']);
        $this->gitIn($linked, ['commit', '-q', '-m', 'hook side']);
        file_put_contents($this->tmpRoot . '/hook-side-only.txt', "stray in the target root\n");
        $hostile = ['GIT_DIR' => $this->tmpRoot . '/.git/worktrees/pushing-from-here'];

        $process = new Process([PHP_BINARY, $this->gate, '--root=' . $this->tmpRoot], $this->repoRoot, $hostile);
        $exit = $process->run();
        $out = $process->getOutput() . $process->getErrorOutput();

        self::assertSame('false', trim($this->git(['config', 'core.bare'])), 'The gate must never touch the repository the hook environment points at.');
        self::assertSame(1, $exit, "The gate must enumerate the root it was given, not the hook's repository.\n{$out}");
        self::assertStringContainsString('hook-side-only.txt', $out, 'The stray file is tracked only in the hook-side repository; the target root must still report it.');
        self::assertSame('', trim($this->git(['status', '--porcelain', '--', 'README.md'])), 'The fixture repository must still be a usable work tree.');
    }

    #[Test]
    public function a_root_that_is_not_a_git_repository_fails_closed(): void
    {
        $bare = $this->tmpRoot . '/not-a-repo';
        mkdir($bare);
        file_put_contents($bare . '/README.md', "x\n");

        [$exit, $out] = $this->runGate($bare);

        self::assertNotSame(0, $exit, "A non-repository root must not pass.\n{$out}");
    }

    #[Test]
    public function the_real_repository_root_currently_passes(): void
    {
        // The gate is a pre-push and CI gate, so the tree this test runs in
        // must itself be clean; otherwise the gate blocks every push.
        [$exit, $out] = $this->runGate($this->repoRoot);

        self::assertSame(0, $exit, "The checked-out repository root must satisfy its own hygiene gate.\n{$out}");
    }

    private function initFixtureRepository(): void
    {
        $this->git(['init', '-q', '-b', 'main']);
        $this->git(['config', 'user.email', 'fixture@example.test']);
        $this->git(['config', 'user.name', 'Fixture']);
        $this->git(['config', 'commit.gpgsign', 'false']);
        file_put_contents($this->tmpRoot . '/README.md', "fixture\n");
        // Mirror the real ignore rules so the "gitignored leak still fails"
        // cases prove the gate is stricter than git status, not merely equal.
        copy($this->repoRoot . '/.gitignore', $this->tmpRoot . '/.gitignore');
        mkdir($this->tmpRoot . '/storage');
        file_put_contents($this->tmpRoot . '/storage/.gitkeep', '');
        mkdir($this->tmpRoot . '/packages/demo/src', 0o755, true);
        file_put_contents($this->tmpRoot . '/packages/demo/src/Demo.php', "<?php\n");
        $this->git(['add', '-A']);
        $this->git(['commit', '-q', '-m', 'fixture']);
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): string
    {
        return $this->gitIn($this->tmpRoot, $arguments);
    }

    /** @param list<string> $arguments */
    private function gitIn(string $directory, array $arguments): string
    {
        $process = new Process(['git', '-C', $directory, ...$arguments], null, $this->cleanGitEnvironment());
        $process->mustRun();

        return $process->getOutput();
    }

    /**
     * Unset every repository-selecting git variable (Symfony Process removes
     * a variable set to false) so the test's own git calls, like the gate's,
     * act on the fixture whatever environment PHPUnit inherited.
     *
     * @return array<string, false>
     */
    private function cleanGitEnvironment(): array
    {
        return array_fill_keys([
            'GIT_ALTERNATE_OBJECT_DIRECTORIES',
            'GIT_COMMON_DIR',
            'GIT_CONFIG',
            'GIT_CONFIG_COUNT',
            'GIT_CONFIG_PARAMETERS',
            'GIT_DIR',
            'GIT_GRAFT_FILE',
            'GIT_IMPLICIT_WORK_TREE',
            'GIT_INDEX_FILE',
            'GIT_NAMESPACE',
            'GIT_NO_REPLACE_OBJECTS',
            'GIT_OBJECT_DIRECTORY',
            'GIT_PREFIX',
            'GIT_REPLACE_REF_BASE',
            'GIT_SHALLOW_FILE',
            'GIT_WORK_TREE',
        ], false);
    }

    /** @return array{int, string} */
    private function runGate(?string $root = null): array
    {
        $process = new Process([PHP_BINARY, $this->gate, '--root=' . ($root ?? $this->tmpRoot)], $this->repoRoot, $this->cleanGitEnvironment());
        $process->run();

        return [$process->getExitCode() ?? -1, $process->getOutput() . $process->getErrorOutput()];
    }
}
