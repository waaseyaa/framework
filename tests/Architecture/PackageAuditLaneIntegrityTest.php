<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

require_once dirname(__DIR__, 2) . '/.agents/skills/waaseyaa-package-convergence/scripts/lane-integrity.php';

/**
 * FW-PACKAGE-CONVERGENCE-01 (#3118): the package-convergence skill's lane
 * write-isolation check (scripts/lane-integrity.php) must fail when anything
 * outside an allowed output area changes between a snapshot and a verify, and
 * must stay quiet when nothing did. Git state is exercised through an injected
 * command runner, so this test spawns no subprocess of its own except the one
 * real read-only round trip on this repository.
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
        new Filesystem()->remove($this->work);
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
        touch($tree . '/edited.txt', time() + 60);
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
    public function git_state_changes_are_reported_and_allowed_paths_are_ignored(): void
    {
        $root = $this->work . '/checkout';
        mkdir($root . '/scratch', 0o777, true);
        $allow = [$root . '/scratch'];

        $state = [
            'rev-parse' => "aaaa\n",
            'symbolic-ref' => "refs/heads/work\n",
            'status' => " M src/A.php\0?? scratch/lane.json\0",
            'stash' => '',
            'config' => "core.bare=false\n",
            'worktree' => "worktree /x\n",
        ];
        $before = \laneIntegritySnapshot([$root], [], $allow, self::fakeGit($state));
        self::assertSame([' M src/A.php'], $before['git'][\laneIntegrityNormalize($root)]['status'], 'allowed paths are not recorded');
        self::assertSame([], \laneIntegrityDiff($before, \laneIntegritySnapshot([$root], [], $allow, self::fakeGit($state))));

        $changed = [
            'rev-parse' => "bbbb\n",
            'status' => " M src/A.php\0 M src/B.php\0?? scratch/other.json\0",
            'stash' => "stash@{0}: WIP on work\n",
            'config' => "core.bare=false\ncore.worktree=/mnt/c/elsewhere\n",
        ] + $state;
        $changes = implode("\n", \laneIntegrityDiff($before, \laneIntegritySnapshot([$root], [], $allow, self::fakeGit($changed))));

        self::assertStringContainsString('HEAD moved', $changes);
        self::assertStringContainsString('now M src/B.php', $changes);
        self::assertStringContainsString('stash list changed', $changes);
        self::assertStringContainsString('repository config changed', $changes);
        self::assertStringNotContainsString('scratch', $changes);
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
     * @param array<string, string> $outputs keyed by the git subcommand
     * @return \Closure(list<string>, string): array{0: int, 1: string}
     */
    private static function fakeGit(array $outputs): \Closure
    {
        return static function (array $command, string $cwd) use ($outputs): array {
            $subcommand = $command[1] === 'config' ? 'config' : $command[1];

            return [0, $outputs[$subcommand] ?? ''];
        };
    }
}
