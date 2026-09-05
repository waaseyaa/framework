<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Adversarial evidence for #2902: two independent appends to one JSONL ledger
 * produce a textual merge conflict. Immutable batch files are the proposed
 * remedy (design under Codex review — not implemented here).
 */
#[CoversNothing]
final class DeliveryAgentEventBatchContentionFixtureTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/waaseyaa_batch_contention_' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o755, true);
    }

    protected function tearDown(): void
    {
        if ($this->directory === '' || !is_dir($this->directory)) {
            return;
        }
        new \Symfony\Component\Filesystem\Filesystem()->remove($this->directory);
    }

    #[Test]
    public function concurrent_appends_to_one_jsonl_tail_conflict_under_either_merge_order(): void
    {
        $repo = $this->directory . '/repo';
        mkdir($repo);
        $this->git($repo, 'init', '-q');
        $this->git($repo, 'config', 'user.email', 'fixture@example.com');
        $this->git($repo, 'config', 'user.name', 'Batch Contention Fixture');

        $ledger = 'ops/observability/delivery-agent-events-v1.jsonl';
        mkdir($repo . '/ops/observability', 0o755, true);
        $baseEvent = $this->event('11111111-1111-4111-8111-111111111111', '2026-09-03T20:00:00Z');
        file_put_contents($repo . '/' . $ledger, $baseEvent);
        $this->git($repo, 'add', $ledger);
        $this->git($repo, 'commit', '-q', '-m', 'base ledger');

        $this->git($repo, 'branch', 'lane-a');
        $this->git($repo, 'branch', 'lane-b');

        $this->git($repo, 'checkout', '-q', 'lane-a');
        file_put_contents(
            $repo . '/' . $ledger,
            $baseEvent . $this->event('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', '2026-09-03T20:01:00Z'),
        );
        $this->git($repo, 'add', $ledger);
        $this->git($repo, 'commit', '-q', '-m', 'lane A evidence');

        $this->git($repo, 'checkout', '-q', 'lane-b');
        file_put_contents(
            $repo . '/' . $ledger,
            $baseEvent . $this->event('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', '2026-09-03T20:02:00Z'),
        );
        $this->git($repo, 'add', $ledger);
        $this->git($repo, 'commit', '-q', '-m', 'lane B evidence');

        $ab = $this->tryMerge($repo, 'lane-a', 'into-ab');
        $ba = $this->tryMerge($repo, 'lane-b', 'into-ba', 'lane-a');

        self::assertSame(1, $ab['exit'], 'A-then-B should conflict on the shared JSONL: ' . $ab['output']);
        self::assertSame(1, $ba['exit'], 'B-then-A should conflict on the shared JSONL: ' . $ba['output']);
        self::assertTrue(
            str_contains($ab['output'], 'CONFLICT') || str_contains($ab['output'], 'conflict'),
            $ab['output'],
        );
        self::assertTrue(
            str_contains($ba['output'], 'CONFLICT') || str_contains($ba['output'], 'conflict'),
            $ba['output'],
        );
    }

    #[Test]
    public function independent_batch_paths_merge_cleanly_in_either_order(): void
    {
        $repo = $this->directory . '/batches';
        mkdir($repo);
        $this->git($repo, 'init', '-q');
        $this->git($repo, 'config', 'user.email', 'fixture@example.com');
        $this->git($repo, 'config', 'user.name', 'Batch Contention Fixture');

        $dir = 'ops/observability/delivery-agent-batches-v1';
        mkdir($repo . '/' . $dir, 0o755, true);
        file_put_contents($repo . '/' . $dir . '/.gitkeep', '');
        $this->git($repo, 'add', $dir . '/.gitkeep');
        $this->git($repo, 'commit', '-q', '-m', 'batch directory');

        $this->git($repo, 'branch', 'lane-a');
        $this->git($repo, 'branch', 'lane-b');

        $this->git($repo, 'checkout', '-q', 'lane-a');
        file_put_contents($repo . '/' . $dir . '/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa.json', "{\"batch_id\":\"aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa\"}\n");
        $this->git($repo, 'add', $dir . '/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa.json');
        $this->git($repo, 'commit', '-q', '-m', 'batch A');

        $this->git($repo, 'checkout', '-q', 'lane-b');
        file_put_contents($repo . '/' . $dir . '/bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb.json', "{\"batch_id\":\"bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb\"}\n");
        $this->git($repo, 'add', $dir . '/bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb.json');
        $this->git($repo, 'commit', '-q', '-m', 'batch B');

        $ab = $this->tryMerge($repo, 'lane-a', 'into-ab');
        self::assertSame(0, $ab['exit'], $ab['output']);

        $this->git($repo, 'checkout', '-q', 'lane-a');
        $ba = $this->tryMerge($repo, 'lane-b', 'into-ba');
        self::assertSame(0, $ba['exit'], $ba['output']);

        foreach (['into-ab', 'into-ba'] as $branch) {
            $this->git($repo, 'checkout', '-q', $branch);
            self::assertFileExists($repo . '/' . $dir . '/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa.json');
            self::assertFileExists($repo . '/' . $dir . '/bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb.json');
        }
    }

    /** @return array{exit: int, output: string} */
    private function tryMerge(string $repo, string $theirs, string $resultBranch, ?string $oursBase = null): array
    {
        if ($oursBase !== null) {
            $this->git($repo, 'checkout', '-q', '-b', $resultBranch, $oursBase);
        } else {
            $this->git($repo, 'checkout', '-q', '-b', $resultBranch);
        }
        exec(
            'cd ' . escapeshellarg($repo) . ' && git merge --no-edit ' . escapeshellarg($theirs) . ' 2>&1',
            $lines,
            $code,
        );
        if ($code !== 0) {
            exec('cd ' . escapeshellarg($repo) . ' && git merge --abort 2>&1');
        }

        return ['exit' => $code, 'output' => implode("\n", $lines)];
    }

    private function event(string $id, string $recordedAt): string
    {
        $payload = [
            'schema_version' => 'delivery-agent-event/v1',
            'event_id' => $id,
            'event_type' => 'review_started',
            'recorded_at' => $recordedAt,
            'occurred_at' => null,
            'repository' => 'waaseyaa/framework',
            'pull_request' => 2902,
            'head_sha' => str_repeat('a', 40),
            'actor' => ['kind' => 'cursor', 'name' => 'Cursor', 'model' => null],
            'evidence_kind' => 'observed',
            'causation_event_id' => null,
            'review_depth' => null,
            'outcome' => null,
            'finding_count' => null,
            'token_count' => null,
            'elapsed_ms' => null,
            'source_url' => null,
            'notes' => null,
            'verification' => null,
            'adjudication' => null,
        ];

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    private function git(string $repo, string ...$arguments): void
    {
        $command = 'cd ' . escapeshellarg($repo) . ' && git';
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }
        exec($command . ' 2>&1', $lines, $code);
        self::assertSame(0, $code, implode("\n", $lines));
    }
}
