<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Executable #2902 proofs through the real delivery-agent gate: commutative
 * batch merges, topo replay for equal-time causes, timezone-normalized
 * timestamps, cross-batch adjudication, and freeze immutability.
 */
#[CoversNothing]
final class DeliveryAgentEventBatchGateTest extends TestCase
{
    private string $root;

    /** @var list<string> */
    private array $fixtures = [];

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/bin/lib/delivery-agent-event-set.php';
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $fixture) {
            new \Symfony\Component\Filesystem\Filesystem()->remove($fixture);
        }
    }

    #[Test]
    public function equal_time_effect_sorts_after_its_cause_under_topo_replay(): void
    {
        $cause = $this->event('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'verification_finding_issued', '2026-09-03T20:00:00Z');
        $cause['verification'] = [
            'verifier_id' => 'probe',
            'check_type' => 'review_probe',
            'claim' => 'c',
            'observed' => 'o',
            'candidate_defect_claimed' => true,
            'safety_effect' => 'fail_closed',
        ];
        $effect = $this->event(
            'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'verification_finding_adjudicated',
            '2026-09-03T20:00:00Z',
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        );
        $effect['adjudication'] = [
            'classification' => 'false_positive',
            'candidate_defect_confirmed' => false,
            'rationale' => 'fixture',
            'adjudicated_by' => 'Cursor',
        ];
        $effect['actor'] = ['kind' => 'claude', 'name' => 'Cursor', 'model' => null];

        // Deliberately list effect before cause in the batch-event array.
        $replay = delivery_agent_replay_events([], [$effect, $cause]);
        self::assertSame([], $replay['errors']);
        self::assertSame(
            ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'],
            array_column($replay['events'], 'event_id'),
        );
    }

    #[Test]
    public function timezone_equivalent_recorded_at_values_compare_equal_for_replay_keys(): void
    {
        self::assertSame(
            delivery_agent_normalize_timestamp('2026-09-03T20:00:00Z'),
            delivery_agent_normalize_timestamp('2026-09-03T16:00:00-04:00'),
        );
    }

    #[Test]
    public function two_batch_paths_pass_the_real_gate_in_either_merge_order(): void
    {
        $repo = $this->seedFrozenAuthorityRepo();
        $base = $this->sha($repo);

        $batchA = $this->batchFile(
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            [$this->event('11111111-1111-4111-8111-111111111111', 'review_started', '2026-09-04T01:00:00Z')],
        );
        $batchB = $this->batchFile(
            'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            [$this->event('22222222-2222-4222-8222-222222222222', 'review_started', '2026-09-04T02:00:00Z')],
        );

        $this->git($repo, ['checkout', '-q', '-b', 'lane-a']);
        $this->writeBatch($repo, $batchA);
        $headA = $this->commitAll($repo, 'batch A');

        $this->git($repo, ['checkout', '-q', '-b', 'lane-b', $base]);
        $this->writeBatch($repo, $batchB);
        $headB = $this->commitAll($repo, 'batch B');

        $this->git($repo, ['checkout', '-q', '-b', 'merge-ab', $headA]);
        exec('cd ' . escapeshellarg($repo) . ' && git merge --no-edit ' . escapeshellarg($headB) . ' 2>&1', $abOut, $abCode);
        self::assertSame(0, $abCode, implode("\n", $abOut));
        $gateAb = $this->gate($repo, []);
        self::assertSame(0, $gateAb['exit'], $gateAb['stderr'] . $gateAb['stdout']);

        $this->git($repo, ['checkout', '-q', '-b', 'merge-ba', $headB]);
        exec('cd ' . escapeshellarg($repo) . ' && git merge --no-edit ' . escapeshellarg($headA) . ' 2>&1', $baOut, $baCode);
        self::assertSame(0, $baCode, implode("\n", $baOut));
        $gateBa = $this->gate($repo, []);
        self::assertSame(0, $gateBa['exit'], $gateBa['stderr'] . $gateBa['stdout']);

        $replayAb = $this->replayIdsFromWorktree($repo);
        $this->git($repo, ['checkout', '-q', 'merge-ab']);
        $replayBaOnAb = $this->replayIdsFromWorktree($repo);
        self::assertSame($replayAb, $replayBaOnAb);
    }

    /** @return list<string> */
    private function replayIdsFromWorktree(string $repo): array
    {
        $ledger = (string) file_get_contents($repo . '/ops/observability/delivery-agent-events-v1.jsonl');
        $v1 = delivery_agent_parse_v1_ledger_events($ledger);
        $loaded = delivery_agent_load_batch_files_from_directory($repo . '/ops/observability/delivery-agent-batches-v1');
        $batchSchema = json_decode(
            (string) file_get_contents($repo . '/ops/observability/delivery-agent-batch-v1.schema.json'),
            flags: JSON_THROW_ON_ERROR,
        );
        $eventSchema = json_decode(
            (string) file_get_contents($repo . '/ops/observability/delivery-agent-event-v1.schema.json'),
            flags: JSON_THROW_ON_ERROR,
        );
        $parsed = delivery_agent_parse_batch_files($loaded['batches'], $batchSchema, $eventSchema);
        $replay = delivery_agent_replay_events($v1, $parsed['events']);

        return array_values(array_map(
            static fn(array $event): string => (string) $event['event_id'],
            $replay['events'],
        ));
    }

    #[Test]
    public function cross_batch_adjudication_is_accepted_and_duplicate_adjudication_is_refused(): void
    {
        $repo = $this->seedFrozenAuthorityRepo();
        $finding = $this->event('44444444-4444-4444-8444-444444444444', 'verification_finding_issued', '2026-09-04T03:00:00Z');
        $finding['verification'] = [
            'verifier_id' => 'probe',
            'check_type' => 'review_probe',
            'claim' => 'c',
            'observed' => 'o',
            'candidate_defect_claimed' => true,
            'safety_effect' => 'fail_closed',
        ];
        $adj = $this->event(
            '55555555-5555-4555-8555-555555555555',
            'verification_finding_adjudicated',
            '2026-09-04T03:01:00Z',
            '44444444-4444-4444-8444-444444444444',
        );
        $adj['adjudication'] = [
            'classification' => 'false_positive',
            'candidate_defect_confirmed' => false,
            'rationale' => 'ok',
            'adjudicated_by' => 'Cursor',
        ];
        $this->writeBatch($repo, $this->batchFile('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', [$finding]));
        $this->writeBatch($repo, $this->batchFile('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', [$adj]));
        $this->commitAll($repo, 'cross-batch adjudication');
        $ok = $this->gate($repo, []);
        self::assertSame(0, $ok['exit'], $ok['stderr'] . $ok['stdout']);

        $dup = $this->event(
            '66666666-6666-4666-8666-666666666666',
            'verification_finding_adjudicated',
            '2026-09-04T03:02:00Z',
            '44444444-4444-4444-8444-444444444444',
        );
        $dup['adjudication'] = $adj['adjudication'];
        $this->writeBatch($repo, $this->batchFile('cccccccc-cccc-4ccc-8ccc-cccccccccccc', [$dup]));
        $this->commitAll($repo, 'duplicate adjudication');
        $bad = $this->gate($repo, []);
        self::assertSame(1, $bad['exit'], $bad['stderr']);
        self::assertStringContainsString('conflicting adjudications', $bad['stderr']);
    }

    #[Test]
    public function frozen_v1_ledger_refuses_growth_after_cutover(): void
    {
        $repo = $this->seedFrozenAuthorityRepo();
        file_put_contents(
            $repo . '/ops/observability/delivery-agent-events-v1.jsonl',
            (string) file_get_contents($repo . '/ops/observability/delivery-agent-events-v1.jsonl')
            . json_encode($this->event('99999999-9999-4999-8999-999999999999', 'review_started', '2099-01-01T00:00:00Z'), JSON_UNESCAPED_SLASHES) . "\n",
        );
        $bad = $this->gate($repo, []);
        self::assertSame(1, $bad['exit'], $bad['stderr']);
        self::assertStringContainsString('frozen v1 ledger', $bad['stderr']);
    }

    #[Test]
    public function accepted_batch_modification_fails_branch_mode_against_merge_base(): void
    {
        $repo = $this->seedFrozenAuthorityRepo();
        $this->writeBatch(
            $repo,
            $this->batchFile(
                'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                [$this->event('11111111-1111-4111-8111-111111111111', 'review_started', '2026-09-04T01:00:00Z')],
            ),
        );
        $base = $this->commitAll($repo, 'accepted batch');
        $this->git($repo, ['checkout', '-q', '-b', 'tamper']);
        $path = $repo . '/ops/observability/delivery-agent-batches-v1/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa.json';
        file_put_contents($path, str_replace('"review_started"', '"repair_started"', (string) file_get_contents($path)));
        $this->commitAll($repo, 'tamper batch');
        $result = $this->gate($repo, ['--branch-base=' . $base]);
        self::assertSame(1, $result['exit'], $result['stderr']);
        self::assertStringContainsString('accepted batch modified', $result['stderr']);
    }

    private function seedFrozenAuthorityRepo(): string
    {
        $repo = sys_get_temp_dir() . '/waaseyaa-batch-gate-' . bin2hex(random_bytes(6));
        mkdir($repo . '/ops/observability/delivery-agent-batches-v1', 0o777, true);
        mkdir($repo . '/bin/lib', 0o777, true);
        foreach ([
            'ops/observability/delivery-agent-event-v1.schema.json',
            'ops/observability/delivery-agent-events-v1.jsonl',
            'ops/observability/delivery-agent-batch-v1.schema.json',
            'ops/observability/delivery-agent-v1-freeze.json',
            'bin/check-delivery-agent-events',
            'bin/lib/delivery-agent-event-set.php',
            'bin/git',
        ] as $path) {
            self::assertTrue(copy($this->root . '/' . $path, $repo . '/' . $path));
        }
        chmod($repo . '/bin/check-delivery-agent-events', 0o755);
        chmod($repo . '/bin/git', 0o755);
        symlink($this->root . '/vendor', $repo . '/vendor');
        $this->fixtures[] = $repo;

        $this->git($repo, ['init', '--quiet', '--initial-branch=main']);
        $this->git($repo, ['config', 'user.name', 'Batch Gate Fixture']);
        $this->git($repo, ['config', 'user.email', 'batch-gate@example.invalid']);
        $this->git($repo, ['add', 'ops', 'bin', 'vendor']);
        $this->git($repo, ['commit', '--quiet', '-m', 'frozen authority']);

        return $repo;
    }

    /** @param array<string, mixed> $batch */
    private function writeBatch(string $repo, array $batch): void
    {
        $dir = $repo . '/ops/observability/delivery-agent-batches-v1';
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        $path = $dir . '/' . $batch['batch_id'] . '.json';
        file_put_contents($path, json_encode($batch, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private function batchFile(string $batchId, array $events): array
    {
        return [
            'schema_version' => 'delivery-agent-batch/v1',
            'batch_id' => $batchId,
            'created_at' => '2026-09-05T00:00:00Z',
            'producer' => ['kind' => 'claude', 'name' => 'Cursor', 'model' => null],
            'events' => $events,
        ];
    }

    /** @return array<string, mixed> */
    private function event(string $id, string $type, string $recordedAt, ?string $cause = null): array
    {
        return [
            'schema_version' => 'delivery-agent-event/v1',
            'event_id' => $id,
            'event_type' => $type,
            'recorded_at' => $recordedAt,
            'occurred_at' => null,
            'repository' => 'waaseyaa/framework',
            'pull_request' => 2902,
            'head_sha' => str_repeat('a', 40),
            'actor' => ['kind' => 'claude', 'name' => 'Cursor', 'model' => null],
            'evidence_kind' => 'observed',
            'causation_event_id' => $cause,
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
    }

    private function commitAll(string $repo, string $message): string
    {
        $this->git($repo, ['add', '--all']);
        $this->git($repo, ['commit', '--quiet', '-m', $message]);

        return $this->sha($repo);
    }

    /** @param list<string> $arguments
     *  @return array{exit: int, stdout: string, stderr: string}
     */
    private function gate(string $repo, array $arguments): array
    {
        $process = new Process([PHP_BINARY, $repo . '/bin/check-delivery-agent-events', ...$arguments], $repo);
        $exit = $process->run();

        return ['exit' => $exit, 'stdout' => $process->getOutput(), 'stderr' => $process->getErrorOutput()];
    }

    private function sha(string $repo): string
    {
        return trim($this->git($repo, ['rev-parse', 'HEAD'])['stdout']);
    }

    /** @param list<string> $arguments
     *  @return array{exit: int, stdout: string, stderr: string}
     */
    private function git(string $repo, array $arguments, ?string $stdin = null): array
    {
        $process = new Process(['git', '-C', $repo, ...$arguments]);
        if ($stdin !== null) {
            $process->setInput($stdin);
        }
        $exit = $process->run();
        self::assertSame(0, $exit, $process->getErrorOutput() . $process->getOutput());

        return ['exit' => $exit, 'stdout' => $process->getOutput(), 'stderr' => $process->getErrorOutput()];
    }
}
