<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
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
    public function an_all_green_custom_plan_is_passed_but_never_a_qualification(): void
    {
        $plan = $this->plan([
            $this->component('alpha', 'exit(0);', junit: ['tests' => 3, 'failures' => 0, 'errors' => 0, 'skipped' => 0]),
            $this->component('beta', 'exit(0);'),
        ]);

        [$exit, $out, $receipt] = $this->qualify(['--plan=' . $plan]);

        self::assertSame(0, $exit, $out);
        self::assertSame('passed', $receipt['verdict']);
        self::assertFalse($receipt['qualification']);
        self::assertSame(['custom_plan'], $receipt['disqualifiers']);
        self::assertStringContainsString('qualification: false', $out);
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
    public function a_plan_declaring_qualifies_is_rejected(): void
    {
        $plan = $this->rawPlan(json_encode([
            'schema_version' => 1,
            'qualifies' => true,
            'components' => [$this->component('alpha', 'exit(0);')],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $process = $this->process(['--plan=' . $plan, '--out=' . $this->tmp . '/evidence-rejected']);
        $exit = $process->run();
        $err = $process->getErrorOutput();

        self::assertSame(2, $exit, $process->getOutput() . $err);
        self::assertStringContainsString('custom plans cannot declare qualification', $err);
        self::assertFileDoesNotExist($this->tmp . '/evidence-rejected/alpha.log', 'No component may run once the plan is rejected.');
    }

    #[Test]
    public function a_failing_child_fails_the_run_with_its_real_exit_status(): void
    {
        $plan = $this->plan([
            $this->component('alpha', 'exit(0);'),
            $this->component('beta', 'fwrite(STDOUT, "OK (999 tests)\n"); exit(7);'),
        ]);

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
        ]);

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
        ]);

        [$exit, $out, $receipt] = $this->qualify(['--plan=' . $plan]);

        self::assertSame(0, $exit, $out);
        $alpha = $this->componentNamed($receipt, 'alpha');
        self::assertSame('passed', $alpha['outcome']);
        self::assertSame(2, $alpha['counts']['skipped']);
        self::assertStringContainsString('skipped', $out, 'The summary must surface skipped counts.');
    }

    #[Test]
    #[DataProvider('invalidJunitDocuments')]
    public function malformed_or_invalid_junit_counts_are_an_evidence_error(string $junit): void
    {
        $plan = $this->plan([$this->componentWithJunit('alpha', $junit)]);

        [$exit, $out, $receipt] = $this->qualify(['--plan=' . $plan]);

        self::assertSame(2, $exit, $out);
        self::assertSame('evidence_error', $receipt['verdict']);
        self::assertFalse($receipt['qualification']);
        $alpha = $this->componentNamed($receipt, 'alpha');
        self::assertSame('evidence_error', $alpha['outcome']);
        self::assertArrayNotHasKey('counts', $alpha);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidJunitDocuments(): iterable
    {
        yield 'non-junit root' => ['<?xml version="1.0"?><report tests="1" failures="0" errors="0" skipped="0"/>'];
        yield 'no suites' => ['<?xml version="1.0"?><testsuites/>'];
        yield 'missing count' => ['<?xml version="1.0"?><testsuites><testsuite tests="1" failures="0" errors="0"/></testsuites>'];
        yield 'negative count' => ['<?xml version="1.0"?><testsuites><testsuite tests="1" failures="-1" errors="0" skipped="0"/></testsuites>'];
        yield 'non-integer count' => ['<?xml version="1.0"?><testsuites><testsuite tests="1.5" failures="0" errors="0" skipped="0"/></testsuites>'];
    }

    #[Test]
    public function a_missing_junit_for_a_component_that_promised_one_is_an_evidence_error(): void
    {
        $plan = $this->plan([
            // exits 0 but never writes the junit file it declared
            $this->component('alpha', 'exit(0);', junit: null, declaresJunit: true),
        ]);

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
        ]);

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
        $plan = $this->plan([$this->component('alpha', 'exit(0);', junit: null, declaresJunit: true)]);

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
        ]);
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
        $plan = $this->plan([$this->component('alpha', 'exit(0);')]);

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
        ]);

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
        ]);

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
        $plan = $this->plan([$this->component('alpha', 'exit(0);')]);

        $process = $this->process(['--plan=' . $plan]);
        self::assertSame(3, $process->run(), $process->getOutput() . $process->getErrorOutput());

        [$exit, $out, $receipt] = $this->qualify(['--plan=' . $plan, '--allow-dirty']);
        self::assertSame(0, $exit, $out);
        self::assertSame('passed', $receipt['verdict']);
        self::assertFalse($receipt['qualification'], 'Evidence from a dirty tree is never a qualification.');
        self::assertContains('dirty_worktree', $receipt['disqualifiers']);
        self::assertSame(['tracked.txt'], array_map(static fn(string $l): string => trim(substr($l, 3)), $receipt['candidate']['dirty_at_start']));
    }

    #[Test]
    public function a_subset_run_is_passed_with_a_subset_disqualifier_and_never_a_qualification(): void
    {
        $plan = $this->plan([
            $this->component('alpha', 'exit(0);'),
            $this->component('beta', 'exit(0);'),
        ]);

        [$exit, $out, $receipt] = $this->qualify(['--plan=' . $plan, '--only=alpha']);

        self::assertSame(0, $exit, $out);
        self::assertSame('passed', $receipt['verdict']);
        self::assertFalse($receipt['qualification']);
        self::assertContains('subset', $receipt['disqualifiers']);
        self::assertCount(1, $receipt['components']);
    }

    #[Test]
    public function a_failed_preflight_leaves_expensive_components_explicitly_unrun(): void
    {
        $marker = $this->tmp . '/expensive-component-ran';
        $writeMarker = 'file_put_contents(' . var_export($marker, true) . ', "ran\\n", FILE_APPEND); exit(0);';
        $plan = $this->plan([
            $this->component('preflight', 'fwrite(STDERR, "preflight rejected\\n"); exit(7);'),
            $this->component('unit', $writeMarker),
            $this->component('integration', $writeMarker),
        ]);

        [$exit, $out, $receipt] = $this->qualify(['--plan=' . $plan, '--jobs=3']);

        self::assertSame(1, $exit, $out);
        self::assertSame('failed', $receipt['verdict']);
        self::assertFalse($receipt['qualification']);
        self::assertSame(2, $receipt['runner']['schema_version']);
        self::assertFalse($receipt['runner']['collect_all']);
        self::assertFileDoesNotExist($marker, 'No held expensive component may start after preflight fails.');
        self::assertSame('failed', $this->componentNamed($receipt, 'preflight')['outcome']);
        foreach (['unit', 'integration'] as $id) {
            $component = $this->componentNamed($receipt, $id);
            self::assertSame('unrun', $component['outcome']);
            self::assertSame('not_started', $component['termination']);
            self::assertNull($component['started_at']);
            self::assertNull($component['finished_at']);
            self::assertNull($component['exit_code']);
            self::assertStringContainsString('preflight', $component['reason']);
            self::assertNull($component['log']);
            self::assertNull($component['junit']);
        }
    }

    #[Test]
    public function a_preflight_evidence_error_keeps_expensive_components_unrun(): void
    {
        $marker = $this->tmp . '/evidence-error-held-component-ran';
        $plan = $this->plan([
            $this->component('preflight', 'exit(0);', junit: null, declaresJunit: true),
            $this->component('unit', 'file_put_contents(' . var_export($marker, true) . ', "ran\\n"); exit(0);'),
        ]);

        [$exit, $out, $receipt] = $this->qualify(['--plan=' . $plan, '--jobs=2']);

        self::assertSame(2, $exit, $out);
        self::assertSame('evidence_error', $receipt['verdict']);
        self::assertFalse($receipt['qualification']);
        self::assertFileDoesNotExist($marker);
        self::assertSame('evidence_error', $this->componentNamed($receipt, 'preflight')['outcome']);
        $unit = $this->componentNamed($receipt, 'unit');
        self::assertSame('unrun', $unit['outcome']);
        self::assertSame('not_started', $unit['termination']);
        self::assertNull($unit['started_at']);
        self::assertNull($unit['finished_at']);
        self::assertNull($unit['exit_code']);
        self::assertNull($unit['log']);
        self::assertNull($unit['junit']);
    }

    #[Test]
    public function interruption_before_the_barrier_opens_keeps_expensive_components_unrun(): void
    {
        $marker = $this->tmp . '/interrupted-held-component-ran';
        $plan = $this->plan([
            $this->component('preflight', 'exit(0);'),
            $this->component('unit', 'file_put_contents(' . var_export($marker, true) . ', "ran\\n"); exit(0);'),
        ]);

        [$exit, $out, $receipt] = $this->qualify(
            ['--plan=' . $plan, '--jobs=2'],
            env: ['WAASEYAA_QUALIFY_INTERRUPT_AFTER' => 'preflight'],
        );

        self::assertSame(130, $exit, $out);
        self::assertSame('interrupted', $receipt['verdict']);
        self::assertFalse($receipt['qualification']);
        self::assertFileDoesNotExist($marker);
        self::assertSame('passed', $this->componentNamed($receipt, 'preflight')['outcome']);
        $unit = $this->componentNamed($receipt, 'unit');
        self::assertSame('unrun', $unit['outcome']);
        self::assertSame('not_started', $unit['termination']);
        self::assertNull($unit['started_at']);
        self::assertNull($unit['finished_at']);
        self::assertNull($unit['exit_code']);
        self::assertStringContainsString('interrupted', $unit['reason']);
        self::assertNull($unit['log']);
        self::assertNull($unit['junit']);
    }

    #[Test]
    public function suite_failures_are_collected_after_the_preflight_barrier_opens(): void
    {
        $marker = $this->tmp . '/later-suites-ran';
        $plan = $this->plan([
            $this->component('preflight', 'exit(0);'),
            $this->component('unit', 'exit(9);'),
            $this->component('integration', 'file_put_contents(' . var_export($marker, true) . ', "integration\\n", FILE_APPEND); exit(0);'),
            $this->component('architecture', 'file_put_contents(' . var_export($marker, true) . ', "architecture\\n", FILE_APPEND); exit(0);'),
        ]);

        [$exit, $out, $receipt] = $this->qualify(['--plan=' . $plan, '--jobs=1']);

        self::assertSame(1, $exit, $out);
        self::assertSame("integration\narchitecture\n", file_get_contents($marker));
        self::assertSame('passed', $this->componentNamed($receipt, 'preflight')['outcome']);
        self::assertSame('failed', $this->componentNamed($receipt, 'unit')['outcome']);
        self::assertSame('passed', $this->componentNamed($receipt, 'integration')['outcome']);
        self::assertSame('passed', $this->componentNamed($receipt, 'architecture')['outcome']);
    }

    #[Test]
    public function collect_all_explicitly_runs_diagnostics_after_a_failed_preflight(): void
    {
        $marker = $this->tmp . '/diagnostic-ran';
        $plan = $this->plan([
            $this->component('preflight', 'exit(7);'),
            $this->component('unit', 'file_put_contents(' . var_export($marker, true) . ', "ran\\n"); exit(0);'),
        ]);

        [$exit, $out, $receipt] = $this->qualify(['--plan=' . $plan, '--jobs=2', '--collect-all']);

        self::assertSame(1, $exit, $out);
        self::assertFileExists($marker);
        self::assertTrue($receipt['runner']['collect_all']);
        self::assertSame('failed', $this->componentNamed($receipt, 'preflight')['outcome']);
        self::assertSame('passed', $this->componentNamed($receipt, 'unit')['outcome']);
    }

    #[Test]
    public function concurrent_components_are_all_bound_to_one_head_and_tree(): void
    {
        $plan = $this->plan([
            $this->component('alpha', 'usleep(200000); exit(0);'),
            $this->component('beta', 'usleep(200000); exit(0);'),
            $this->component('gamma', 'exit(0);'),
        ]);

        [$exit, $out, $receipt] = $this->qualify(['--plan=' . $plan, '--jobs=3']);

        self::assertSame(0, $exit, $out);
        self::assertSame(3, $receipt['runner']['jobs']);
        self::assertSame('passed', $receipt['verdict']);
        self::assertSame(['custom_plan'], $receipt['disqualifiers']);
        self::assertCount(3, $receipt['components']);
        self::assertSame($receipt['candidate']['tree'], $receipt['source_check']['tree_after']);
    }

    #[Test]
    public function a_reused_out_directory_supersedes_the_prior_receipt_before_children_start(): void
    {
        $out = $this->tmp . '/evidence-supersede-kill';
        mkdir($out);
        file_put_contents(
            $out . '/receipt.json',
            json_encode(['verdict' => 'qualified', 'qualification' => true, 'marker' => 'OLD'], JSON_THROW_ON_ERROR),
        );
        $marker = 'waaseyaa_qualify_sleeper_' . bin2hex(random_bytes(8));
        $plan = $this->plan([$this->component('sleeper', sprintf('/* %s */ sleep(20); exit(0);', $marker))]);

        $process = $this->process(['--plan=' . $plan, '--out=' . $out]);
        $process->start();
        try {
            usleep(1_000_000);
            $process->signal(9);
            $process->wait();

            self::assertFileExists($out . '/receipt.json');
            $receipt = json_decode((string) file_get_contents($out . '/receipt.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('in_progress', $receipt['verdict']);
            self::assertFalse($receipt['qualification']);
            self::assertNull($receipt['runner']['finished_at']);

            $superseded = glob($out . '/receipt.superseded-*.json');
            self::assertCount(1, $superseded, 'Exactly one superseded receipt must be preserved.');
            self::assertStringContainsString('OLD', (string) file_get_contents($superseded[0]));
        } finally {
            // The 20s sleeper is orphaned by the SIGKILL on the runner (which
            // never had a chance to terminate it); reap it via its unique
            // marker argument so it does not outlive the test.
            $this->pkillMarker($marker);
        }
    }

    #[Test]
    public function a_reused_out_directory_that_completes_reports_only_the_new_run(): void
    {
        $out = $this->tmp . '/evidence-supersede-complete';
        mkdir($out);
        file_put_contents(
            $out . '/receipt.json',
            json_encode(['verdict' => 'qualified', 'qualification' => true, 'marker' => 'OLD'], JSON_THROW_ON_ERROR),
        );
        $plan = $this->plan([$this->component('alpha', 'exit(0);')]);

        $process = $this->process(['--plan=' . $plan, '--out=' . $out]);
        $exit = $process->run();
        $receipt = json_decode((string) file_get_contents($out . '/receipt.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $exit, $process->getOutput() . $process->getErrorOutput());
        self::assertSame('passed', $receipt['verdict']);
        self::assertArrayNotHasKey('marker', $receipt);
        self::assertStringNotContainsString('OLD', (string) file_get_contents($out . '/receipt.json'));
        $superseded = glob($out . '/receipt.superseded-*.json');
        self::assertCount(1, $superseded, 'The prior receipt must be preserved, never deleted.');
        self::assertStringContainsString('OLD', (string) file_get_contents($superseded[0]));
    }

    #[Test]
    public function a_new_out_directory_has_no_superseded_receipts(): void
    {
        $out = $this->tmp . '/evidence-fresh';
        $plan = $this->plan([$this->component('alpha', 'exit(0);')]);

        $process = $this->process(['--plan=' . $plan, '--out=' . $out]);
        $exit = $process->run();

        self::assertSame(0, $exit, $process->getOutput() . $process->getErrorOutput());
        self::assertFileExists($out . '/receipt.json');
        self::assertSame([], glob($out . '/receipt.superseded-*.json'));
    }

    #[Test]
    public function an_invalid_only_selection_finalizes_the_in_progress_receipt(): void
    {
        $out = $this->tmp . '/evidence-invalid-only';
        $plan = $this->plan([$this->component('alpha', 'exit(0);')]);

        $process = $this->process(['--plan=' . $plan, '--only=missing', '--out=' . $out]);
        $exit = $process->run();
        $receipt = json_decode((string) file_get_contents($out . '/receipt.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(2, $exit, $process->getOutput() . $process->getErrorOutput());
        self::assertSame('evidence_error', $receipt['verdict']);
        self::assertFalse($receipt['qualification']);
        self::assertSame([], $receipt['components']);
        self::assertNotNull($receipt['runner']['finished_at']);
    }

    // ---------------------------------------------------------------- helpers

    /** @param list<array<string, mixed>> $components */
    private function plan(array $components): string
    {
        $path = $this->tmp . '/plan-' . uniqid('', true) . '.json';
        file_put_contents($path, json_encode([
            'schema_version' => 1,
            // The plan schema has no `qualifies` key: a --plan is always a
            // custom plan and can never itself be a qualification.
            'components' => $components,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /** A raw plan file bypassing the component()/plan() fixture helpers, for schema-rejection cases. */
    private function rawPlan(string $json): string
    {
        $path = $this->tmp . '/plan-' . uniqid('', true) . '.json';
        file_put_contents($path, $json);

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

    /** @return array<string, mixed> */
    private function componentWithJunit(string $id, string $junit): array
    {
        $writeJunit = 'file_put_contents(getenv("WAASEYAA_QUALIFY_JUNIT"), ' . var_export($junit, true) . ');';

        return [
            'id' => $id,
            'command' => [PHP_BINARY, '-r', $writeJunit . ' exit(0);'],
            'junit' => true,
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

    /** Best-effort reap of an orphaned fixture child by its unique marker argument. */
    private function pkillMarker(string $marker): void
    {
        new Process(['pkill', '-9', '-f', $marker])->run();
    }
}
