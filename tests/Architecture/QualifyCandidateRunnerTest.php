<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Acceptance proof for #2913 (FW-DELIVERY-QUALIFICATION-EVIDENCE-01).
 *
 * Every case drives the REAL bin/qualify-candidate against a throwaway git
 * repository with a `--plan` whose components are tiny `php -r` children. No
 * case runs a real suite. The properties proven are the ones the issue names:
 * numeric exit evidence survives log capture; failure, skipped and
 * wrapper/evidence errors stay distinct; interruption and candidate drift are
 * never called a qualification; concurrency binds every child to one head.
 */
#[CoversNothing]
final class QualifyCandidateRunnerTest extends TestCase
{
    private string $repoRoot;
    private string $runner;
    private string $tmp;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        $this->runner = $this->repoRoot . '/bin/qualify-candidate';
        self::assertFileExists($this->runner, 'bin/qualify-candidate must exist (FW-DELIVERY-QUALIFICATION-EVIDENCE-01).');
        $this->tmp = sys_get_temp_dir() . '/waaseyaa_qualify_' . uniqid('', true);
        new Filesystem()->mkdir($this->tmp);
        $this->git('init', '-q');
        $this->git('config', 'user.email', 'fixture@example.test');
        $this->git('config', 'user.name', 'Fixture');
        file_put_contents($this->tmp . '/tracked.txt', "v1\n");
        $this->git('add', 'tracked.txt');
        $this->git('commit', '-q', '-m', 'fixture');
    }

    protected function tearDown(): void
    {
        // Best-effort; a read-only fixture dir is restored first so teardown never masks a verdict.
        @chmod($this->tmp . '/ro', 0o755);
        new Filesystem()->remove($this->tmp);
    }

    #[Test]
    public function a_fully_passing_plan_is_a_qualification_with_numeric_evidence(): void
    {
        $plan = $this->plan([
            $this->component('alpha', 'exit(0);', junit: ['tests' => 3, 'failures' => 0, 'errors' => 0, 'skipped' => 0]),
            $this->component('beta', 'exit(0);'),
        ], full: true);

        [$exit, $out, $receipt] = $this->qualify(['--plan=' . $plan]);

        self::assertSame(0, $exit, $out);
        self::assertSame('qualified', $receipt['verdict']);
        self::assertTrue($receipt['qualification']);
        self::assertSame($this->head(), $receipt['candidate']['head']);
        self::assertSame($this->head(), $receipt['source_check']['head_after']);
        self::assertFalse($receipt['source_check']['drifted']);
        $alpha = $this->componentNamed($receipt, 'alpha');
        self::assertSame(0, $alpha['exit_code']);
        self::assertSame('exit', $alpha['termination']);
        self::assertSame('passed', $alpha['outcome']);
        self::assertSame(3, $alpha['counts']['tests']);
        self::assertFileExists($alpha['log']);
    }

    #[Test]
    public function a_failing_child_fails_the_run_with_its_real_exit_status(): void
    {
        $plan = $this->plan([
            $this->component('alpha', 'exit(0);'),
            $this->component('beta', 'fwrite(STDOUT, "OK (999 tests)\n"); exit(7);'),
        ], full: true);

        [$exit, $out, $receipt] = $this->qualify(['--plan=' . $plan]);

        self::assertSame(1, $exit, $out);
        self::assertSame('failed', $receipt['verdict']);
        self::assertFalse($receipt['qualification']);
        $beta = $this->componentNamed($receipt, 'beta');
        // The child printed a green-looking line and still exited 7: the exit
        // status is what counts, never the log text.
        self::assertSame(7, $beta['exit_code']);
        self::assertSame('failed', $beta['outcome']);
        self::assertSame('passed', $this->componentNamed($receipt, 'alpha')['outcome']);
    }

    #[Test]
    public function a_child_killed_by_a_signal_is_recorded_as_signaled_not_passed(): void
    {
        if (!function_exists('posix_kill')) {
            self::markTestSkipped('Signal termination of a child requires the posix extension.');
        }
        $plan = $this->plan([
            $this->component('alpha', 'posix_kill(getmypid(), SIGKILL);'),
        ], full: true);

        [$exit, $out, $receipt] = $this->qualify(['--plan=' . $plan]);

        self::assertSame(1, $exit, $out);
        $alpha = $this->componentNamed($receipt, 'alpha');
        self::assertSame('signal', $alpha['termination']);
        self::assertSame('signaled', $alpha['outcome']);
        self::assertNull($alpha['exit_code']);
        self::assertSame(9, $alpha['signal']);
    }

    #[Test]
    public function skipped_tests_are_a_count_on_a_passed_component_not_a_failure(): void
    {
        $plan = $this->plan([
            $this->component('alpha', 'exit(0);', junit: ['tests' => 5, 'failures' => 0, 'errors' => 0, 'skipped' => 2]),
        ], full: true);

        [$exit, $out, $receipt] = $this->qualify(['--plan=' . $plan]);

        self::assertSame(0, $exit, $out);
        $alpha = $this->componentNamed($receipt, 'alpha');
        self::assertSame('passed', $alpha['outcome']);
        self::assertSame(2, $alpha['counts']['skipped']);
        self::assertStringContainsString('skipped', $out, 'The summary must surface skipped counts.');
    }

    #[Test]
    public function a_missing_junit_for_a_component_that_promised_one_is_an_evidence_error(): void
    {
        $plan = $this->plan([
            // exits 0 but never writes the junit file it declared
            $this->component('alpha', 'exit(0);', junit: null, declaresJunit: true),
        ], full: true);

        [$exit, $out, $receipt] = $this->qualify(['--plan=' . $plan]);

        self::assertSame(2, $exit, $out);
        self::assertSame('evidence_error', $receipt['verdict']);
        self::assertFalse($receipt['qualification']);
        self::assertSame('evidence_error', $this->componentNamed($receipt, 'alpha')['outcome']);
    }

    #[Test]
    public function a_zero_exit_that_contradicts_its_junit_failures_is_an_evidence_error_not_a_pass(): void
    {
        // PHPUnit never exits 0 with failures or errors; a junit that says
        // otherwise is not this run's evidence and must not be counted green.
        $plan = $this->plan([
            $this->component('alpha', 'exit(0);', junit: ['tests' => 5, 'failures' => 2, 'errors' => 0, 'skipped' => 0]),
        ], full: true);

        [$exit, $out, $receipt] = $this->qualify(['--plan=' . $plan]);

        self::assertSame(2, $exit, $out);
        self::assertSame('evidence_error', $receipt['verdict']);
        self::assertFalse($receipt['qualification']);
        self::assertStringNotContainsString('verdict: qualified', $out);
        $alpha = $this->componentNamed($receipt, 'alpha');
        self::assertSame('evidence_error', $alpha['outcome']);
        self::assertSame(2, $alpha['counts']['failures']);
    }

    #[Test]
    public function stale_evidence_in_a_reused_out_directory_is_never_this_runs_evidence(): void
    {
        $out = $this->tmp . '/evidence-reused';
        mkdir($out);
        file_put_contents(
            $out . '/alpha.junit.xml',
            '<?xml version="1.0"?><testsuites><testsuite name="old" tests="14316" errors="0" failures="0" skipped="0"/></testsuites>',
        );
        file_put_contents($out . '/alpha.log', "OLD LOG LINE\n");
        // declares a junit, exits 0, never writes one — only the stale file exists
        $plan = $this->plan([$this->component('alpha', 'exit(0);', junit: null, declaresJunit: true)], full: true);

        $process = $this->process(['--plan=' . $plan, '--out=' . $out]);
        $exit = $process->run();
        $receipt = json_decode((string) file_get_contents($out . '/receipt.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(2, $exit, $process->getOutput() . $process->getErrorOutput());
        self::assertSame('evidence_error', $receipt['verdict']);
        self::assertFalse($receipt['qualification']);
        $alpha = $this->componentNamed($receipt, 'alpha');
        self::assertSame('evidence_error', $alpha['outcome']);
        self::assertArrayNotHasKey('counts', $alpha, 'Stale counts must not be attributed to this run.');
        self::assertStringNotContainsString('OLD LOG LINE', (string) file_get_contents($out . '/alpha.log'));
    }

    #[Test]
    public function a_real_sigint_terminates_running_children_and_exits_130_promptly(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            self::markTestSkipped('Real signal delivery to the runner requires the pcntl extension.');
        }
        $plan = $this->plan([
            $this->component('alpha', 'exit(0);'),
            $this->component('sleeper', 'sleep(20); exit(0);'),
            $this->component('never', 'exit(0);'),
        ], full: true);
        $out = $this->tmp . '/evidence-sigint';

        $process = $this->process(['--plan=' . $plan, '--out=' . $out]);
        $startedNs = hrtime(true);
        $process->start();
        // The sleeper's log is opened by the runner at spawn; once it exists the
        // child is running and the signal lands mid-component, not between two.
        while (!is_file($out . '/sleeper.log') && $process->isRunning() && hrtime(true) - $startedNs < 10_000_000_000) {
            usleep(20_000);
        }
        usleep(200_000);
        $process->signal(SIGINT);
        $exit = $process->wait();
        $elapsedS = (hrtime(true) - $startedNs) / 1_000_000_000;
        $output = $process->getOutput() . $process->getErrorOutput();
        $receipt = json_decode((string) file_get_contents($out . '/receipt.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(130, $exit, $output);
        self::assertLessThan(10.0, $elapsedS, 'The runner must not wait for a 20s child after SIGINT.');
        self::assertSame('interrupted', $receipt['verdict']);
        self::assertFalse($receipt['qualification']);
        $sleeper = $this->componentNamed($receipt, 'sleeper');
        self::assertSame('signal', $sleeper['termination']);
        self::assertSame('signaled', $sleeper['outcome']);
        self::assertNotSame('passed', $sleeper['outcome']);
        self::assertArrayNotHasKey('never', array_column($receipt['components'], null, 'id'));
        self::assertStringNotContainsString('verdict: qualified', $output);
    }

    #[Test]
    public function an_unwritable_evidence_directory_is_refused_without_a_success_claim(): void
    {
        $ro = $this->tmp . '/ro';
        mkdir($ro, 0o555);
        if (is_writable($ro)) {
            self::markTestSkipped('Read-only directory permissions are not enforced for this user.');
        }
        $plan = $this->plan([$this->component('alpha', 'exit(0);')], full: true);

        $process = $this->process(['--plan=' . $plan, '--out=' . $ro . '/evidence']);
        $exit = $process->run();
        $out = $process->getOutput() . $process->getErrorOutput();

        self::assertSame(2, $exit, $out);
        self::assertStringNotContainsString('qualified', strtolower($process->getOutput()));
        self::assertFileDoesNotExist($ro . '/evidence/receipt.json');
    }

    #[Test]
    public function interruption_writes_an_interrupted_receipt_and_exits_130(): void
    {
        $plan = $this->plan([
            $this->component('alpha', 'exit(0);'),
            $this->component('beta', 'exit(0);'),
        ], full: true);

        [$exit, $out, $receipt] = $this->qualify(['--plan=' . $plan], env: ['WAASEYAA_QUALIFY_INTERRUPT_AFTER' => 'alpha']);

        self::assertSame(130, $exit, $out);
        self::assertSame('interrupted', $receipt['verdict']);
        self::assertFalse($receipt['qualification']);
        self::assertSame('passed', $this->componentNamed($receipt, 'alpha')['outcome']);
        self::assertArrayNotHasKey('beta', array_column($receipt['components'], null, 'id'));
    }

    #[Test]
    public function tracked_source_changing_during_the_run_is_drift_not_qualification(): void
    {
        $tracked = $this->tmp . '/tracked.txt';
        $plan = $this->plan([
            $this->component('alpha', 'file_put_contents(' . var_export($tracked, true) . ', "v2\n"); exit(0);'),
            $this->component('beta', 'exit(0);'),
        ], full: true);

        [$exit, $out, $receipt] = $this->qualify(['--plan=' . $plan]);

        self::assertSame(3, $exit, $out);
        self::assertSame('drifted', $receipt['verdict']);
        self::assertFalse($receipt['qualification']);
        self::assertTrue($receipt['source_check']['drifted']);
        self::assertNotSame([], $receipt['source_check']['changes']);
    }

    #[Test]
    public function a_dirty_tracked_tree_is_refused_unless_explicitly_allowed_and_then_never_qualifies(): void
    {
        file_put_contents($this->tmp . '/tracked.txt', "dirty\n");
        $plan = $this->plan([$this->component('alpha', 'exit(0);')], full: true);

        $process = $this->process(['--plan=' . $plan]);
        self::assertSame(3, $process->run(), $process->getOutput() . $process->getErrorOutput());

        [$exit, $out, $receipt] = $this->qualify(['--plan=' . $plan, '--allow-dirty']);
        self::assertSame(0, $exit, $out);
        self::assertFalse($receipt['qualification'], 'Evidence from a dirty tree is never a qualification.');
        self::assertSame(['tracked.txt'], array_map(static fn(string $l): string => trim(substr($l, 3)), $receipt['candidate']['dirty_at_start']));
    }

    #[Test]
    public function a_subset_run_is_partial_and_never_a_qualification(): void
    {
        $plan = $this->plan([
            $this->component('alpha', 'exit(0);'),
            $this->component('beta', 'exit(0);'),
        ], full: true);

        [$exit, $out, $receipt] = $this->qualify(['--plan=' . $plan, '--only=alpha']);

        self::assertSame(0, $exit, $out);
        self::assertSame('partial', $receipt['verdict']);
        self::assertFalse($receipt['qualification']);
        self::assertCount(1, $receipt['components']);
    }

    #[Test]
    public function concurrent_components_are_all_bound_to_one_head_and_tree(): void
    {
        $plan = $this->plan([
            $this->component('alpha', 'usleep(200000); exit(0);'),
            $this->component('beta', 'usleep(200000); exit(0);'),
            $this->component('gamma', 'exit(0);'),
        ], full: true);

        [$exit, $out, $receipt] = $this->qualify(['--plan=' . $plan, '--jobs=3']);

        self::assertSame(0, $exit, $out);
        self::assertSame(3, $receipt['runner']['jobs']);
        self::assertSame('qualified', $receipt['verdict']);
        self::assertCount(3, $receipt['components']);
        self::assertSame($receipt['candidate']['tree'], $receipt['source_check']['tree_after']);
    }

    // ---------------------------------------------------------------- helpers

    /** @param list<array<string, mixed>> $components */
    private function plan(array $components, bool $full): string
    {
        $path = $this->tmp . '/plan-' . uniqid('', true) . '.json';
        file_put_contents($path, json_encode([
            'schema_version' => 1,
            // A fixture plan may declare itself "the full default plan" so a
            // green run can be a qualification; real plans are the default table.
            'qualifies' => $full,
            'components' => $components,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /**
     * @param array{tests:int,failures:int,errors:int,skipped:int}|null $junit
     * @return array<string, mixed>
     */
    private function component(string $id, string $php, ?array $junit = null, bool $declaresJunit = false): array
    {
        $writeJunit = '';
        if ($junit !== null) {
            $declaresJunit = true;
            $xml = sprintf(
                '<?xml version="1.0"?><testsuites><testsuite name="%s" tests="%d" assertions="%d" errors="%d" failures="%d" skipped="%d" time="0.1"/></testsuites>',
                $id,
                $junit['tests'],
                $junit['tests'],
                $junit['errors'],
                $junit['failures'],
                $junit['skipped'],
            );
            $writeJunit = 'file_put_contents(getenv("WAASEYAA_QUALIFY_JUNIT"), ' . var_export($xml, true) . ');';
        }

        return [
            'id' => $id,
            'command' => [PHP_BINARY, '-r', $writeJunit . ' ' . $php],
            'junit' => $declaresJunit,
        ];
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $env
     * @return array{int, string, array<string, mixed>}
     */
    private function qualify(array $arguments, array $env = []): array
    {
        $out = $this->tmp . '/evidence-' . uniqid('', true);
        $process = $this->process([...$arguments, '--out=' . $out], $env);
        $exit = $process->run();
        $output = $process->getOutput() . $process->getErrorOutput();
        $receiptPath = $out . '/receipt.json';
        self::assertFileExists($receiptPath, "A receipt must be written even on failure.\n{$output}");
        /** @var array<string, mixed> $receipt */
        $receipt = json_decode((string) file_get_contents($receiptPath), true, 512, JSON_THROW_ON_ERROR);

        return [$exit, $output, $receipt];
    }

    /** @param list<string> $arguments @param array<string, string> $env */
    private function process(array $arguments, array $env = []): Process
    {
        return new Process(
            [PHP_BINARY, $this->runner, '--repo=' . $this->tmp, ...$arguments],
            $this->repoRoot,
            $env + ['WAASEYAA_QUALIFY_INTERRUPT_AFTER' => false],
            null,
            60,
        );
    }

    /** @param array<string, mixed> $receipt @return array<string, mixed> */
    private function componentNamed(array $receipt, string $id): array
    {
        foreach ($receipt['components'] as $component) {
            if ($component['id'] === $id) {
                return $component;
            }
        }
        self::fail("component {$id} missing from receipt");
    }

    private function head(): string
    {
        return trim($this->git('rev-parse', 'HEAD'));
    }

    private function git(string ...$args): string
    {
        $process = new Process(['git', '-C', $this->tmp, ...$args]);
        $process->mustRun();

        return $process->getOutput();
    }
}
