<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Trustworthy elapsed_ms for FUTURE (batch-sourced, post-#2902-freeze)
 * substantive_review_issued and repair_completed events: an authoritative
 * causal start event (review_started / repair_started), identity-coherent,
 * timestamp-ordered, and an exact computed duration. The frozen v1 ledger is
 * immutable and out of scope for this rule; historical null and the one
 * legacy non-null elapsed_ms value (PR 2872) are unaffected.
 */
#[CoversNothing]
final class DeliveryAgentEventTimingCompletenessTest extends TestCase
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
    public function null_elapsed_ms_never_triggers_the_rule(): void
    {
        $review = $this->event('11111111-1111-4111-8111-111111111111', 'substantive_review_issued', occurredAt: '2026-09-05T00:10:00Z');
        $repair = $this->event('22222222-2222-4222-8222-222222222222', 'repair_completed', occurredAt: '2026-09-05T00:20:00Z');

        self::assertSame([], delivery_agent_elapsed_ms_errors($review, []));
        self::assertSame([], delivery_agent_elapsed_ms_errors($repair, []));
    }

    #[Test]
    public function other_event_types_are_never_subject_to_the_rule(): void
    {
        $event = $this->event('33333333-3333-4333-8333-333333333333', 'verification_completed', occurredAt: '2026-09-05T00:10:00Z');
        $event['elapsed_ms'] = 5000;

        self::assertSame([], delivery_agent_elapsed_ms_errors($event, []));
    }

    #[Test]
    public function a_correctly_matched_review_start_and_end_computes_a_trustworthy_duration(): void
    {
        $start = $this->event('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'review_started', occurredAt: '2026-09-05T00:00:00Z');
        $end = $this->event(
            'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'substantive_review_issued',
            occurredAt: '2026-09-05T00:05:00Z',
            cause: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        );
        $end['elapsed_ms'] = 300000;

        self::assertSame([], delivery_agent_elapsed_ms_errors($end, [$start['event_id'] => $start]));
    }

    #[Test]
    public function a_correctly_matched_repair_start_and_end_computes_a_trustworthy_duration(): void
    {
        $start = $this->event('cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'repair_started', occurredAt: '2026-09-05T00:00:00Z');
        $end = $this->event(
            'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
            'repair_completed',
            occurredAt: '2026-09-05T00:12:00Z',
            cause: 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
        );
        $end['head_sha'] = str_repeat('b', 40);
        $end['elapsed_ms'] = 720000;

        // Repair events may legitimately cross head SHAs, so a mismatched
        // head_sha between the repair_started cause and repair_completed
        // effect must not be treated as a mismatch.
        self::assertSame([], delivery_agent_elapsed_ms_errors($end, [$start['event_id'] => $start]));
    }

    #[Test]
    public function missing_causation_event_id_fails_closed(): void
    {
        $end = $this->event('eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'substantive_review_issued', occurredAt: '2026-09-05T00:05:00Z');
        $end['elapsed_ms'] = 300000;

        $errors = delivery_agent_elapsed_ms_errors($end, []);
        self::assertNotSame([], $errors);
        self::assertStringContainsString('requires a causation_event_id', $errors[0]);
    }

    #[Test]
    public function causation_event_id_naming_an_unknown_event_fails_closed(): void
    {
        $end = $this->event(
            'ffffffff-ffff-4fff-8fff-ffffffffffff',
            'substantive_review_issued',
            occurredAt: '2026-09-05T00:05:00Z',
            cause: '00000000-0000-4000-8000-000000000000',
        );
        $end['elapsed_ms'] = 300000;

        $errors = delivery_agent_elapsed_ms_errors($end, []);
        self::assertNotSame([], $errors);
        self::assertStringContainsString('does not name a known event', $errors[0]);
    }

    #[Test]
    public function a_mismatched_start_type_is_refused_for_review(): void
    {
        $wrongStart = $this->event('11111111-1111-4111-8111-111111111112', 'repair_started', occurredAt: '2026-09-05T00:00:00Z');
        $end = $this->event(
            '22222222-2222-4222-8222-222222222223',
            'substantive_review_issued',
            occurredAt: '2026-09-05T00:05:00Z',
            cause: $wrongStart['event_id'],
        );
        $end['elapsed_ms'] = 300000;

        $errors = delivery_agent_elapsed_ms_errors($end, [$wrongStart['event_id'] => $wrongStart]);
        self::assertNotSame([], $errors);
        self::assertStringContainsString('must be caused by a review_started event, not repair_started', $errors[0]);
    }

    #[Test]
    public function a_mismatched_start_type_is_refused_for_repair(): void
    {
        $wrongStart = $this->event('33333333-3333-4333-8333-333333333334', 'substantive_review_issued', occurredAt: '2026-09-05T00:00:00Z');
        $end = $this->event(
            '44444444-4444-4444-8444-444444444445',
            'repair_completed',
            occurredAt: '2026-09-05T00:05:00Z',
            cause: $wrongStart['event_id'],
        );
        $end['elapsed_ms'] = 300000;

        $errors = delivery_agent_elapsed_ms_errors($end, [$wrongStart['event_id'] => $wrongStart]);
        self::assertNotSame([], $errors);
        self::assertStringContainsString('must be caused by a repair_started event, not substantive_review_issued', $errors[0]);
    }

    #[Test]
    public function a_start_event_from_a_different_pull_request_is_refused(): void
    {
        $start = $this->event('55555555-5555-4555-8555-555555555556', 'review_started', occurredAt: '2026-09-05T00:00:00Z');
        $start['pull_request'] = 1;
        $end = $this->event(
            '66666666-6666-4666-8666-666666666667',
            'substantive_review_issued',
            occurredAt: '2026-09-05T00:05:00Z',
            cause: $start['event_id'],
        );
        $end['pull_request'] = 2;
        $end['elapsed_ms'] = 300000;

        $errors = delivery_agent_elapsed_ms_errors($end, [$start['event_id'] => $start]);
        self::assertNotSame([], $errors);
        self::assertStringContainsString('repository or pull_request differs', $errors[0]);
    }

    #[Test]
    public function a_review_start_event_from_a_different_head_sha_is_refused(): void
    {
        $start = $this->event('77777777-7777-4777-8777-777777777778', 'review_started', occurredAt: '2026-09-05T00:00:00Z');
        $start['head_sha'] = str_repeat('a', 40);
        $end = $this->event(
            '88888888-8888-4888-8888-888888888889',
            'substantive_review_issued',
            occurredAt: '2026-09-05T00:05:00Z',
            cause: $start['event_id'],
        );
        $end['head_sha'] = str_repeat('b', 40);
        $end['elapsed_ms'] = 300000;

        $errors = delivery_agent_elapsed_ms_errors($end, [$start['event_id'] => $start]);
        self::assertNotSame([], $errors);
        self::assertStringContainsString('head_sha differs', $errors[0]);
    }

    #[Test]
    public function a_null_occurred_at_on_either_side_forbids_a_populated_elapsed_ms(): void
    {
        $startWithoutTime = $this->event('99999999-9999-4999-8999-99999999999a', 'review_started', occurredAt: null);
        $endWithTime = $this->event(
            'aaaaaaab-aaaa-4aaa-8aaa-aaaaaaaaaaab',
            'substantive_review_issued',
            occurredAt: '2026-09-05T00:05:00Z',
            cause: $startWithoutTime['event_id'],
        );
        $endWithTime['elapsed_ms'] = 300000;

        $errors = delivery_agent_elapsed_ms_errors($endWithTime, [$startWithoutTime['event_id'] => $startWithoutTime]);
        self::assertNotSame([], $errors);
        self::assertStringContainsString('requires explicit occurred_at on both', $errors[0]);

        $startWithTime = $this->event('bbbbbbbc-bbbb-4bbb-8bbb-bbbbbbbbbbbc', 'review_started', occurredAt: '2026-09-05T00:00:00Z');
        $endWithoutTime = $this->event(
            'ccccccbd-cccc-4ccc-8ccc-ccccccccccbd',
            'substantive_review_issued',
            occurredAt: null,
            cause: $startWithTime['event_id'],
        );
        $endWithoutTime['elapsed_ms'] = 300000;

        $errors2 = delivery_agent_elapsed_ms_errors($endWithoutTime, [$startWithTime['event_id'] => $startWithTime]);
        self::assertNotSame([], $errors2);
        self::assertStringContainsString('requires explicit occurred_at on both', $errors2[0]);
    }

    #[Test]
    public function an_end_timestamp_before_its_start_timestamp_is_refused(): void
    {
        $start = $this->event('dddddcbe-dddd-4ddd-8ddd-ddddddddddbe', 'repair_started', occurredAt: '2026-09-05T00:10:00Z');
        $end = $this->event(
            'eeeeecbf-eeee-4eee-8eee-eeeeeeeeeebf',
            'repair_completed',
            occurredAt: '2026-09-05T00:05:00Z',
            cause: $start['event_id'],
        );
        $end['elapsed_ms'] = 0;

        $errors = delivery_agent_elapsed_ms_errors($end, [$start['event_id'] => $start]);
        self::assertNotSame([], $errors);
        self::assertStringContainsString('occurs after', $errors[0]);
    }

    #[Test]
    public function a_declared_elapsed_ms_that_does_not_match_the_computed_duration_is_refused(): void
    {
        $start = $this->event('ffffffc0-ffff-4fff-8fff-fffffffffec0', 'review_started', occurredAt: '2026-09-05T00:00:00Z');
        $end = $this->event(
            '00000001-0000-4000-8000-0000000000c1',
            'substantive_review_issued',
            occurredAt: '2026-09-05T00:05:00Z',
            cause: $start['event_id'],
        );
        $end['elapsed_ms'] = 300001;

        $errors = delivery_agent_elapsed_ms_errors($end, [$start['event_id'] => $start]);
        self::assertNotSame([], $errors);
        self::assertStringContainsString('does not match the computed duration 300000', $errors[0]);
    }

    #[Test]
    public function the_real_gate_accepts_a_correctly_matched_batch_pair_and_refuses_a_mismatched_one(): void
    {
        $repo = $this->seedFrozenAuthorityRepo();
        $start = $this->event('12121212-1212-4121-8121-121212121212', 'review_started', occurredAt: '2026-09-05T00:00:00Z');
        $end = $this->event(
            '13131313-1313-4131-8131-131313131313',
            'substantive_review_issued',
            occurredAt: '2026-09-05T00:05:00Z',
            cause: $start['event_id'],
        );
        $end['outcome'] = 'accepted';
        $end['elapsed_ms'] = 300000;
        $this->writeBatch($repo, $this->batchFile('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', [$start, $end]));
        $this->commitAll($repo, 'matched timing batch');

        $ok = $this->gate($repo, []);
        self::assertSame(0, $ok['exit'], $ok['stderr'] . $ok['stdout']);

        $this->git($repo, ['checkout', '-q', '-b', 'mismatch']);
        $path = $repo . '/ops/observability/delivery-agent-batches-v1/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa.json';
        file_put_contents($path, str_replace('300000', '999999', (string) file_get_contents($path)));
        $this->commitAll($repo, 'mismatched elapsed_ms');

        $bad = $this->gate($repo, []);
        self::assertSame(1, $bad['exit']);
        self::assertStringContainsString('does not match the computed duration', $bad['stderr']);
    }

    #[Test]
    public function the_frozen_historical_ledger_with_its_legacy_non_null_elapsed_ms_row_is_untouched_by_the_new_rule(): void
    {
        $process = new Process([PHP_BINARY, $this->root . '/bin/check-delivery-agent-events']);
        self::assertSame(0, $process->run(), $process->getErrorOutput());
        self::assertStringContainsString('PASS', $process->getOutput());
    }

    /** @return array<string, mixed> */
    private function event(string $id, string $type, ?string $occurredAt, ?string $cause = null): array
    {
        return [
            'schema_version' => 'delivery-agent-event/v1',
            'event_id' => $id,
            'event_type' => $type,
            'recorded_at' => '2026-09-05T00:30:00Z',
            'occurred_at' => $occurredAt,
            'repository' => 'waaseyaa/framework',
            'pull_request' => 2941,
            'head_sha' => str_repeat('a', 40),
            'actor' => ['kind' => 'claude', 'name' => 'Cursor', 'model' => null],
            'evidence_kind' => 'observed',
            'causation_event_id' => $cause,
            'review_depth' => $type === 'substantive_review_issued' ? 'substantive' : null,
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

    private function commitAll(string $repo, string $message): string
    {
        $this->git($repo, ['add', '--all']);
        $this->git($repo, ['commit', '--quiet', '-m', $message]);

        return $this->sha($repo);
    }

    private function sha(string $repo): string
    {
        return trim($this->git($repo, ['rev-parse', 'HEAD'])['stdout']);
    }

    /**
     * @param list<string> $arguments
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function gate(string $repo, array $arguments): array
    {
        $process = new Process([PHP_BINARY, $repo . '/bin/check-delivery-agent-events', ...$arguments], $repo);
        $exit = $process->run();

        return ['exit' => $exit, 'stdout' => $process->getOutput(), 'stderr' => $process->getErrorOutput()];
    }

    /**
     * @param list<string> $arguments
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function git(string $repo, array $arguments): array
    {
        $process = new Process(['git', '-C', $repo, ...$arguments]);
        $exit = $process->run();
        self::assertSame(0, $exit, $process->getErrorOutput() . $process->getOutput());

        return ['exit' => $exit, 'stdout' => $process->getOutput(), 'stderr' => $process->getErrorOutput()];
    }

    private function seedFrozenAuthorityRepo(): string
    {
        $repo = sys_get_temp_dir() . '/waaseyaa-timing-gate-' . bin2hex(random_bytes(6));
        mkdir($repo . '/ops/observability/delivery-agent-batches-v1', 0o777, true);
        mkdir($repo . '/bin/lib', 0o777, true);
        foreach ([
            'ops/observability/delivery-agent-event-v1.schema.json',
            'ops/observability/delivery-agent-events-v1.jsonl',
            'ops/observability/delivery-agent-batch-v1.schema.json',
            'ops/observability/delivery-agent-v1-freeze.json',
            'bin/check-delivery-agent-events',
            'bin/lib/delivery-agent-event-set.php',
            'bin/lib/vendor-freshness.php',
            'bin/git',
            'composer.json',
            'composer.lock',
        ] as $path) {
            self::assertTrue(copy($this->root . '/' . $path, $repo . '/' . $path));
        }
        chmod($repo . '/bin/check-delivery-agent-events', 0o755);
        chmod($repo . '/bin/git', 0o755);
        symlink($this->root . '/vendor', $repo . '/vendor');
        $this->fixtures[] = $repo;

        $this->git($repo, ['init', '--quiet', '--initial-branch=main']);
        $this->git($repo, ['config', 'user.name', 'Timing Gate Fixture']);
        $this->git($repo, ['config', 'user.email', 'timing-gate@example.invalid']);
        $this->git($repo, ['add', 'ops', 'bin', 'vendor', 'composer.json', 'composer.lock']);
        $this->git($repo, ['commit', '--quiet', '-m', 'frozen authority']);

        return $repo;
    }
}
