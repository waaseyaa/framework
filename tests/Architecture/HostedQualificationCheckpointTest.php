<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class HostedQualificationCheckpointTest extends TestCase
{
    private string $root;
    private string $scratch;
    private string $repository;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->scratch = sys_get_temp_dir() . '/waaseyaa-hosted-checkpoint-' . bin2hex(random_bytes(6));
        $this->repository = $this->scratch . '/candidate';
        $remote = $this->scratch . '/remote.git';
        new Filesystem()->mkdir([$this->repository, $remote]);

        $this->git($remote, ['init', '--bare']);
        $this->git($this->repository, ['init', '--initial-branch=checkpoint']);
        $this->git($this->repository, ['config', 'user.name', 'Checkpoint Test']);
        $this->git($this->repository, ['config', 'user.email', 'checkpoint@example.test']);
        file_put_contents($this->repository . '/candidate.txt', "candidate\n");
        $this->git($this->repository, ['add', 'candidate.txt']);
        $this->git($this->repository, ['commit', '-m', 'candidate']);
        $this->git($this->repository, ['remote', 'add', 'origin', $remote]);
        $this->git($this->repository, ['push', '--set-upstream', 'origin', 'checkpoint']);
    }

    protected function tearDown(): void
    {
        $filesystem = new Filesystem();
        $filesystem->chmod($this->scratch, 0o755, 0o000, true);
        $filesystem->remove($this->scratch);
    }

    #[Test]
    public function dry_run_accepts_only_the_clean_pushed_exact_head(): void
    {
        [$exit, $stdout, $stderr] = $this->checkpoint();

        self::assertSame(0, $exit, $stdout . $stderr);
        self::assertStringContainsString('remote ref: origin/checkpoint', $stdout);
        self::assertStringContainsString('hosted owner: ci/full-qualification', $stdout);
        self::assertStringContainsString('no workflow was started', $stdout);
    }

    #[Test]
    public function a_dirty_candidate_is_refused_before_dispatch(): void
    {
        file_put_contents($this->repository . '/candidate.txt', "dirty\n");
        [$exit, $stdout, $stderr] = $this->checkpoint();

        self::assertSame(3, $exit, $stdout . $stderr);
        self::assertStringContainsString('worktree must be clean', $stderr);
    }

    #[Test]
    public function an_unpushed_candidate_is_refused_before_dispatch(): void
    {
        file_put_contents($this->repository . '/candidate.txt', "next\n");
        $this->git($this->repository, ['add', 'candidate.txt']);
        $this->git($this->repository, ['commit', '-m', 'next']);
        [$exit, $stdout, $stderr] = $this->checkpoint();

        self::assertSame(3, $exit, $stdout . $stderr);
        self::assertStringContainsString('is not the exact local HEAD', $stderr);
    }

    /** @return array{int,string,string} */
    private function checkpoint(): array
    {
        $process = new Process([
            PHP_BINARY,
            $this->root . '/bin/start-hosted-qualification',
            '--repository=' . $this->repository,
            '--github-repository=waaseyaa/framework',
            '--dry-run',
        ], $this->root, timeout: 30);
        $exit = $process->run();

        return [(int) $exit, $process->getOutput(), $process->getErrorOutput()];
    }

    private function git(string $directory, array $arguments): void
    {
        $process = new Process(['git', '-C', $directory, ...$arguments], $this->root, timeout: 30);
        self::assertSame(0, $process->run(), $process->getOutput() . $process->getErrorOutput());
    }
}
