<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class ProjectBoardSyncTest extends TestCase
{
    private string $root;
    private string $fixture;
    private string $tmp;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->fixture = $this->root . '/tests/Fixtures/ProjectBoardSync/base.json';
        $this->tmp = sys_get_temp_dir() . '/waaseyaa_board_sync_' . uniqid('', true);
        mkdir($this->tmp, 0o755, true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->tmp);
    }

    #[Test]
    public function audit_reports_each_axis_and_preserves_the_reverse_beta_rule(): void
    {
        [$exit, $report] = $this->runJson(['audit', '--snapshot=' . $this->fixture, '--format=json']);

        self::assertSame(1, $exit);
        self::assertSame(
            ['coverage', 'delivery_ordering', 'priority', 'readiness', 'status'],
            array_values(array_unique(array_column($report['findings'], 'axis'))),
        );
        self::assertContains('COVERAGE_MISSING_OPEN', array_column($report['findings'], 'code'));
        self::assertContains('BETA_BLOCKER_OUTSIDE_SAFETY', array_column($report['findings'], 'code'));
        self::assertNotContains(11, array_column($report['findings'], 'issue'));
    }

    #[Test]
    public function plan_adds_new_open_issue_and_reconciles_closed_history(): void
    {
        [$exit, $plan] = $this->runJson(['plan', '--snapshot=' . $this->fixture, '--format=json']);

        self::assertSame(0, $exit);
        $add = $this->operation($plan, 'add_issue', 13);
        self::assertSame('P0 - Critical', $add['fields']['Priority']['option_name']);
        self::assertSame('Needs Validation', $add['fields']['Readiness']['option_name']);
        self::assertSame('Todo', $add['fields']['Status']['option_name']);
        self::assertSame('Done', $this->operation($plan, 'set_field', 12, 'Status')['option_name']);
        self::assertSame('clear_field', $this->operation($plan, 'clear_field', 12, 'Readiness')['type']);
    }

    #[Test]
    public function all_observed_readiness_carriers_have_explicit_mappings(): void
    {
        $mapping = [
            'status:ready' => 'Ready',
            'status:in-progress' => 'In Progress',
            'status:blocked' => 'Blocked',
            'status:needs-design' => 'Needs Design',
            'portfolio:needs-validation' => 'Needs Validation',
            'status:needs-rescope' => 'Needs Rescope',
            'status:needs-triage' => 'Needs Triage',
            'portfolio:deferred' => 'Deferred',
            'needs-decision' => 'Decision',
        ];

        foreach ($mapping as $label => $expected) {
            $snapshot = $this->readFixture();
            $snapshot['issues'][1]['labels'] = ['priority:p2', $label];
            $snapshot['items'][1]['readiness'] = $expected === 'Ready' ? 'Blocked' : 'Ready';
            $path = $this->writeSnapshot($snapshot, str_replace(':', '-', $label));
            [$exit, $plan] = $this->runJson(['plan', '--snapshot=' . $path, '--format=json']);

            self::assertSame(0, $exit, $label);
            self::assertSame($expected, $this->operation($plan, 'set_field', 11, 'Readiness')['option_name']);
        }
    }

    #[Test]
    public function missing_readiness_maps_to_triage_but_conflicts_fail_closed_per_axis(): void
    {
        $snapshot = $this->readFixture();
        $snapshot['issues'][1]['labels'] = ['priority:p2'];
        $path = $this->writeSnapshot($snapshot, 'missing');
        [, $plan] = $this->runJson(['plan', '--snapshot=' . $path, '--format=json']);
        self::assertSame('Needs Triage', $this->operation($plan, 'set_field', 11, 'Readiness')['option_name']);

        $snapshot['issues'][1]['labels'] = ['priority:p1', 'priority:p2', 'status:ready', 'portfolio:deferred'];
        $path = $this->writeSnapshot($snapshot, 'ambiguous');
        [$exit, $report] = $this->runJson(['audit', '--snapshot=' . $path, '--format=json']);
        self::assertSame(1, $exit);
        self::assertContains('PRIORITY_AMBIGUOUS', array_column($report['findings'], 'code'));
        self::assertContains('READINESS_AMBIGUOUS', array_column($report['findings'], 'code'));

        [, $plan] = $this->runJson(['plan', '--snapshot=' . $path, '--format=json']);
        $issueEleven = array_values(array_filter($plan['operations'], static fn(array $op): bool => $op['issue'] === 11));
        self::assertNotContains('Priority', array_column($issueEleven, 'field'));
        self::assertNotContains('Readiness', array_column($issueEleven, 'field'));
    }

    #[Test]
    public function unknown_status_poisoning_and_missing_item_axes_fail_closed(): void
    {
        $snapshot = $this->readFixture();
        $snapshot['issues'][0]['labels'][] = 'status:completed';
        $path = $this->writeSnapshot($snapshot, 'known-plus-unknown-status');
        [, $plan] = $this->runJson(['plan', '--snapshot=' . $path, '--format=json']);
        $issueTen = array_values(array_filter($plan['operations'], static fn(array $operation): bool => $operation['issue'] === 10));
        self::assertNotContains('Readiness', array_column($issueTen, 'field'));
        self::assertContains('READINESS_AMBIGUOUS', array_column($plan['unresolved_findings'], 'code'));

        $snapshot = $this->readFixture();
        $snapshot['issues'][3]['labels'] = ['priority:p0', 'priority:p1', 'status:ready', 'status:completed', 'release:beta-blocker'];
        $path = $this->writeSnapshot($snapshot, 'missing-all-axes');
        [, $plan] = $this->runJson(['plan', '--snapshot=' . $path, '--format=json']);
        $codes = array_column($plan['unresolved_findings'], 'code');
        self::assertContains('PRIORITY_AMBIGUOUS', $codes);
        self::assertContains('READINESS_AMBIGUOUS', $codes);
        self::assertContains('BETA_BLOCKER_OUTSIDE_SAFETY', $codes);
        $add = $this->operation($plan, 'add_issue', 13);
        self::assertArrayNotHasKey('Priority', $add['fields']);
        self::assertArrayNotHasKey('Readiness', $add['fields']);
        self::assertArrayNotHasKey('Roadmap Stage', $add['fields']);
    }

    #[Test]
    public function live_collection_refuses_truncated_or_bound_hitting_sources_and_non_digit_projects(): void
    {
        $cases = [
            [['BOARD_SYNC_STUB_TRUNCATE_ITEMS' => '1'], 'Project item collection is truncated'],
            [['BOARD_SYNC_STUB_TRUNCATE_FIELDS' => '1'], 'Project field collection is truncated'],
            [['BOARD_SYNC_STUB_ISSUE_BOUND' => '1'], 'open-issue collection reached its 1000-record bound'],
        ];
        foreach ($cases as [$override, $message]) {
            [$exit, $error] = $this->runJson(['audit', '--format=json'], $this->stubEnvironment($override));
            self::assertSame(2, $exit, $message);
            self::assertStringContainsString($message, $error['error']['message']);
        }

        [$exit, $error] = $this->runJson(['audit', '--project=4x', '--format=json'], $this->stubEnvironment());
        self::assertSame(2, $exit);
        self::assertStringContainsString('decimal digits', $error['error']['message']);

        [$exit, $error] = $this->runJson(['audit', '--project=999999999999999999999999999999', '--format=json'], $this->stubEnvironment());
        self::assertSame(2, $exit);
        self::assertStringContainsString('native integer range', $error['error']['message']);
    }

    #[Test]
    public function partial_apply_writes_an_honest_receipt_and_same_receipt_blocks_replay(): void
    {
        $environment = $this->stubEnvironment(['BOARD_SYNC_STUB_FAIL_MUTATION_AT' => '2']);
        [, $plan] = $this->runJson(['plan', '--format=json'], $environment);
        $planPath = $this->tmp . '/live-plan.json';
        file_put_contents($planPath, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        $receiptPath = $this->tmp . '/apply-receipt.json';

        [$exit, $receipt] = $this->runJson(['apply', '--plan=' . $planPath, '--receipt=' . $receiptPath, '--format=json'], $environment);
        self::assertSame(3, $exit);
        self::assertSame('partial_failure', $receipt['result']);
        self::assertSame(1, $receipt['applied_count']);
        self::assertCount(1, $receipt['applied_operations']);
        self::assertSame($receipt, json_decode((string) file_get_contents($receiptPath), true, flags: JSON_THROW_ON_ERROR));

        [$exit, $error] = $this->runJson(['apply', '--plan=' . $planPath, '--receipt=' . $receiptPath, '--format=json'], $environment);
        self::assertSame(2, $exit);
        self::assertStringContainsString('receipt already exists', $error['error']['message']);
        self::assertCount(2, file($this->tmp . '/gh-mutations.jsonl', FILE_IGNORE_NEW_LINES));
    }

    #[Test]
    public function verify_refuses_stale_issue_state_and_changed_field_option_identity(): void
    {
        $planPath = $this->tmp . '/plan.json';
        $run = new Process([PHP_BINARY, $this->root . '/bin/project-board-sync', 'plan', '--snapshot=' . $this->fixture, '--output=' . $planPath], $this->root);
        $run->run();
        self::assertSame(0, $run->getExitCode(), $run->getErrorOutput());

        $snapshot = $this->readFixture();
        $snapshot['issues'][0]['labels'][] = 'status:blocked';
        $changed = $this->writeSnapshot($snapshot, 'stale-label');
        [$exit, $report] = $this->runJson(['verify-plan', '--snapshot=' . $changed, '--plan=' . $planPath, '--format=json']);
        self::assertSame(1, $exit);
        self::assertSame('PLAN_SOURCE_DRIFT', $report['error']['code']);

        $snapshot = $this->readFixture();
        $snapshot['fields'][1]['options'][1]['id'] = 'PRIORITY_p1_replaced';
        $changed = $this->writeSnapshot($snapshot, 'stale-option');
        [$exit, $report] = $this->runJson(['verify-plan', '--snapshot=' . $changed, '--plan=' . $planPath, '--format=json']);
        self::assertSame(1, $exit);
        self::assertSame('PLAN_SOURCE_DRIFT', $report['error']['code']);
    }

    #[Test]
    public function verify_refuses_a_rehashed_plan_with_injected_operations(): void
    {
        [, $plan] = $this->runJson(['plan', '--snapshot=' . $this->fixture, '--format=json']);
        $plan['operations'][] = [
            'type' => 'set_field',
            'issue' => 11,
            'item_id' => 'ITEM_11',
            'field' => 'Roadmap Stage',
            'field_id' => 'FIELD_stage',
            'option_id' => 'STAGE_later',
            'option_name' => 'Later',
        ];
        unset($plan['plan_id']);
        $plan['plan_id'] = hash('sha256', json_encode($this->canonicalize($plan), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $planPath = $this->tmp . '/injected-plan.json';
        file_put_contents($planPath, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        [$exit, $report] = $this->runJson(['verify-plan', '--snapshot=' . $this->fixture, '--plan=' . $planPath, '--format=json']);
        self::assertSame(1, $exit);
        self::assertSame('PLAN_CONTENT_MISMATCH', $report['error']['code']);
    }

    #[Test]
    public function fixture_mode_can_never_apply(): void
    {
        $planPath = $this->tmp . '/plan.json';
        $plan = new Process([PHP_BINARY, $this->root . '/bin/project-board-sync', 'plan', '--snapshot=' . $this->fixture, '--output=' . $planPath], $this->root);
        $plan->run();

        [$exit, $report] = $this->runJson(['apply', '--snapshot=' . $this->fixture, '--plan=' . $planPath, '--format=json']);
        self::assertSame(2, $exit);
        self::assertSame('FIXTURE_APPLY_REFUSED', $report['error']['code']);
    }

    /** @return array{0: int|null, 1: array<string, mixed>} */
    private function runJson(array $arguments, array $environment = []): array
    {
        $process = new Process(
            [PHP_BINARY, $this->root . '/bin/project-board-sync', ...$arguments],
            $this->root,
            $environment === [] ? null : array_replace($_SERVER, $_ENV, $environment),
        );
        $process->setTimeout(30.0);
        $process->run();
        $decoded = json_decode($process->getOutput() . $process->getErrorOutput(), true, flags: JSON_THROW_ON_ERROR);

        return [$process->getExitCode(), $decoded];
    }

    /** @param array<string, string> $override @return array<string, string> */
    private function stubEnvironment(array $override = []): array
    {
        return array_replace([
            'WAASEYAA_PROJECT_BOARD_GH_ADAPTER' => $this->root . '/tests/Fixtures/ProjectBoardSync/gh-adapter.php',
            'BOARD_SYNC_STUB_SNAPSHOT' => $this->fixture,
            'BOARD_SYNC_STUB_LOG' => $this->tmp . '/gh-mutations.jsonl',
        ], $override);
    }

    /** @return array<string, mixed> */
    private function readFixture(): array
    {
        return json_decode((string) file_get_contents($this->fixture), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $snapshot */
    private function writeSnapshot(array $snapshot, string $name): string
    {
        $path = $this->tmp . '/' . $name . '.json';
        file_put_contents($path, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        return $path;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->canonicalize($child);
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function operation(array $plan, string $type, int $issue, ?string $field = null): array
    {
        foreach ($plan['operations'] as $operation) {
            if ($operation['type'] === $type && $operation['issue'] === $issue && ($field === null || ($operation['field'] ?? null) === $field)) {
                return $operation;
            }
        }

        self::fail("Missing {$type} operation for #{$issue}" . ($field === null ? '' : " {$field}"));
    }
}
