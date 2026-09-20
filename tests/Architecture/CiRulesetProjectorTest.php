<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class CiRulesetProjectorTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
        require_once self::$root . '/bin/lib/ci-ruleset-projector.php';
    }

    #[Test]
    public function union_plan_preserves_every_non_status_field_and_proves_all_checks(): void
    {
        [$policy, $baseline, $live, $runs] = self::fixtures();

        $plan = \crp_plan('union', $baseline, $policy, $live, $runs, 'waaseyaa/framework', str_repeat('a', 40));

        self::assertSame(22, $plan['before']['required_context_count']);
        self::assertSame(31, $plan['after']['required_context_count']);
        self::assertSame(31, $plan['verified_check_count']);
        self::assertSame(
            \crp_hash(\crp_non_context_shape(\crp_write_payload($live))),
            \crp_hash(\crp_non_context_shape($plan['payload'])),
        );
        self::assertNotSame($plan['before']['hash'], $plan['after']['hash']);
    }

    #[Test]
    public function tracked_baseline_is_the_exact_legacy_write_payload(): void
    {
        [, $baseline] = self::fixtures();
        $legacy = \crp_context_map(\crp_required_contexts($baseline));

        self::assertSame('671d65c3259e35c583de6ce71799bf091ffd08b1fd783d7323c4c8d12c388748', \crp_hash($baseline));
        self::assertCount(22, $legacy);
        self::assertArrayHasKey('ci/mutation-pilot', $legacy);
        self::assertNull($legacy['ci/mutation-pilot']);
        self::assertSame(['non_fast_forward', 'deletion', 'required_status_checks', 'pull_request'], array_column($baseline['rules'], 'type'));
    }

    #[Test]
    public function final_requires_the_union_as_its_exact_predecessor(): void
    {
        [$policy, $baseline, $live, $runs] = self::fixtures();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not an allowed predecessor for final');

        \crp_plan('final', $baseline, $policy, $live, $runs, 'waaseyaa/framework', str_repeat('a', 40));
    }

    #[Test]
    public function final_projects_the_proved_union_to_nine_stable_contexts(): void
    {
        [$policy, $baseline, $live, $runs] = self::fixtures();
        $union = \crp_phase_projection('union', $baseline, $policy)['target'];
        $live = \crp_with_required_contexts($live, $union);

        $plan = \crp_plan('final', $baseline, $policy, $live, $runs, 'waaseyaa/framework', str_repeat('b', 40));

        self::assertSame(31, $plan['before']['required_context_count']);
        self::assertSame(9, $plan['after']['required_context_count']);
        self::assertSame(31, $plan['verified_check_count']);
    }

    #[Test]
    public function rollback_accepts_union_or_final_and_does_not_depend_on_green_checks(): void
    {
        [$policy, $baseline, $live] = self::fixtures();
        foreach (['union', 'final'] as $from) {
            $liveFrom = \crp_with_required_contexts($live, \crp_phase_projection($from, $baseline, $policy)['target']);
            $plan = \crp_plan('rollback', $baseline, $policy, $liveFrom, [], 'waaseyaa/framework', str_repeat('c', 40));

            self::assertSame(22, $plan['after']['required_context_count']);
            self::assertSame(0, $plan['verified_check_count']);
        }
    }

    #[Test]
    public function non_status_drift_fails_before_a_projection_is_emitted(): void
    {
        [$policy, $baseline, $live, $runs] = self::fixtures();
        $live['enforcement'] = 'evaluate';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('non-status ruleset field');

        \crp_plan('union', $baseline, $policy, $live, $runs, 'waaseyaa/framework', str_repeat('d', 40));
    }

    #[Test]
    public function missing_or_non_green_exact_sha_evidence_fails_closed(): void
    {
        [$policy, $baseline, $live, $runs] = self::fixtures();
        array_pop($runs);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Exact-SHA evidence is missing');

        \crp_plan('union', $baseline, $policy, $live, $runs, 'waaseyaa/framework', str_repeat('e', 40));
    }

    #[Test]
    public function fixture_cli_is_dry_run_and_emits_the_exact_before_hash(): void
    {
        [$policy, $baseline, $live, $runs] = self::fixtures();
        $directory = sys_get_temp_dir() . '/waaseyaa_ci_projector_' . bin2hex(random_bytes(6));
        mkdir($directory, 0o777, true);
        $rulesetPath = $directory . '/ruleset.json';
        $runsPath = $directory . '/runs.json';
        file_put_contents($rulesetPath, json_encode($live, JSON_THROW_ON_ERROR));
        file_put_contents($runsPath, json_encode(['check_runs' => $runs], JSON_THROW_ON_ERROR));

        try {
            $process = new Process([
                PHP_BINARY,
                self::$root . '/bin/project-ci-ruleset',
                '--phase=union',
                '--repo=waaseyaa/framework',
                '--evidence-sha=' . str_repeat('f', 40),
                '--ruleset=' . $rulesetPath,
                '--check-runs=' . $runsPath,
            ], self::$root);
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
            $report = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('dry-run', $report['mode']);
            self::assertFalse($report['applied']);
            self::assertSame(\crp_hash(\crp_write_payload($live)), $report['before']['hash']);
            self::assertSame(31, $report['after']['required_context_count']);
        } finally {
            foreach (glob($directory . '/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    /** @return array{array<string, mixed>, array<string, mixed>, array<string, mixed>, list<array<string, mixed>>} */
    private static function fixtures(): array
    {
        $policy = self::json(self::$root . '/tools/ci-check-roster.json');
        $baseline = self::json(self::$root . '/tools/ci-ruleset-main-protection-baseline.json');
        $live = ['id' => 15181711] + $baseline;
        $names = [];
        foreach ($policy['policy']['stable_aggregate_interface']['contexts'] as $entry) {
            $names[] = $entry['context'];
            array_push($names, ...$entry['prerequisite_contexts']);
        }
        $runs = [];
        foreach (array_values(array_unique($names)) as $offset => $name) {
            $runs[] = [
                'name' => $name,
                'status' => 'completed',
                'conclusion' => 'success',
                'app' => ['id' => 15368],
                'started_at' => sprintf('2026-09-20T00:%02d:00Z', $offset % 60),
                'completed_at' => sprintf('2026-09-20T00:%02d:03Z', $offset % 60),
            ];
        }

        return [$policy, $baseline, $live, $runs];
    }

    /** @return array<string, mixed> */
    private static function json(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
