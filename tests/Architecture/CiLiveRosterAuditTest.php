<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class CiLiveRosterAuditTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
        require_once self::$root . '/bin/lib/ci-live-roster-audit.php';
    }

    #[Test]
    public function a_conforming_exact_sha_proves_ruleset_and_all_nine_shadow_contexts(): void
    {
        [$policy, $inventory, $ruleset, $runs] = self::fixtures();

        $report = \cla_audit($policy, $inventory, $ruleset, $runs, 'waaseyaa/framework', str_repeat('a', 40));

        self::assertTrue($report['ok'], json_encode($report['findings']));
        self::assertSame(0, $report['counts']['error']);
        self::assertSame(15181711, $report['ruleset_snapshot']['id']);
        self::assertTrue($report['ruleset_snapshot']['strict']);
        self::assertCount(22, $report['ruleset_snapshot']['contexts']);
        self::assertSame(9, $report['stable_aggregate_count']);
        self::assertCount(9, $report['stable_aggregates']);
        self::assertSame(1, $report['measurement']['sample_size']);
        self::assertGreaterThan(0, $report['measurement']['aggregate_cost_proxy_seconds']);
        self::assertNull($report['measurement']['billed_runner_minutes']);
        foreach ($report['stable_aggregates'] as $aggregate) {
            self::assertSame('completed', $aggregate['status']);
            self::assertSame('success', $aggregate['conclusion']);
            self::assertSame(15368, $aggregate['app_id']);
            self::assertGreaterThan(0, $aggregate['duration_seconds']);
            self::assertNotSame([], $aggregate['prerequisites']);
        }
    }

    #[Test]
    public function ruleset_integration_binding_drift_fails_closed(): void
    {
        [$policy, $inventory, $ruleset, $runs] = self::fixtures();
        $ruleset['rules'][0]['parameters']['required_status_checks'][0]['integration_id'] = null;

        $report = \cla_audit($policy, $inventory, $ruleset, $runs, 'waaseyaa/framework', str_repeat('a', 40));

        self::assertFalse($report['ok']);
        self::assertContains('CLA003', self::errorCodes($report));
    }

    #[Test]
    public function required_prerequisite_from_the_wrong_app_fails_closed(): void
    {
        [$policy, $inventory, $ruleset, $runs] = self::fixtures();
        foreach ($runs as &$run) {
            if ($run['name'] === 'ci/playwright-smoke') {
                $run['app']['id'] = 999;
            }
        }
        unset($run);

        $report = \cla_audit($policy, $inventory, $ruleset, $runs, 'waaseyaa/framework', str_repeat('a', 40));

        self::assertFalse($report['ok']);
        self::assertContains('CLA010', self::errorCodes($report));
    }

    #[Test]
    public function missing_shadow_and_missing_prerequisite_are_independent_errors(): void
    {
        [$policy, $inventory, $ruleset, $runs] = self::fixtures();
        $runs = array_values(array_filter(
            $runs,
            static fn(array $run): bool => !in_array($run['name'], ['merge/browser-acceptance', 'ci/playwright-smoke'], true),
        ));

        $report = \cla_audit($policy, $inventory, $ruleset, $runs, 'waaseyaa/framework', str_repeat('a', 40));

        self::assertContains('CLA004', self::errorCodes($report));
        self::assertContains('CLA007', self::errorCodes($report));
    }

    #[Test]
    public function aggregate_failure_is_classified_as_derivative_when_its_root_failed(): void
    {
        [$policy, $inventory, $ruleset, $runs] = self::fixtures();
        foreach ($runs as &$run) {
            if (in_array($run['name'], ['ci/playwright-smoke', 'merge/browser-acceptance'], true)) {
                $run['conclusion'] = 'failure';
            }
        }
        unset($run);

        $report = \cla_audit($policy, $inventory, $ruleset, $runs, 'waaseyaa/framework', str_repeat('a', 40));
        $classifications = array_column($report['classifications'], 'class', 'context');

        self::assertSame('root-execution-failure', $classifications['ci/playwright-smoke']);
        self::assertSame('derivative-aggregate-failure', $classifications['merge/browser-acceptance']);
        self::assertContains('CLA005', self::errorCodes($report));
        self::assertContains('CLA008', self::errorCodes($report));
    }

    #[Test]
    public function inventory_condition_distinguishes_expected_and_unexpected_skips(): void
    {
        [$policy, $inventory, $ruleset, $runs] = self::fixtures();
        $runs[] = self::checkRun('admin/release', 'skipped');
        $runs[] = self::checkRun('ci/playwright-smoke', 'skipped', '2026-09-20T23:59:00Z');

        $report = \cla_audit($policy, $inventory, $ruleset, $runs, 'waaseyaa/framework', str_repeat('a', 40));
        $classifications = array_column($report['classifications'], 'class', 'context');

        self::assertSame('expected-conditional-skip', $classifications['admin/release']);
        self::assertSame('unexpected-skip-or-missing-prerequisite', $classifications['ci/playwright-smoke']);
    }

    #[Test]
    public function cli_accepts_rest_fixtures_and_emits_the_crc026_snapshot_shape(): void
    {
        [$policy, $inventory, $ruleset, $runs] = self::fixtures();
        $directory = sys_get_temp_dir() . '/waaseyaa_ci_live_' . bin2hex(random_bytes(6));
        mkdir($directory, 0o777, true);
        $rulesetPath = $directory . '/ruleset.json';
        $runsPath = $directory . '/runs.json';
        $reportPath = $directory . '/report.json';
        $snapshotPath = $directory . '/snapshot.json';
        file_put_contents($rulesetPath, json_encode($ruleset, JSON_THROW_ON_ERROR));
        file_put_contents($runsPath, json_encode(['check_runs' => $runs], JSON_THROW_ON_ERROR));

        try {
            $process = new Process([
                PHP_BINARY,
                self::$root . '/bin/audit-ci-roster-live',
                '--repo=waaseyaa/framework',
                '--sha=' . str_repeat('a', 40),
                '--ruleset=' . $rulesetPath,
                '--check-runs=' . $runsPath,
                '--output=' . $reportPath,
                '--snapshot-output=' . $snapshotPath,
                '--json',
            ], self::$root);
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
            $report = json_decode((string) file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
            $snapshot = json_decode((string) file_get_contents($snapshotPath), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('ci-roster-live-audit', $report['kind']);
            self::assertSame(['id', 'strict', 'contexts'], array_keys($snapshot));
            self::assertSame(15181711, $snapshot['id']);
            self::assertCount(22, $snapshot['contexts']);
        } finally {
            foreach (glob($directory . '/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    #[Test]
    public function workflow_is_manual_or_scheduled_and_never_a_pull_request_dependency(): void
    {
        $workflow = (string) file_get_contents(self::$root . '/.github/workflows/ci-roster-live-audit.yml');

        self::assertStringContainsString('schedule:', $workflow);
        self::assertStringContainsString('workflow_dispatch:', $workflow);
        self::assertStringNotContainsString('pull_request:', $workflow);
        self::assertStringContainsString('checks: read', $workflow);
        self::assertStringContainsString('contents: read', $workflow);
        self::assertStringContainsString('timeout-minutes: 10', $workflow);
        self::assertStringContainsString('php bin/audit-ci-roster-live', $workflow);
        self::assertStringContainsString('if: always()', $workflow);
    }

    /** @return array{array<string, mixed>, array<string, mixed>, array<string, mixed>, list<array<string, mixed>>} */
    private static function fixtures(): array
    {
        $policy = json_decode((string) file_get_contents(self::$root . '/tools/ci-check-roster.json'), true, 512, JSON_THROW_ON_ERROR);
        $inventory = json_decode((string) file_get_contents(self::$root . '/tools/ci-workflow-inventory.json'), true, 512, JSON_THROW_ON_ERROR);
        $projection = $policy['policy']['required_projection'];
        $ruleset = [
            'id' => $projection['source_ruleset_id'],
            'rules' => [[
                'type' => 'required_status_checks',
                'parameters' => [
                    'strict_required_status_checks_policy' => $projection['strict'],
                    'required_status_checks' => array_map(
                        static fn(array $entry): array => [
                            'context' => $entry['context'],
                            'integration_id' => $entry['binding']['integration_id'],
                        ],
                        $projection['contexts'],
                    ),
                ],
            ]],
        ];

        $names = [];
        foreach ($policy['policy']['stable_aggregate_shadow']['contexts'] as $entry) {
            $names[] = $entry['context'];
            array_push($names, ...$entry['prerequisite_contexts']);
        }
        $runs = [];
        foreach (array_values(array_unique($names)) as $offset => $name) {
            $runs[] = self::checkRun($name, 'success', sprintf('2026-09-20T00:%02d:00Z', $offset % 60));
        }

        return [$policy, $inventory, $ruleset, $runs];
    }

    /** @return array<string, mixed> */
    private static function checkRun(string $name, string $conclusion, string $started = '2026-09-20T00:00:00Z'): array
    {
        $completed = new \DateTimeImmutable($started)->modify('+3 seconds')->format(DATE_ATOM);

        return [
            'name' => $name,
            'status' => 'completed',
            'conclusion' => $conclusion,
            'app' => ['id' => 15368],
            'started_at' => $started,
            'completed_at' => $completed,
            'details_url' => 'https://github.com/waaseyaa/framework/actions/runs/1/job/1',
        ];
    }

    /** @return list<string> */
    private static function errorCodes(array $report): array
    {
        return array_values(array_map(
            static fn(array $finding): string => $finding['code'],
            array_filter($report['findings'], static fn(array $finding): bool => $finding['severity'] === 'error'),
        ));
    }
}
