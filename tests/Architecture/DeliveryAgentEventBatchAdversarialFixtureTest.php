<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Ordering-independent adversarial controls for #2902 complete-set rules.
 * These fixtures do not lock replay order; they only prove refusals that the
 * eventual batch contract must keep regardless of Codex ordering decisions.
 */
#[CoversNothing]
final class DeliveryAgentEventBatchAdversarialFixtureTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/bin/lib/delivery-agent-event-set.php';
        $this->directory = sys_get_temp_dir() . '/waaseyaa_batch_adversarial_' . bin2hex(random_bytes(6));
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
    public function duplicate_event_ids_are_refused_whether_payloads_match_or_conflict(): void
    {
        $first = $this->event('11111111-1111-4111-8111-111111111111', 'review_started');
        $identical = $first;
        $conflict = $this->event('11111111-1111-4111-8111-111111111111', 'repair_started');

        $identicalErrors = delivery_agent_event_set_errors([$first, $identical]);
        self::assertNotSame([], $identicalErrors);
        self::assertStringContainsString('duplicates event_id', implode("\n", $identicalErrors));

        $conflictErrors = delivery_agent_event_set_errors([$first, $conflict]);
        self::assertNotSame([], $conflictErrors);
        self::assertStringContainsString('conflicts with event_id', implode("\n", $conflictErrors));
    }

    #[Test]
    public function missing_causal_references_are_refused(): void
    {
        $orphan = $this->event(
            '22222222-2222-4222-8222-222222222222',
            'verification_finding_adjudicated',
            causation: '33333333-3333-4333-8333-333333333333',
        );

        $errors = delivery_agent_event_set_errors([$orphan]);
        self::assertNotSame([], $errors);
        self::assertStringContainsString('missing cause', implode("\n", $errors));
    }

    #[Test]
    public function causal_cycles_are_refused(): void
    {
        $a = $this->event(
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'repair_started',
            causation: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        );
        $b = $this->event(
            'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'repair_completed',
            causation: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        );

        $errors = delivery_agent_event_set_errors([$a, $b]);
        self::assertNotSame([], $errors);
        self::assertStringContainsString('causal cycle', implode("\n", $errors));
    }

    #[Test]
    public function conflicting_adjudications_of_one_finding_are_refused(): void
    {
        $finding = $this->finding('44444444-4444-4444-8444-444444444444');
        $first = $this->adjudication(
            '55555555-5555-4555-8555-555555555555',
            $finding['event_id'],
            'false_positive',
            false,
        );
        $second = $this->adjudication(
            '66666666-6666-4666-8666-666666666666',
            $finding['event_id'],
            'true_positive',
            true,
        );

        $errors = delivery_agent_event_set_errors([$finding, $first, $second]);
        self::assertNotSame([], $errors);
        self::assertStringContainsString('conflicting adjudications', implode("\n", $errors));
    }

    #[Test]
    public function modified_or_deleted_accepted_batches_are_refused(): void
    {
        $path = 'ops/observability/delivery-agent-batches-v1/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa.json';
        $accepted = [$path => "{\"batch_id\":\"aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa\"}\n"];

        $deleted = delivery_agent_batch_immutability_errors($accepted, []);
        self::assertSame(['accepted batch deleted: ' . $path], $deleted);

        $modified = delivery_agent_batch_immutability_errors(
            $accepted,
            [$path => "{\"batch_id\":\"aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa\",\"tampered\":true}\n"],
        );
        self::assertSame(['accepted batch modified: ' . $path], $modified);

        $additive = delivery_agent_batch_immutability_errors(
            $accepted,
            [
                $path => $accepted[$path],
                'ops/observability/delivery-agent-batches-v1/bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb.json' => "{}\n",
            ],
        );
        self::assertSame([], $additive);
    }

    #[Test]
    public function git_merge_cannot_quietly_rewrite_or_drop_an_accepted_batch_path(): void
    {
        $repo = $this->directory . '/repo';
        mkdir($repo);
        $this->git($repo, 'init', '-q');
        $this->git($repo, 'config', 'user.email', 'fixture@example.com');
        $this->git($repo, 'config', 'user.name', 'Batch Adversarial Fixture');

        $dir = 'ops/observability/delivery-agent-batches-v1';
        $acceptedPath = $dir . '/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa.json';
        mkdir($repo . '/' . $dir, 0o755, true);
        $acceptedBytes = "{\"batch_id\":\"aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa\",\"events\":[]}\n";
        file_put_contents($repo . '/' . $acceptedPath, $acceptedBytes);
        $this->git($repo, 'add', $acceptedPath);
        $this->git($repo, 'commit', '-q', '-m', 'accepted batch');
        $acceptedSha = trim((string) shell_exec('cd ' . escapeshellarg($repo) . ' && git rev-parse HEAD'));

        $this->git($repo, 'checkout', '-q', '-b', 'tamper');
        file_put_contents($repo . '/' . $acceptedPath, "{\"batch_id\":\"aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa\",\"events\":[1]}\n");
        $this->git($repo, 'add', $acceptedPath);
        $this->git($repo, 'commit', '-q', '-m', 'modified accepted batch');

        $acceptedMap = [$acceptedPath => $this->gitShow($repo, $acceptedSha, $acceptedPath)];
        $proposedMap = [$acceptedPath => (string) file_get_contents($repo . '/' . $acceptedPath)];
        $errors = delivery_agent_batch_immutability_errors($acceptedMap, $proposedMap);
        self::assertSame(['accepted batch modified: ' . $acceptedPath], $errors);

        $this->git($repo, 'checkout', '-q', '-b', 'drop', $acceptedSha);
        $this->git($repo, 'rm', '-q', $acceptedPath);
        $this->git($repo, 'commit', '-q', '-m', 'deleted accepted batch');
        $dropErrors = delivery_agent_batch_immutability_errors($acceptedMap, []);
        self::assertSame(['accepted batch deleted: ' . $acceptedPath], $dropErrors);
    }

    #[Test]
    public function accepted_freeze_and_batch_schema_blobs_cannot_be_deleted_or_rewritten(): void
    {
        $freeze = "{\"schema_version\":\"delivery-agent-v1-freeze/v1\"}\n";
        $schema = "{\"title\":\"batch\"}\n";

        self::assertSame(
            ['accepted v1 freeze manifest deleted'],
            delivery_agent_authority_blob_immutability_errors('v1 freeze manifest', $freeze, null),
        );
        self::assertSame(
            ['accepted v1 freeze manifest deleted'],
            delivery_agent_authority_blob_immutability_errors('v1 freeze manifest', $freeze, ''),
        );
        self::assertSame(
            ['the published v1 freeze manifest is immutable'],
            delivery_agent_authority_blob_immutability_errors('v1 freeze manifest', $freeze, $schema),
        );
        self::assertSame(
            [],
            delivery_agent_authority_blob_immutability_errors('v1 freeze manifest', $freeze, $freeze),
        );

        self::assertSame(
            ['accepted batch schema deleted'],
            delivery_agent_authority_blob_immutability_errors('batch schema', $schema, null),
        );
        self::assertSame(
            ['accepted batch schema deleted'],
            delivery_agent_authority_blob_immutability_errors('batch schema', $schema, ''),
        );
        self::assertSame(
            ['the published batch schema is immutable'],
            delivery_agent_authority_blob_immutability_errors('batch schema', $schema, $freeze),
        );
        self::assertSame(
            [],
            delivery_agent_authority_blob_immutability_errors('batch schema', $schema, $schema),
        );
    }

    #[Test]
    public function a_valid_cross_batch_causal_set_without_cycles_or_duplicates_is_accepted(): void
    {
        $finding = $this->finding('77777777-7777-4777-8777-777777777777');
        $adjudication = $this->adjudication(
            '88888888-8888-4888-8888-888888888888',
            $finding['event_id'],
            'false_positive',
            false,
        );

        self::assertSame([], delivery_agent_event_set_errors([$finding, $adjudication]));
    }

    /**
     * @return array<string, mixed>
     */
    private function event(string $id, string $type, ?string $causation = null): array
    {
        return [
            'schema_version' => 'delivery-agent-event/v1',
            'event_id' => $id,
            'event_type' => $type,
            'recorded_at' => '2026-09-03T20:00:00Z',
            'occurred_at' => null,
            'repository' => 'waaseyaa/framework',
            'pull_request' => 2902,
            'head_sha' => str_repeat('a', 40),
            'actor' => ['kind' => 'cursor', 'name' => 'Cursor', 'model' => null],
            'evidence_kind' => 'observed',
            'causation_event_id' => $causation,
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

    /** @return array<string, mixed> */
    private function finding(string $id): array
    {
        $event = $this->event($id, 'verification_finding_issued');
        $event['verification'] = [
            'verifier_id' => 'probe-1',
            'check_type' => 'review_probe',
            'claim' => 'claim',
            'observed' => 'observed',
            'candidate_defect_claimed' => true,
            'safety_effect' => 'fail_closed',
        ];

        return $event;
    }

    /** @return array<string, mixed> */
    private function adjudication(string $id, string $findingId, string $classification, ?bool $confirmed): array
    {
        $event = $this->event($id, 'verification_finding_adjudicated', $findingId);
        $event['adjudication'] = [
            'classification' => $classification,
            'candidate_defect_confirmed' => $confirmed,
            'rationale' => 'fixture',
            'adjudicated_by' => 'Cursor',
        ];

        return $event;
    }

    private function gitShow(string $repo, string $commit, string $path): string
    {
        $command = sprintf(
            'cd %s && git show %s',
            escapeshellarg($repo),
            escapeshellarg($commit . ':' . $path),
        );
        exec($command . ' 2>&1', $lines, $code);
        self::assertSame(0, $code, implode("\n", $lines));

        return implode("\n", $lines) . "\n";
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
