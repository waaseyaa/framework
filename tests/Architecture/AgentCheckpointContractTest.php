<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class AgentCheckpointContractTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/waaseyaa_agent_checkpoint_' . bin2hex(random_bytes(6));
        mkdir($this->fixture . '/evidence', 0o755, true);
        $this->initializeRepository($this->fixture . '/active');
        $this->initializeRepository($this->fixture . '/readonly');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->fixture);
    }

    #[Test]
    public function oversized_evidence_returns_a_bounded_hashed_manifest_with_live_state(): void
    {
        $report = $this->fixture . '/evidence/reconciliation.md';
        file_put_contents($report, str_repeat('full evidence line\n', 8_000));
        file_put_contents($this->fixture . '/active/untracked.txt', 'active edit');

        $process = new Process([
            PHP_BINARY,
            dirname(__DIR__, 2) . '/bin/agent-checkpoint',
            '--task=synthetic oversized reconciliation',
            '--verdict=in-progress',
            '--cwd=' . $this->fixture . '/active',
            '--evidence-dir=' . $this->fixture . '/evidence',
            '--evidence=disposable-local:' . $report,
            '--worktree=task-active-edit:' . $this->fixture . '/active',
            '--worktree=read-only-evidence:' . $this->fixture . '/readonly',
            '--pid=live-test-runner:' . getmypid(),
            '--pid=stale-terminal:999999999',
            '--pin=framework:' . str_repeat('a', 40),
            '--mutation=github:created-pr:waaseyaa/framework#999',
            '--next=resume from checkpoint without replaying terminal history',
        ]);
        $exit = $process->run();

        self::assertSame(0, $exit, $process->getErrorOutput());
        self::assertLessThan(16_384, strlen($process->getOutput()));
        $document = json_decode($process->getOutput(), flags: JSON_THROW_ON_ERROR);
        $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/tools/agent-checkpoint.schema.json'), flags: JSON_THROW_ON_ERROR);
        self::assertTrue(new Validator()->validate($document, $schema)->isValid());
        $manifest = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('in-progress', $manifest['verdict']);
        self::assertSame(hash_file('sha256', $report), $manifest['evidence'][0]['sha256']);
        self::assertGreaterThan(100_000, $manifest['evidence'][0]['bytes']);
        self::assertSame('task-active-edit', $manifest['worktrees'][0]['role']);
        self::assertTrue($manifest['worktrees'][0]['task_created']);
        self::assertSame(1, $manifest['worktrees'][0]['porcelain']['untracked']);
        self::assertSame('read-only-evidence', $manifest['worktrees'][1]['role']);
        self::assertFalse($manifest['worktrees'][1]['task_created']);
        self::assertTrue($manifest['processes'][0]['alive']);
        self::assertFalse($manifest['processes'][1]['alive']);
        self::assertSame('created-pr', $manifest['mutations'][0]['action']);
        self::assertFileExists($this->fixture . '/evidence/checkpoint.json');
        self::assertFileExists($report, 'Checkpoint serialization must not consume or discard report evidence.');
    }

    #[Test]
    public function it_refuses_ambiguous_multiple_active_edit_targets(): void
    {
        $process = new Process([
            PHP_BINARY,
            dirname(__DIR__, 2) . '/bin/agent-checkpoint',
            '--task=ambiguous worktrees',
            '--verdict=blocked',
            '--cwd=' . $this->fixture . '/active',
            '--evidence-dir=' . $this->fixture . '/evidence',
            '--worktree=active-edit:' . $this->fixture . '/active',
            '--worktree=task-active-edit:' . $this->fixture . '/readonly',
        ]);

        self::assertSame(2, $process->run());
        self::assertStringContainsString('at most one worktree', $process->getErrorOutput());
    }

    #[Test]
    public function failed_manifest_generation_leaves_completed_evidence_intact(): void
    {
        $report = $this->fixture . '/evidence/completed-report.md';
        $contents = str_repeat('retained evidence\n', 500);
        file_put_contents($report, $contents);
        $process = new Process([
            PHP_BINARY,
            dirname(__DIR__, 2) . '/bin/agent-checkpoint',
            '--task=invalid bounded return',
            '--verdict=complete',
            '--cwd=' . $this->fixture . '/active',
            '--evidence-dir=' . $this->fixture . '/evidence',
            '--evidence=disposable-local:' . $report,
            '--next=' . str_repeat('x', 1_001),
        ]);

        self::assertSame(2, $process->run());
        self::assertStringContainsString('next authorized action', $process->getErrorOutput());
        self::assertSame($contents, file_get_contents($report));
        self::assertFileDoesNotExist($this->fixture . '/evidence/checkpoint.json');
    }

    private function initializeRepository(string $path): void
    {
        mkdir($path, 0o755, true);
        file_put_contents($path . '/tracked.txt', 'checkpoint fixture');
        foreach ([
            ['git', 'init', '--quiet', $path],
            ['git', '-C', $path, 'config', 'user.name', 'Checkpoint Test'],
            ['git', '-C', $path, 'config', 'user.email', 'checkpoint@example.test'],
            ['git', '-C', $path, 'add', 'tracked.txt'],
            ['git', '-C', $path, 'commit', '--quiet', '-m', 'fixture'],
        ] as $command) {
            $process = new Process($command);
            self::assertSame(0, $process->run(), $process->getErrorOutput());
        }
    }
}
