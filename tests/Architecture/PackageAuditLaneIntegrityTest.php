<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 2) . '/.agents/skills/waaseyaa-package-convergence/scripts/lane-integrity.php';

/**
 * FW-PACKAGE-CONVERGENCE-01 (#3118): the package-convergence skill's lane
 * write-isolation check (scripts/lane-integrity.php) must fail when anything
 * outside an allowed output area changes between a snapshot and a verify, must
 * fail closed when it can't inspect a root, and must stay quiet when nothing
 * changed. Each adversarial case below produced a clean diff in the first
 * version of the check. Git state is mostly exercised through an injected
 * command runner; one test builds a real throwaway checkout.
 */
#[CoversNothing]
final class PackageAuditLaneIntegrityTest extends TestCase
{
    private string $work = '';

    protected function setUp(): void
    {
        $this->work = sys_get_temp_dir() . '/waaseyaa_lane_integrity_' . bin2hex(random_bytes(6));
        mkdir($this->work . '/tree/sub', 0o777, true);
        mkdir($this->work . '/tree/out', 0o777, true);
        file_put_contents($this->work . '/tree/sub/kept.txt', 'kept');
        file_put_contents($this->work . '/tree/edited.txt', 'before');
        file_put_contents($this->work . '/tree/removed.txt', 'gone soon');
        file_put_contents($this->work . '/tree/out/lane.txt', 'lane output');
    }

    protected function tearDown(): void
    {
        $filesystem = new Filesystem();
        // Git writes its objects read-only; Windows refuses to unlink those.
        $filesystem->chmod($this->work, 0o755, 0o000, true);
        $filesystem->remove($this->work);
    }

    #[Test]
    public function an_untouched_tree_verifies_clean(): void
    {
        $before = \laneIntegritySnapshot([], [$this->work . '/tree'], [$this->work . '/tree/out'], self::neverRun(...));
        $after = \laneIntegritySnapshot([], [$this->work . '/tree'], [$this->work . '/tree/out'], self::neverRun(...));

        self::assertSame([], \laneIntegrityDiff($before, $after));
    }

    #[Test]
    public function changes_outside_the_allowed_area_are_reported_and_changes_inside_are_not(): void
    {
        $tree = $this->work . '/tree';
        $allow = [$tree . '/out'];
        $before = \laneIntegritySnapshot([], [$tree], $allow, self::neverRun(...));

        file_put_contents($tree . '/edited.txt', 'after, longer');
        unlink($tree . '/removed.txt');
        file_put_contents($tree . '/sub/added.txt', 'new');
        file_put_contents($tree . '/out/lane.txt', 'rewritten by the lane');
        file_put_contents($tree . '/out/more.txt', 'more lane output');

        $changes = \laneIntegrityDiff($before, \laneIntegritySnapshot([], [$tree], $allow, self::neverRun(...)));
        $joined = implode("\n", $changes);

        self::assertCount(3, $changes, $joined);
        self::assertStringContainsString('edited.txt: modified', $joined);
        self::assertStringContainsString('removed.txt: removed', $joined);
        self::assertStringContainsString('sub/added.txt: added', $joined);
        self::assertStringNotContainsString('/out/', $joined);
    }

    #[Test]
    public function a_same_size_rewrite_with_the_old_mtime_restored_is_reported(): void
    {
        $tree = $this->work . '/tree';
        $file = $tree . '/edited.txt';
        clearstatcache();
        $mtime = (int) filemtime($file);
        $before = \laneIntegritySnapshot([], [$tree], [], self::neverRun(...));

        file_put_contents($file, 'after!');
        touch($file, $mtime);
        clearstatcache();
        self::assertSame([6, $mtime], [filesize($file), filemtime($file)], 'the rewrite keeps size and mtime');

        self::assertSame(
            [\laneIntegrityNormalize($tree) . '/edited.txt: modified'],
            \laneIntegrityDiff($before, \laneIntegritySnapshot([], [$tree], [], self::neverRun(...))),
        );
    }

    #[Test]
    public function git_state_changes_are_reported_and_allowed_paths_are_ignored(): void
    {
        $root = $this->work . '/checkout';
        mkdir($root . '/scratch', 0o777, true);
        $allow = [$root . '/scratch'];

        $state = [
            'rev-parse' => "aaaa\n",
            'branch' => "work\n",
            'status' => " M src/A.php\0?? scratch/lane.json\0",
            'config' => "core.bare=false\n",
            'worktree' => "worktree /x\n",
        ];
        $before = \laneIntegritySnapshot([$root], [], $allow, self::fakeGit($state));
        self::assertSame([' M src/A.php'], $before['git'][\laneIntegrityNormalize($root)]['status'], 'allowed paths are not recorded');
        self::assertSame([], \laneIntegrityDiff($before, \laneIntegritySnapshot([$root], [], $allow, self::fakeGit($state))));

        $changed = [
            'rev-parse' => "bbbb\n",
            'status' => " M src/A.php\0 M src/B.php\0?? scratch/other.json\0",
            'diff' => "diff --git a/src/B.php b/src/B.php\n",
            'for-each-ref' => "refs/heads/sneaky bbbb\n",
            'stash' => "stash@{0}: WIP on work\n",
            'config' => "core.bare=false\ncore.worktree=/mnt/c/elsewhere\n",
        ] + $state;
        $changes = implode("\n", \laneIntegrityDiff($before, \laneIntegritySnapshot([$root], [], $allow, self::fakeGit($changed))));

        self::assertStringContainsString('HEAD moved', $changes);
        self::assertStringContainsString('now  M src/B.php', $changes);
        self::assertStringContainsString('tracked working-tree content changed', $changes);
        self::assertStringContainsString('ref changed', $changes);
        self::assertStringContainsString('stash list changed', $changes);
        self::assertStringContainsString('repository config changed', $changes);
        self::assertStringNotContainsString('scratch', $changes);
    }

    #[Test]
    public function a_second_write_to_an_already_dirty_or_untracked_file_is_reported(): void
    {
        $root = $this->work . '/checkout';
        mkdir($root . '/src', 0o777, true);
        file_put_contents($root . '/src/A.php', 'dirty once');
        file_put_contents($root . '/notes.txt', 'untracked once');
        // Identical Git output before and after: only the bytes change.
        $git = self::fakeGit(['rev-parse' => "aaaa\n", 'status' => " M src/A.php\0?? notes.txt\0"]);
        $before = \laneIntegritySnapshot([$root], [], [], $git);

        file_put_contents($root . '/src/A.php', 'dirty twice');
        file_put_contents($root . '/notes.txt', 'untracked twice');
        $changes = \laneIntegrityDiff($before, \laneIntegritySnapshot([$root], [], [], $git));
        $normalized = \laneIntegrityNormalize($root);

        self::assertSame([$normalized . '/notes.txt: content changed', $normalized . '/src/A.php: content changed'], $changes);
    }

    #[Test]
    public function rename_sources_are_recorded_and_a_changed_source_is_reported(): void
    {
        $root = $this->work . '/checkout';
        mkdir($root, 0o777, true);
        $before = \laneIntegritySnapshot([$root], [], [], self::fakeGit(['status' => "R  src/New.php\0src/Old.php\0"]));

        self::assertSame(['R  from src/Old.php', 'R  src/New.php'], $before['git'][\laneIntegrityNormalize($root)]['status']);

        $changes = implode("\n", \laneIntegrityDiff($before, \laneIntegritySnapshot([$root], [], [], self::fakeGit(['status' => "R  src/New.php\0src/Other.php\0"]))));
        self::assertStringContainsString('now R  from src/Other.php', $changes);
        self::assertStringContainsString('no longer R  from src/Old.php', $changes);
    }

    /** @return iterable<string, array{string}> */
    public static function gitCommands(): iterable
    {
        foreach (['toplevel', 'status', 'rev-parse', 'branch', 'diff', 'diff --cached', 'for-each-ref', 'stash', 'config', 'worktree'] as $command) {
            yield $command => [$command];
        }
    }

    #[Test]
    #[DataProvider('gitCommands')]
    public function any_failing_git_command_fails_closed(string $command): void
    {
        $root = $this->work . '/checkout';
        mkdir($root, 0o777, true);

        $this->expectException(\RuntimeException::class);
        \laneIntegritySnapshot([$root], [], [], self::fakeGit([$command => [128, '']]));
    }

    #[Test]
    public function a_missing_git_root_fails_closed(): void
    {
        $this->expectExceptionMessage('is not a directory');
        \laneIntegritySnapshot([$this->work . '/nowhere'], [], [], self::fakeGit([]));
    }

    #[Test]
    public function a_directory_inside_another_checkout_is_refused(): void
    {
        $root = $this->work . '/checkout';
        mkdir($root, 0o777, true);

        $this->expectExceptionMessage('is not the top of a Git checkout');
        \laneIntegritySnapshot([$root], [], [], self::fakeGit(['toplevel' => $this->work . "\n"]));
    }

    #[Test]
    public function the_command_line_exits_two_when_it_cannot_trust_the_result(): void
    {
        $tree = $this->work . '/tree';
        $snapshot = $this->work . '/lane.snapshot.json';

        [$exit, $out] = self::main(['snapshot', '--out=' . $tree . '/out/lane.snapshot.json', '--tree=' . $tree, '--allow=' . $tree . '/out'], self::neverRun(...));
        self::assertSame([2, ''], [$exit, $out], 'a snapshot inside an allowed area is refused');

        [$exit, $out] = self::main(['snapshot', '--out=' . $snapshot, '--tree=' . $tree, '--allow=' . $tree . '/out'], self::neverRun(...));
        self::assertSame(0, $exit);
        self::assertSame(1, preg_match('#sha256: ([0-9a-f]{64})#', $out, $digest));

        self::assertSame(0, self::main(['verify', '--snapshot=' . $snapshot, '--sha256=' . $digest[1]], self::neverRun(...))[0]);
        self::assertSame(2, self::main(['verify', '--snapshot=' . $snapshot], self::neverRun(...))[0], 'verify needs the digest');

        file_put_contents($tree . '/edited.txt', 'changed');
        self::assertSame(1, self::main(['verify', '--snapshot=' . $snapshot, '--sha256=' . $digest[1]], self::neverRun(...))[0]);

        // A lane that rewrites the snapshot to hide its change is caught by the digest.
        $forged = json_decode((string) file_get_contents($snapshot), true, 64, JSON_THROW_ON_ERROR);
        $forged['tree'][\laneIntegrityNormalize($tree)]['edited.txt'] = hash('sha256', 'changed');
        file_put_contents($snapshot, json_encode($forged, JSON_THROW_ON_ERROR));
        self::assertSame(2, self::main(['verify', '--snapshot=' . $snapshot, '--sha256=' . $digest[1]], self::neverRun(...))[0]);

        // A root that disappears is not "nothing changed".
        file_put_contents($snapshot, json_encode(['version' => \LANE_INTEGRITY_VERSION, 'roots' => ['git' => [], 'tree' => [$this->work . '/gone'], 'allow' => []], 'git' => [], 'tree' => []], JSON_THROW_ON_ERROR));
        self::assertSame(2, self::main(['verify', '--snapshot=' . $snapshot, '--sha256=' . hash_file('sha256', $snapshot)], self::neverRun(...))[0]);
    }

    #[Test]
    public function a_real_checkout_reports_a_second_write_to_a_dirty_file(): void
    {
        $root = $this->work . '/repo';
        mkdir($root);
        self::git($root, ['init', '-q']);
        file_put_contents($root . '/a.txt', 'one');
        self::git($root, ['add', 'a.txt']);
        file_put_contents($root . '/a.txt', 'two');
        file_put_contents($root . '/b.txt', 'untracked');
        $before = \laneIntegritySnapshot([$root], [], [], 'laneIntegrityRun');
        $state = $before['git'][\laneIntegrityNormalize($root)];

        self::assertSame(['?? b.txt', 'AM a.txt'], $state['status']);
        self::assertSame([], \laneIntegrityDiff($before, \laneIntegritySnapshot([$root], [], [], 'laneIntegrityRun')));

        file_put_contents($root . '/a.txt', 'three');
        file_put_contents($root . '/b.txt', 'rewritten');
        $changes = \laneIntegrityDiff($before, \laneIntegritySnapshot([$root], [], [], 'laneIntegrityRun'));
        $normalized = \laneIntegrityNormalize($root);

        self::assertSame([
            $normalized . ': tracked working-tree content changed',
            $normalized . '/a.txt: content changed',
            $normalized . '/b.txt: content changed',
        ], $changes);
    }

    #[Test]
    public function inherited_git_variables_do_not_redirect_the_check(): void
    {
        $root = $this->work . '/repo';
        mkdir($root);
        self::git($root, ['init', '-q']);
        file_put_contents($root . '/a.txt', 'one');
        self::git($root, ['add', 'a.txt']);

        // A hook exports variables like this one; the check must still read this checkout's own index.
        $previous = getenv('GIT_INDEX_FILE');
        putenv('GIT_INDEX_FILE=' . $this->work . '/elsewhere.index');
        try {
            $state = \laneIntegritySnapshot([$root], [], [], 'laneIntegrityRun')['git'][\laneIntegrityNormalize($root)];
        } finally {
            putenv($previous === false ? 'GIT_INDEX_FILE' : 'GIT_INDEX_FILE=' . $previous);
        }

        self::assertSame(['A  a.txt'], $state['status']);
    }

    #[Test]
    public function a_read_only_round_trip_on_this_repository_is_clean(): void
    {
        $root = dirname(__DIR__, 2);
        $before = \laneIntegritySnapshot([$root], [], [], 'laneIntegrityRun');
        $state = $before['git'][\laneIntegrityNormalize($root)];

        self::assertMatchesRegularExpression('#^[0-9a-f]{40}$#', $state['head']);
        self::assertSame([], \laneIntegrityDiff($before, \laneIntegritySnapshot([$root], [], [], 'laneIntegrityRun')));
    }

    /**
     * @param list<string> $command
     * @return array{0: int, 1: string}
     */
    private static function neverRun(array $command, string $cwd): array
    {
        self::fail('tree snapshots must not run commands');
    }

    /**
     * @param array<string, string|array{0: int, 1: string}> $outputs keyed by the git subcommand
     * @return \Closure(list<string>, string): array{0: int, 1: string}
     */
    private static function fakeGit(array $outputs): \Closure
    {
        return static function (array $command, string $cwd) use ($outputs): array {
            $key = match (true) {
                $command[1] === 'rev-parse' && in_array('--show-toplevel', $command, true) => 'toplevel',
                $command[1] === 'diff' && in_array('--cached', $command, true) => 'diff --cached',
                default => $command[1],
            };
            $result = $outputs[$key] ?? ($key === 'toplevel' ? $cwd . "\n" : '');

            return is_array($result) ? $result : [0, $result];
        };
    }

    /**
     * @param list<string> $arguments
     * @param callable(list<string>, string): array{0: int, 1: string} $run
     * @return array{0: int, 1: string}
     */
    private static function main(array $arguments, callable $run): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);
        $exit = \laneIntegrityMain(['lane-integrity.php', ...$arguments], $run, $out, $err);
        rewind($out);

        return [$exit, (string) stream_get_contents($out)];
    }

    /** @param list<string> $arguments */
    private static function git(string $root, array $arguments): void
    {
        $environment = array_fill_keys(['GIT_DIR', 'GIT_WORK_TREE', 'GIT_INDEX_FILE', 'GIT_COMMON_DIR', 'GIT_OBJECT_DIRECTORY', 'GIT_CONFIG_PARAMETERS', 'GIT_CONFIG_COUNT'], false);
        new Process(['git', '-C', $root, ...$arguments], null, $environment)->mustRun();
    }
}
