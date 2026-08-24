<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class WorktreeCoordinatorTest extends TestCase
{
    private string $root;
    private string $fixture;
    private string $repository;
    private string $git;
    private ?Process $activeProcess = null;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->fixture = sys_get_temp_dir() . '/waaseyaa-worktree-coordinator-' . bin2hex(random_bytes(6));
        $this->repository = $this->fixture . '/repository';
        $this->git = escapeshellarg($this->root . '/bin/git');
        mkdir($this->repository, 0o700, true);
        $this->git('init --quiet --initial-branch=main');
        $this->git('config user.email test@example.com');
        $this->git('config user.name "Worktree Coordinator Test"');
        file_put_contents($this->repository . '/tracked.txt', "baseline\n");
        $this->git('add tracked.txt');
        $this->git('commit --quiet -m baseline');
    }

    protected function tearDown(): void
    {
        // stop() sends SIGTERM and escalates to SIGKILL, matching the old
        // proc_terminate()/proc_close() pair; it is a no-op on a process that
        // was never started.
        $this->activeProcess?->stop();
        $this->activeProcess = null;
        $this->removeTree($this->fixture);
    }

    #[Test]
    public function inventory_is_machine_readable_and_every_protection_is_load_bearing(): void
    {
        $branch = $this->addWorktree('branch-safe', '-b branch-safe');
        $detachedSafe = $this->addWorktree('detached-safe', '--detach');
        $detachedUnique = $this->addWorktree('detached-unique', '--detach');
        $dirty = $this->addWorktree('dirty', '-b dirty');
        $active = $this->addWorktree('active', '-b active');
        $leased = $this->addWorktree('leased', '-b leased');
        $custody = $this->addWorktree('custody', '-b custody');
        $namedCustody = $this->addWorktree('named-custody', '-b canonical/accepted-fixture');
        $operation = $this->addWorktree('operation', '-b operation');
        $locked = $this->addWorktree('locked', '-b locked');
        $stale = $this->addWorktree('stale', '-b stale');
        $residue = $this->addWorktree('residue', '-b residue');

        $this->git('-C ' . escapeshellarg($detachedUnique) . ' commit --allow-empty --quiet -m unique');
        file_put_contents($dirty . '/tracked.txt', "dirty\n");
        file_put_contents($dirty . '/untracked.txt', "untracked\n");

        // A long-lived stand-in for an ACTIVE worktree: it must stay alive while
        // the coordinator is inspected below, so this is start()/stop(), never
        // run() (#2491). The command was already an array and its third element
        // is real shell content, so it stays exactly as it was. proc_open()
        // received no cwd and no env, so both are null — the child does its own
        // `cd`. Its stdin pipe was opened but never written, which Symfony's
        // null $input reproduces. timeout: null — never time-bounded before.
        $this->activeProcess = new Process(
            ['bash', '-lc', 'cd ' . escapeshellarg($active) . ' && echo ready && exec sleep 30'],
            null,
            null,
            null,
            null,
        );
        $this->activeProcess->start();

        // Bounded replacement for the blocking fgets() readiness handshake.
        // Liveness is sampled BEFORE the drain so a child that announces and
        // exits in between is still credited with its announcement.
        $announcement = '';
        $deadline = microtime(true) + 10.0;
        while (!str_contains($announcement, "\n")) {
            $running = $this->activeProcess->isRunning();
            $announcement .= $this->activeProcess->getIncrementalOutput();
            if (str_contains($announcement, "\n")) {
                break;
            }
            self::assertTrue($running, 'Stand-in process exited before announcing readiness: ' . $this->activeProcess->getErrorOutput());
            self::assertLessThan($deadline, microtime(true), 'Timed out waiting for the stand-in process to announce readiness.');
            usleep(10_000);
        }
        self::assertSame("ready\n", substr($announcement, 0, (int) strpos($announcement, "\n") + 1));

        $this->release($branch, 'branch-owner', 'disposable');
        $this->release($detachedSafe, 'detached-owner', 'disposable');
        $this->release($detachedUnique, 'unique-owner', 'disposable');
        $this->release($dirty, 'dirty-owner', 'disposable');
        $this->release($active, 'active-owner', 'disposable');
        $this->acquire($leased, 'lease-owner', 'disposable');
        $this->release($custody, 'custody-owner', 'custody');
        $this->release($namedCustody, 'named-custody-owner', 'disposable');
        $this->release($operation, 'operation-owner', 'disposable');
        $this->release($locked, 'locked-owner', 'disposable');
        $this->release($stale, 'stale-owner', 'disposable');
        $this->release($residue, 'residue-owner', 'disposable');

        [, $mergeHead] = $this->git('-C ' . escapeshellarg($operation) . ' rev-parse --git-path MERGE_HEAD');
        file_put_contents(trim($mergeHead), trim($this->git('-C ' . escapeshellarg($operation) . ' rev-parse HEAD')[1]) . "\n");
        $this->git('worktree lock --reason=fixture ' . escapeshellarg($locked));

        $this->removeTree($stale);
        $this->git('worktree remove ' . escapeshellarg($residue));
        mkdir($residue, 0o700, true);
        file_put_contents($residue . '/root-owned-residue.fixture', 'simulated residue');

        [$code, $json] = $this->coordinator([
            'inventory',
            '--repo=' . $this->repository,
            '--format=json',
        ]);
        self::assertSame(0, $code, $json);
        $inventory = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('waaseyaa.worktree-inventory.v1', $inventory['schema']);
        $byPath = [];
        foreach ($inventory['worktrees'] as $entry) {
            $byPath[$entry['path']] = $entry;
        }

        self::assertTrue($byPath[$branch]['cleanup_eligible']);
        self::assertTrue($byPath[$detachedSafe]['cleanup_eligible']);
        self::assertProtectedBy($byPath[$detachedUnique], 'detached_unique_commits');
        self::assertProtectedBy($byPath[$dirty], 'dirty');
        self::assertProtectedBy($byPath[$active], 'live_process');
        self::assertNotEmpty($byPath[$active]['processes']);
        self::assertProtectedBy($byPath[$leased], 'active_lease');
        self::assertProtectedBy($byPath[$custody], 'custody_lifecycle');
        self::assertProtectedBy($byPath[$namedCustody], 'custody_naming');
        self::assertProtectedBy($byPath[$operation], 'active_git_operation');
        self::assertProtectedBy($byPath[$locked], 'locked_worktree');
        self::assertProtectedBy($byPath[$stale], 'registered_path_missing');
        self::assertProtectedBy($byPath[$residue], 'unregistered_residue');
        self::assertProtectedBy($byPath[$this->repository], 'unknown_ownership');

        [$humanCode, $human] = $this->coordinator(['inventory', '--repo=' . $this->repository]);
        self::assertSame(0, $humanCode, $human);
        self::assertStringContainsString('cleanup eligible: 2', $human);
        self::assertStringContainsString('protected:', $human);
    }

    #[Test]
    public function cleanup_is_plan_first_exact_and_revalidated(): void
    {
        $safe = $this->addWorktree('safe', '-b safe');
        $drifts = $this->addWorktree('drifts', '-b drifts');
        $safeLease = $this->release($safe, 'safe-owner', 'disposable');
        $this->release($drifts, 'drift-owner', 'disposable');
        $manifest = $this->fixture . '/cleanup-manifest.json';

        [$planCode, $planOutput] = $this->coordinator([
            'cleanup', 'plan',
            '--repo=' . $this->repository,
            '--output=' . $manifest,
        ]);
        self::assertSame(0, $planCode, $planOutput);
        self::assertFileExists($manifest);
        self::assertDirectoryExists($safe, 'Planning must be dry-run only.');
        $plan = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('waaseyaa.worktree-cleanup.v1', $plan['schema']);
        self::assertSame([$drifts, $safe], array_column($plan['items'], 'path'));
        self::assertSame($safeLease, $plan['items'][1]['lease_id']);

        file_put_contents($drifts . '/untracked-after-plan.txt', 'drift');
        [$applyCode, $applyOutput] = $this->coordinator(['cleanup', 'apply', '--manifest=' . $manifest]);
        self::assertSame(2, $applyCode, $applyOutput);
        self::assertStringContainsString('inventory drift', $applyOutput);
        self::assertDirectoryDoesNotExist($safe);
        self::assertDirectoryExists($drifts);
        self::assertStringContainsString('removed', $applyOutput);
        self::assertStringContainsString('skipped-drift', $applyOutput);
        self::assertSame(0, $this->git('rev-parse --verify safe')[0], 'Cleanup must not delete refs.');

        [$repeatCode, $repeatOutput] = $this->coordinator([
            'cleanup', 'plan',
            '--repo=' . $this->repository,
            '--output=' . $manifest,
        ]);
        self::assertNotSame(0, $repeatCode, $repeatOutput);
        self::assertStringContainsString('already exists', $repeatOutput);

        [$unsafeCode, $unsafeOutput] = $this->coordinator([
            'cleanup', 'apply',
            '--manifest=$HOME/*.json',
        ]);
        self::assertNotSame(0, $unsafeCode, $unsafeOutput);
        self::assertStringContainsString('absolute literal path', $unsafeOutput);
    }

    #[Test]
    public function a_concurrent_lease_blocks_a_previous_cleanup_plan_until_released(): void
    {
        $path = $this->addWorktree('concurrent', '-b concurrent');
        $releasedId = $this->release($path, 'workflow', 'disposable');
        $manifest = $this->fixture . '/concurrent.json';
        self::assertSame(0, $this->coordinator([
            'cleanup', 'plan', '--repo=' . $this->repository, '--output=' . $manifest,
        ])[0]);

        $activeId = $this->acquire($path, 'workflow-2', 'disposable');
        self::assertNotSame($releasedId, $activeId);
        [$blockedCode, $blockedOutput] = $this->coordinator(['cleanup', 'apply', '--manifest=' . $manifest]);
        self::assertSame(2, $blockedCode, $blockedOutput);
        self::assertDirectoryExists($path);
        self::assertStringContainsString('active_lease', $blockedOutput);

        self::assertSame(0, $this->coordinator([
            'lease', 'release', '--repo=' . $this->repository, '--path=' . $path, '--lease-id=' . $activeId,
        ])[0]);
    }

    #[Test]
    public function a_filesystem_removal_failure_is_reported_from_observed_post_state(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Permission refusal cannot be reproduced as root.');
        }
        $path = $this->addWorktree('permission-residue', '-b permission-residue');
        mkdir($path . '/blocked');
        file_put_contents($path . '/blocked/tracked.txt', 'blocked');
        $this->git('-C ' . escapeshellarg($path) . ' add blocked/tracked.txt');
        $this->git('-C ' . escapeshellarg($path) . ' commit --quiet -m blocked');
        chmod($path . '/blocked', 0o500);
        $this->release($path, 'permission-owner', 'disposable');
        $manifest = $this->fixture . '/permission.json';
        self::assertSame(0, $this->coordinator([
            'cleanup', 'plan', '--repo=' . $this->repository, '--output=' . $manifest,
        ])[0]);

        [$code, $output] = $this->coordinator(['cleanup', 'apply', '--manifest=' . $manifest]);
        chmod($path . '/blocked', 0o700);
        self::assertSame(2, $code, $output);
        self::assertStringNotContainsString('removed ' . $path . "\n", $output);
        $stillRegistered = $this->git(
            'worktree list --porcelain | grep -F ' . escapeshellarg('worktree ' . $path),
            true,
        )[0] === 0;
        if ($stillRegistered) {
            self::assertStringContainsString('failed-no-change ' . $path, $output);
        } else {
            self::assertStringContainsString('partial-residue ' . $path, $output);
            self::assertDirectoryExists($path);
        }
    }

    private static function assertProtectedBy(array $entry, string $reason): void
    {
        self::assertFalse($entry['cleanup_eligible'], json_encode($entry, JSON_PRETTY_PRINT));
        self::assertContains($reason, $entry['protection_reasons'], json_encode($entry, JSON_PRETTY_PRINT));
    }

    private function addWorktree(string $name, string $arguments): string
    {
        $path = $this->fixture . '/' . $name;
        $this->git('worktree add --quiet ' . $arguments . ' ' . escapeshellarg($path) . ' HEAD');

        return $path;
    }

    private function acquire(string $path, string $owner, string $lifecycle): string
    {
        [$code, $output] = $this->coordinator([
            'lease', 'acquire',
            '--repo=' . $this->repository,
            '--path=' . $path,
            '--owner=' . $owner,
            '--agent=phpunit',
            '--task=fixture',
            '--lifecycle=' . $lifecycle,
            '--format=json',
        ]);
        self::assertSame(0, $code, $output);
        $record = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        return $record['lease_id'];
    }

    private function release(string $path, string $owner, string $lifecycle): string
    {
        $leaseId = $this->acquire($path, $owner, $lifecycle);
        [$code, $output] = $this->coordinator([
            'lease', 'release',
            '--repo=' . $this->repository,
            '--path=' . $path,
            '--lease-id=' . $leaseId,
        ]);
        self::assertSame(0, $code, $output);

        return $leaseId;
    }

    /** @return array{int, string} */
    private function coordinator(array $arguments): array
    {
        $command = array_merge([$this->root . '/bin/worktree-coordinator'], $arguments);
        $escaped = implode(' ', array_map('escapeshellarg', $command));
        exec($escaped . ' 2>&1', $lines, $code);

        return [$code, implode("\n", $lines)];
    }

    /** @return array{int, string} */
    private function git(string $arguments, bool $allowFailure = false): array
    {
        exec('cd ' . escapeshellarg($this->repository) . ' && ' . $this->git . ' ' . $arguments . ' 2>&1', $lines, $code);
        $output = implode("\n", $lines);
        if (!$allowFailure) {
            self::assertSame(0, $code, $output);
        }

        return [$code, $output];
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @chmod($path, 0o700);
            @unlink($path);
            return;
        }
        @chmod($path, 0o700);
        $entries = scandir($path);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path . '/' . $entry);
                }
            }
        }
        @rmdir($path);
    }
}
