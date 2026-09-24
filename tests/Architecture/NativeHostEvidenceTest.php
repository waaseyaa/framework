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
 * Fixture proofs for bin/native-host-evidence (#2678,
 * FW-2678-NATIVE-HOST-CONTRACT-01): the collector and verifier fail closed on
 * every missing identity, unexecuted step, skipped, incomplete or unlisted
 * test, empty selection, mismatched subject and wrong evidence set.
 *
 * The real `pwsh` and `sh` round trips run in the hosted native-host-contract
 * leaves, where the hosted shell exists; here the shells are lexical models
 * of the single-quote forms the renderers emit, so this class needs no shell
 * a contributor might lack. It is part of the native-host contract itself.
 */
#[CoversNothing]
final class NativeHostEvidenceTest extends TestCase
{
    private const MERGE_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const PR_HEAD_SHA = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const DISPATCH_SHA = 'cccccccccccccccccccccccccccccccccccccccc';

    private static string $root;
    private ?string $scratch = null;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
        require_once self::$root . '/bin/lib/native-host-evidence.php';
    }

    protected function tearDown(): void
    {
        if ($this->scratch !== null) {
            new Filesystem()->remove($this->scratch);
            $this->scratch = null;
        }
    }

    #[Test]
    public function the_tracked_contract_is_valid_and_its_digest_ignores_line_endings(): void
    {
        $path = self::$root . '/tools/native-host-contract.json';
        $contract = \nhe_load_contract($path);
        $lf = str_replace("\r\n", "\n", (string) file_get_contents($path));
        file_put_contents($this->scratch() . '/lf.json', $lf);
        file_put_contents($this->scratch() . '/crlf.json', str_replace("\n", "\r\n", $lf));

        self::assertSame([], \nhe_validate_contract($contract));
        self::assertSame(\nhe_contract_digest($contract), \nhe_contract_digest(\nhe_load_contract($this->scratch() . '/lf.json')));
        self::assertSame(\nhe_contract_digest($contract), \nhe_contract_digest(\nhe_load_contract($this->scratch() . '/crlf.json')));
        self::assertSame(['linux', 'windows'], array_keys($contract['hosts']));
        self::assertSame(
            ['composer-install', 'root-hygiene-before', 'composer-policy', 'portable-paths', 'null-device-self-test', 'phpunit-unit', 'phpunit-integration', 'phpunit-architecture', 'root-hygiene-after'],
            array_column($contract['commands'], 'id'),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function weakenedContracts(): iterable
    {
        yield 'a PHPUnit command that tolerates skips' => ['drop-fail-on-skipped', 'must pass --fail-on-skipped'];
        yield 'a PHPUnit command that tolerates incomplete tests' => ['drop-fail-on-incomplete', 'must pass --fail-on-incomplete'];
        yield 'a PHPUnit command that tolerates an empty selection' => ['drop-fail-on-empty', 'must pass --fail-on-empty-test-suite'];
        yield 'a PHPUnit command without an OTR log' => ['drop-otr', 'must pass --log-otr <path>'];
        yield 'a PHPUnit command without expected methods' => ['drop-expected', 'expected_methods must be a non-empty list'];
        yield 'a duplicated step id' => ['duplicate-id', 'is duplicated'];
        yield 'a token no shell renders faithfully' => ['double-quote', 'without a double quote'];
        yield 'a program that is not php or composer' => ['bash-program', 'argv[0] must be one of php, composer'];
        yield 'a hosted shell other than pwsh' => ['cmd-shell', 'harness_shell must be pwsh'];
        yield 'an empty runtime range' => ['empty-range', 'runtime.composer must be {min, below}'];
        yield 'the PowerShell stop-parsing token' => ['stop-parsing', 'which no rendering passes literally'];
    }

    #[Test]
    #[DataProvider('weakenedContracts')]
    public function contract_validation_rejects_weakened_or_unrenderable_commands(string $mutation, string $expected): void
    {
        $contract = self::fixtureContract();
        $remove = static function (array $argv, string $token): array {
            $position = array_search($token, $argv, true);
            unset($argv[$position]);

            return array_values($argv);
        };
        match ($mutation) {
            'drop-fail-on-skipped' => $contract['commands'][1]['argv'] = $remove($contract['commands'][1]['argv'], '--fail-on-skipped'),
            'drop-fail-on-incomplete' => $contract['commands'][1]['argv'] = $remove($contract['commands'][1]['argv'], '--fail-on-incomplete'),
            'drop-fail-on-empty' => $contract['commands'][1]['argv'] = $remove($contract['commands'][1]['argv'], '--fail-on-empty-test-suite'),
            'drop-otr' => $contract['commands'][1]['argv'] = array_slice($contract['commands'][1]['argv'], 0, -3),
            'drop-expected' => $contract['commands'][1]['expected_methods'] = [],
            'duplicate-id' => $contract['commands'][1]['id'] = 'gate',
            'double-quote' => $contract['commands'][0]['argv'][] = '--name="x"',
            'bash-program' => $contract['commands'][0]['argv'][0] = 'bash',
            'cmd-shell' => $contract['harness_shell'] = 'cmd',
            'empty-range' => $contract['runtime']['composer'] = ['min' => '2.10.0', 'below' => '2.10.0'],
            'stop-parsing' => $contract['commands'][0]['argv'][] = '--%',
        };

        self::assertStringContainsString($expected, implode("\n", \nhe_validate_contract($contract)));
        self::assertSame([], \nhe_validate_contract(self::fixtureContract()), 'The unmutated fixture is valid.');
    }

    #[Test]
    public function renderings_quote_exactly_what_each_replay_shell_would_reinterpret(): void
    {
        $argv = ['php', 'vendor/bin/phpunit', '--exclude-filter', '/OnLinux$/', "it's", 'a,b', 'C:\\Temp\\probe'];

        self::assertSame(
            "& 'php' 'vendor/bin/phpunit' '--exclude-filter' '/OnLinux$/' 'it''s' 'a,b' 'C:\\Temp\\probe'",
            \nhe_render_powershell($argv),
        );
        self::assertSame(
            "'php' 'vendor/bin/phpunit' '--exclude-filter' '/OnLinux$/' 'it'\\''s' 'a,b' 'C:\\Temp\\probe'",
            \nhe_render_posix($argv),
        );
        self::assertSame("& 'C:\\php\\php.exe' '-v'", \nhe_render_powershell(['C:\\php\\php.exe', '-v']), 'A quoted program runs through the call operator.');
        self::assertSame("& 'php' 'curly' 'it\u{2019}\u{2019}s'", \nhe_render_powershell(['php', 'curly', "it\u{2019}s"]), 'PowerShell also ends a literal at a Unicode single quote.');
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function switchShapedAndSpecialArguments(): iterable
    {
        yield 'a dotted switch PowerShell would split if bare' => ['-Dfoo.bar', "'-Dfoo.bar'", "'-Dfoo.bar'"];
        yield 'a dotted switch with a value' => ['-Dfoo.bar=value', "'-Dfoo.bar=value'", "'-Dfoo.bar=value'"];
        yield 'spaces' => ['two words', "'two words'", "'two words'"];
        yield 'an embedded single quote' => ["it's", "'it''s'", "'it'\\''s'"];
        yield 'an empty argument' => ['', "''", "''"];
        yield 'a Windows path with a space and a trailing backslash' => ['C:\\Program Files\\Waaseyaa\\', "'C:\\Program Files\\Waaseyaa\\'", "'C:\\Program Files\\Waaseyaa\\'"];
        yield 'a UNC path' => ['\\\\server\\share\\dir', "'\\\\server\\share\\dir'", "'\\\\server\\share\\dir'"];
        yield 'a backslash' => ['back\\slash', "'back\\slash'", "'back\\slash'"];
        yield 'a variable reference' => ['$HOME', "'\$HOME'", "'\$HOME'"];
        yield 'a PowerShell array separator' => ['a,b', "'a,b'", "'a,b'"];
    }

    #[Test]
    #[DataProvider('switchShapedAndSpecialArguments')]
    public function every_argument_is_rendered_as_a_literal_that_parses_back_unchanged(string $argument, string $powershell, string $posix): void
    {
        $argv = ['php', $argument, 'tail'];

        self::assertSame("& 'php' {$powershell} 'tail'", \nhe_render_powershell($argv));
        self::assertSame("'php' {$posix} 'tail'", \nhe_render_posix($argv));
        self::assertSame($argv, self::parsePowerShell(\nhe_render_powershell($argv)));
        self::assertSame($argv, self::parsePosix(\nhe_render_posix($argv)));
        self::assertContains($argument, \NHE_RENDERING_REGRESSION_ARGV, 'The hosted leaves round-trip this argument through the real shells.');
    }

    #[Test]
    public function every_contract_command_renders_to_tokens_each_replay_shell_parses_back(): void
    {
        $contract = \nhe_load_contract(self::$root . '/tools/native-host-contract.json');
        foreach ($contract['commands'] as $command) {
            self::assertSame($command['argv'], self::parsePowerShell(\nhe_render_powershell($command['argv'])), $command['id']);
            self::assertSame($command['argv'], self::parsePosix(\nhe_render_posix($command['argv'])), $command['id']);
        }
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function stepResults(): iterable
    {
        yield 'both governed steps succeeded' => ["gate success 0\ntests success 0\n", []];
        yield 'a skipped step' => ["gate skipped 0\ntests success 0\n", ['step gate finished skipped']];
        yield 'a failed step with its exit code' => ["gate failure 1\ntests success 0\n", ['step gate finished failure', 'step gate exited 1']];
        yield 'a step that never started records no exit code' => ["gate failure \ntests success 0\n", ['must be "<id> <outcome> <exit code>"']];
        yield 'an empty outcome from a missing step id' => ["gate  \ntests success 0\n", ['must be "<id> <outcome> <exit code>"']];
        yield 'a missing step' => ["tests success 0\n", ['step gate has no recorded result']];
        yield 'a duplicated step' => ["gate success 0\ngate success 0\ntests success 0\n", ['step gate is reported more than once']];
        yield 'an unknown step' => ["gate success 0\ntests success 0\nextra success 0\n", ['step extra is not a governed contract step']];
        yield 'a non-numeric exit code' => ["gate success zero\ntests success 0\n", ['recorded no numeric exit code']];
        yield 'success with a non-zero exit code' => ["gate success 3\ntests success 0\n", ['step gate exited 3']];
    }

    /** @param list<string> $expected */
    #[Test]
    #[DataProvider('stepResults')]
    public function step_results_accept_only_one_successful_zero_exit_per_governed_step(string $raw, array $expected): void
    {
        $violations = \nhe_parse_step_results($raw, ['gate', 'tests'])['violations'];

        if ($expected === []) {
            self::assertSame([], $violations);
        }
        foreach ($expected as $fragment) {
            self::assertStringContainsString($fragment, implode("\n", $violations));
        }
    }

    /** @return iterable<string, array{array<string, string>, string|null, string|null, string|null, string|null}> */
    public static function subjects(): iterable
    {
        $run = ['GITHUB_RUN_ID' => '42', 'GITHUB_RUN_ATTEMPT' => '1'];
        yield 'a pull request binds the merge ref and records the head separately' => [
            $run + ['GITHUB_EVENT_NAME' => 'pull_request', 'GITHUB_SHA' => self::MERGE_SHA, 'NATIVE_HOST_PR_HEAD_SHA' => self::PR_HEAD_SHA],
            self::MERGE_SHA, 'merge-ref-sha', null, null,
        ];
        yield 'a pull request checkout of the head is not the merge-ref subject' => [
            $run + ['GITHUB_EVENT_NAME' => 'pull_request', 'GITHUB_SHA' => self::MERGE_SHA, 'NATIVE_HOST_PR_HEAD_SHA' => self::PR_HEAD_SHA],
            self::PR_HEAD_SHA, 'merge-ref-sha', 'is not the merge-ref-sha subject', null,
        ];
        yield 'a pull request without its head SHA' => [
            $run + ['GITHUB_EVENT_NAME' => 'pull_request', 'GITHUB_SHA' => self::MERGE_SHA],
            self::MERGE_SHA, 'merge-ref-sha', 'must record the pull-request head SHA', null,
        ];
        yield 'a dispatch with an exact SHA binds that SHA' => [
            $run + ['GITHUB_EVENT_NAME' => 'workflow_dispatch', 'GITHUB_SHA' => self::MERGE_SHA, 'NATIVE_HOST_DISPATCH_SHA' => self::DISPATCH_SHA],
            self::DISPATCH_SHA, 'dispatched-sha', null, null,
        ];
        yield 'a dispatch with an exact SHA rejects any other checkout' => [
            $run + ['GITHUB_EVENT_NAME' => 'workflow_dispatch', 'GITHUB_SHA' => self::MERGE_SHA, 'NATIVE_HOST_DISPATCH_SHA' => self::DISPATCH_SHA],
            self::MERGE_SHA, 'dispatched-sha', 'is not the dispatched-sha subject', null,
        ];
        yield 'a dispatch without a SHA binds the dispatched ref' => [
            $run + ['GITHUB_EVENT_NAME' => 'workflow_dispatch', 'GITHUB_SHA' => self::MERGE_SHA, 'NATIVE_HOST_DISPATCH_SHA' => ''],
            self::MERGE_SHA, 'dispatched-ref', null, null,
        ];
        yield 'a dispatch without a SHA rejects another checkout' => [
            $run + ['GITHUB_EVENT_NAME' => 'workflow_dispatch', 'GITHUB_SHA' => self::MERGE_SHA],
            self::DISPATCH_SHA, 'dispatched-ref', 'is not the dispatched-ref subject', null,
        ];
        yield 'a push binds the pushed SHA' => [
            $run + ['GITHUB_EVENT_NAME' => 'push', 'GITHUB_SHA' => self::MERGE_SHA],
            self::MERGE_SHA, 'main-sha', null, null,
        ];
        yield 'an event with no subject profile' => [
            $run + ['GITHUB_EVENT_NAME' => 'schedule', 'GITHUB_SHA' => self::MERGE_SHA],
            self::MERGE_SHA, null, 'has no native-host subject profile', null,
        ];
        yield 'an unresolvable checkout is incomplete, never a pass' => [
            $run + ['GITHUB_EVENT_NAME' => 'push', 'GITHUB_SHA' => self::MERGE_SHA],
            null, 'main-sha', null, 'checked-out HEAD could not be resolved',
        ];
        yield 'a missing run identity is incomplete' => [
            ['GITHUB_EVENT_NAME' => 'push', 'GITHUB_SHA' => self::MERGE_SHA],
            self::MERGE_SHA, 'main-sha', null, 'GITHUB_RUN_ID is not set',
        ];
    }

    /** @param array<string, string> $env */
    #[Test]
    #[DataProvider('subjects')]
    public function the_subject_follows_the_event_and_never_substitutes_the_pull_request_head(
        array $env,
        ?string $head,
        ?string $profile,
        ?string $violation,
        ?string $incomplete,
    ): void {
        $subject = \nhe_subject($env, $head);

        self::assertSame($profile, $subject['subject']['profile']);
        self::assertSame($head, $subject['subject']['checked_out_head']);
        self::assertSame($env['NATIVE_HOST_PR_HEAD_SHA'] ?? null, $subject['subject']['pull_request_head_sha']);
        $violation === null
            ? self::assertSame([], $subject['violations'])
            : self::assertStringContainsString($violation, implode("\n", $subject['violations']));
        $incomplete === null
            ? self::assertSame([], $subject['incomplete'])
            : self::assertStringContainsString($incomplete, implode("\n", $subject['incomplete']));
    }

    /** @return iterable<string, array{list<array{string, string}>, int, list<string>, list<string>}> */
    public static function phpunitLogs(): iterable
    {
        $both = ['Fx\\ATest::first', 'Fx\\ATest::second'];
        yield 'every expected method succeeded' => [[['first', 'SUCCESSFUL'], ['second', 'SUCCESSFUL']], 0, $both, []];
        yield 'a skipped test' => [[['first', 'SUCCESSFUL'], ['second', 'SKIPPED']], 1, $both, ['reported 1 skipped']];
        yield 'an incomplete test' => [[['first', 'SUCCESSFUL'], ['second', 'ABORTED']], 1, $both, ['reported 1 incomplete']];
        yield 'a failed test' => [[['first', 'SUCCESSFUL'], ['second', 'FAILED']], 0, $both, ['reported 1 failures']];
        yield 'an empty selection' => [[], 0, $both, ['executed no tests', 'did not execute the expected method Fx\\ATest::first']];
        yield 'an expected method that never ran' => [[['first', 'SUCCESSFUL']], 0, $both, ['did not execute the expected method Fx\\ATest::second']];
        yield 'a method the contract does not list' => [[['first', 'SUCCESSFUL'], ['second', 'SUCCESSFUL']], 0, ['Fx\\ATest::first'], ['executed Fx\\ATest::second, which the contract does not list']];
        yield 'logs that disagree about skips' => [[['first', 'SUCCESSFUL'], ['second', 'SKIPPED']], 0, $both, ['JUnit reports 0 skipped or incomplete tests but OTR reports 1 skipped']];
    }

    /**
     * @param list<array{string, string}> $cases method and OTR status
     * @param list<string> $expectedMethods
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('phpunitLogs')]
    public function phpunit_logs_fail_closed_on_skips_incomplete_empty_and_unlisted_methods(
        array $cases,
        int $junitSkipped,
        array $expectedMethods,
        array $expected,
    ): void {
        [$junit, $otr] = $this->writeLogs($this->scratch(), 't', $cases, $junitSkipped);

        $results = \nhe_phpunit_results($junit, $otr);
        $violations = [...$results['violations'], ...\nhe_phpunit_violations('tests', $results['counts'], $results['methods'], $expectedMethods)];

        if ($expected === []) {
            self::assertSame([], $violations);
            self::assertSame(2, $results['counts']['tests']);
        }
        foreach ($expected as $fragment) {
            self::assertStringContainsString($fragment, implode("\n", $violations));
        }
    }

    #[Test]
    public function missing_logs_are_a_violation_not_an_empty_pass(): void
    {
        $results = \nhe_phpunit_results($this->scratch() . '/absent.junit.xml', $this->scratch() . '/absent.otr.xml');

        self::assertStringContainsString('missing or unreadable', implode("\n", $results['violations']));
        self::assertStringContainsString('executed no tests', implode("\n", \nhe_phpunit_violations('tests', $results['counts'], $results['methods'], ['Fx\\ATest::first'])));
    }

    #[Test]
    public function collect_passes_only_with_complete_identity_steps_logs_and_round_trips(): void
    {
        $contract = self::fixtureContract();
        $root = $this->scratch();
        $this->writeLogs($root . '/build/native-host', 't', [['first', 'SUCCESSFUL'], ['second', 'SUCCESSFUL']], 0);
        $host = PHP_OS_FAMILY === 'Windows' ? 'windows' : 'linux';
        mkdir($root . '/path');
        touch($root . '/path/composer.bat');

        $passed = \nhe_collect($contract, $host, $root, self::hostEnvironment($host, $root . '/path'), self::shellModel(), self::MERGE_SHA);
        $evidence = $passed['evidence'];

        self::assertSame(\NHE_EXIT_PASS, $passed['exit'], implode("\n", [...$evidence['violations'], ...$evidence['incomplete']]));
        self::assertSame('pass', $evidence['result']);
        self::assertSame('merge-ref-sha', $evidence['subject']['profile']);
        self::assertSame(self::MERGE_SHA, $evidence['subject']['checked_out_head']);
        self::assertSame(self::PR_HEAD_SHA, $evidence['subject']['pull_request_head_sha']);
        self::assertSame(\nhe_contract_digest($contract), $evidence['contract']['sha256']);
        self::assertSame(['name' => 'pwsh', 'version' => '7.4.6', 'role' => 'hosted-harness-only'], $evidence['hosted_shell']);
        self::assertSame('2.10.3', $evidence['runtime']['composer']);
        self::assertFalse($evidence['runtime']['node_required']);
        self::assertSame(2, $evidence['commands'][1]['phpunit']['tests']);
        $replay = $host === 'windows' ? 'powershell' : 'posix_sh';
        self::assertSame($replay, $evidence['replay']['shell']);
        self::assertArrayHasKey($replay, $evidence['commands'][0]);
        self::assertArrayNotHasKey($host === 'windows' ? 'posix_sh' : 'powershell', $evidence['commands'][0]);
        self::assertSame('verified', $evidence['commands'][0]['round_trip']['pwsh']);
        self::assertSame(\NHE_RENDERING_REGRESSION_ARGV, $evidence['rendering_regression']['argv']);
        self::assertSame('verified', $evidence['rendering_regression']['round_trip']['pwsh']);
        if ($host === 'linux') {
            self::assertSame('verified', $evidence['commands'][0]['round_trip']['sh']);
            self::assertSame('verified', $evidence['rendering_regression']['round_trip']['sh']);
        }

        $noComposer = \nhe_collect($contract, $host, $root, self::hostEnvironment($host, $root . '/path'), self::shellModel(composer: null), self::MERGE_SHA);
        self::assertSame(\NHE_EXIT_INCOMPLETE, $noComposer['exit']);
        self::assertSame('incomplete', $noComposer['evidence']['result']);

        $noShellVersion = self::hostEnvironment($host, $root . '/path');
        unset($noShellVersion['NATIVE_HOST_SHELL_VERSION']);
        self::assertSame(\NHE_EXIT_INCOMPLETE, \nhe_collect($contract, $host, $root, $noShellVersion, self::shellModel(), self::MERGE_SHA)['exit']);

        $unstartedShell = \nhe_collect($contract, $host, $root, self::hostEnvironment($host, $root . '/path'), self::shellModel(shellStarts: false), self::MERGE_SHA);
        self::assertSame(\NHE_EXIT_INCOMPLETE, $unstartedShell['exit']);

        $mangled = \nhe_collect($contract, $host, $root, self::hostEnvironment($host, $root . '/path'), self::shellModel(dropLastArgument: true), self::MERGE_SHA);
        self::assertSame(\NHE_EXIT_VIOLATION, $mangled['exit']);
        self::assertStringContainsString('instead of the contract arguments', implode("\n", $mangled['evidence']['violations']));

        $skippedStep = self::hostEnvironment($host, $root . '/path');
        $skippedStep['NATIVE_HOST_STEP_RESULTS'] = "gate skipped 0\ntests success 0\n";
        self::assertSame(\NHE_EXIT_VIOLATION, \nhe_collect($contract, $host, $root, $skippedStep, self::shellModel(), self::MERGE_SHA)['exit']);

        $wrongRunner = self::hostEnvironment($host, $root . '/path');
        $wrongRunner['NATIVE_HOST_RUNNER_LABEL'] = 'ubuntu-22.04';
        self::assertSame(\NHE_EXIT_VIOLATION, \nhe_collect($contract, $host, $root, $wrongRunner, self::shellModel(), self::MERGE_SHA)['exit']);

        $otherHost = $host === 'windows' ? 'linux' : 'windows';
        self::assertStringContainsString(
            "the {$otherHost} leaf ran on PHP OS family",
            implode("\n", \nhe_collect($contract, $otherHost, $root, self::hostEnvironment($otherHost, $root . '/path'), self::shellModel(), self::MERGE_SHA)['evidence']['violations']),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function evidenceSets(): iterable
    {
        yield 'one passing record per host' => ['none', ''];
        yield 'a missing host' => ['missing-windows', 'the windows evidence artifact is missing'];
        yield 'an extra host' => ['extra-host', 'unexpected evidence artifact native-host-evidence-macos'];
        yield 'a failing record' => ['linux-fail', 'the linux evidence record fails the result pass check'];
        yield 'another checkout' => ['other-head', 'fails the checked-out HEAD check'];
        yield 'another contract' => ['other-digest', 'fails the contract digest check'];
        yield 'another run' => ['other-run', 'fails the run id check'];
        yield 'a later attempt than the verifier' => ['later-attempt', 'fails the run attempt check'];
        yield 'different pull-request heads' => ['other-pr-head', 'bind different subjects'];
        yield 'an unreadable record' => ['unreadable', 'the linux evidence record is missing or not JSON'];
    }

    #[Test]
    #[DataProvider('evidenceSets')]
    public function verify_set_accepts_exactly_one_passing_record_per_host_bound_to_one_source(string $mutation, string $expected): void
    {
        $contract = self::fixtureContract();
        $directory = $this->scratch();
        $records = [];
        foreach (['linux', 'windows'] as $host) {
            $records[$host] = [
                'schema' => \NHE_EVIDENCE_SCHEMA,
                'schema_version' => \NHE_SCHEMA_VERSION,
                'result' => 'pass',
                'host' => $host,
                'contract' => ['sha256' => \nhe_contract_digest($contract)],
                'subject' => [
                    'profile' => 'merge-ref-sha',
                    'checked_out_head' => self::MERGE_SHA,
                    'github_sha' => self::MERGE_SHA,
                    'dispatch_sha' => null,
                    'pull_request_head_sha' => self::PR_HEAD_SHA,
                    'event_name' => 'pull_request',
                    'run_id' => 42,
                    'run_attempt' => 1,
                ],
            ];
        }
        match ($mutation) {
            'none', 'missing-windows', 'extra-host', 'unreadable' => null,
            'linux-fail' => $records['linux']['result'] = 'fail',
            'other-head' => $records['linux']['subject']['checked_out_head'] = self::DISPATCH_SHA,
            'other-digest' => $records['windows']['contract']['sha256'] = str_repeat('0', 64),
            'other-run' => $records['windows']['subject']['run_id'] = 41,
            'later-attempt' => $records['windows']['subject']['run_attempt'] = 3,
            'other-pr-head' => $records['windows']['subject']['pull_request_head_sha'] = self::DISPATCH_SHA,
        };
        foreach ($records as $host => $record) {
            if ($mutation === 'missing-windows' && $host === 'windows') {
                continue;
            }
            mkdir($directory . "/native-host-evidence-{$host}");
            file_put_contents(
                $directory . "/native-host-evidence-{$host}/evidence.json",
                $mutation === 'unreadable' && $host === 'linux' ? '{not json' : json_encode($record, JSON_THROW_ON_ERROR),
            );
        }
        if ($mutation === 'extra-host') {
            mkdir($directory . '/native-host-evidence-macos');
        }

        $verified = \nhe_verify_set($directory, ['linux', 'windows'], $contract, ['GITHUB_RUN_ID' => '42', 'GITHUB_RUN_ATTEMPT' => '2'], self::MERGE_SHA);

        if ($expected === '') {
            self::assertSame(\NHE_EXIT_PASS, $verified['exit'], implode("\n", $verified['violations']));
            self::assertSame(['linux' => 'pass', 'windows' => 'pass'], $verified['hosts']);
        } else {
            self::assertSame(\NHE_EXIT_VIOLATION, $verified['exit']);
            self::assertStringContainsString($expected, implode("\n", $verified['violations']));
        }
    }

    #[Test]
    public function the_cli_reports_unusable_arguments_as_a_harness_error(): void
    {
        foreach ([['bogus'], ['collect', '--host=solaris', '--out=x.json'], ['verify-set', '--dir=x']] as $arguments) {
            $process = new Process([PHP_BINARY, self::$root . '/bin/native-host-evidence', ...$arguments], self::$root);
            $process->run();

            self::assertSame(\NHE_EXIT_HARNESS, $process->getExitCode(), implode(' ', $arguments) . ': ' . $process->getErrorOutput());
        }
    }

    /** @return array<string, mixed> */
    private static function fixtureContract(): array
    {
        $architecture = \nhe_normalize_architecture(php_uname('m'));

        return [
            'schema' => \NHE_CONTRACT_SCHEMA,
            'schema_version' => \NHE_SCHEMA_VERSION,
            'harness_shell' => 'pwsh',
            'php_extensions' => ['sqlite3'],
            'hosts' => [
                'linux' => ['runner' => 'ubuntu-24.04', 'runner_os' => 'Linux', 'php_os_family' => PHP_OS_FAMILY === 'Windows' ? 'Linux' : PHP_OS_FAMILY, 'architecture' => $architecture, 'replay_shell' => 'posix_sh'],
                'windows' => ['runner' => 'windows-2025', 'runner_os' => 'Windows', 'php_os_family' => 'Windows', 'architecture' => $architecture, 'replay_shell' => 'powershell'],
            ],
            'runtime' => [
                'php' => ['min' => '8.5.0', 'below' => '8.6.0'],
                'composer' => ['min' => '2.10.0', 'below' => '2.11.0'],
                'sqlite' => ['min' => '3.40.0', 'below' => '4.0.0'],
            ],
            'commands' => [
                ['id' => 'gate', 'kind' => 'gate', 'argv' => ['php', 'bin/check-fixture', '--pattern=/x$/']],
                [
                    'id' => 'tests',
                    'kind' => 'phpunit',
                    'argv' => ['php', 'vendor/bin/phpunit', ...\NHE_STRICT_PHPUNIT_OPTIONS, '--log-junit', 'build/native-host/t.junit.xml', '--log-otr', 'build/native-host/t.otr.xml', 'tests/Fixture'],
                    'expected_methods' => ['Fx\\ATest::first', 'Fx\\ATest::second'],
                ],
            ],
        ];
    }

    #[Test]
    public function composer_is_started_the_way_the_host_resolves_it(): void
    {
        $scratch = $this->scratch();
        mkdir($scratch . '/shim');
        mkdir($scratch . '/binary');
        touch($scratch . '/shim/composer.bat');
        touch($scratch . '/binary/composer.exe');
        // Windows semantics (PATH ';', PATHEXT) on any host; the join uses the
        // host's own separator, so the shims are real files here too.
        $shim = $scratch . '/shim' . DIRECTORY_SEPARATOR . 'composer.bat';
        $binary = $scratch . '/binary' . DIRECTORY_SEPARATOR . 'composer.exe';

        self::assertSame(['composer', '--no-ansi', '--version'], \nhe_composer_command('Linux', []));
        self::assertSame(
            ['cmd.exe', '/d', '/c', $shim, '--no-ansi', '--version'],
            \nhe_composer_command('Windows', ['Path' => "{$scratch}/shim;{$scratch}/binary", 'PATHEXT' => '.COM;.EXE;.BAT;.CMD']),
            'A batch shim is handed to cmd.exe by absolute path, whatever the PATH key case.',
        );
        self::assertSame(
            [$binary, '--no-ansi', '--version'],
            \nhe_composer_command('Windows', ['PATH' => "{$scratch}/binary;{$scratch}/shim", 'PATHEXT' => '.COM;.EXE;.BAT;.CMD']),
        );
        self::assertSame(
            [$binary, '--no-ansi', '--version'],
            \nhe_composer_command('Windows', ['PATH' => "{$scratch}/shim;{$scratch}/binary", 'PATHEXT' => '.COM;.EXE']),
            'PATHEXT decides which shims count.',
        );
        self::assertNull(\nhe_composer_command('Windows', ['PATH' => $scratch]));
        self::assertNull(\nhe_composer_version(null, self::shellModel()));
        self::assertSame('2.10.3', \nhe_composer_version(['composer', '--no-ansi', '--version'], self::shellModel()), 'Colour codes are stripped.');
    }

    /** @return array<string, string> */
    private static function hostEnvironment(string $host, string $path = ''): array
    {
        return [
            'PATH' => $path,
            'PATHEXT' => '.COM;.EXE;.BAT;.CMD',
            'RUNNER_OS' => $host === 'windows' ? 'Windows' : 'Linux',
            'NATIVE_HOST_RUNNER_LABEL' => $host === 'windows' ? 'windows-2025' : 'ubuntu-24.04',
            'RUNNER_ARCH' => 'X64',
            'RUNNER_ENVIRONMENT' => 'github-hosted',
            'ImageOS' => $host === 'windows' ? 'win25' : 'ubuntu24',
            'ImageVersion' => '20260922.1',
            'NATIVE_HOST_SHELL' => 'pwsh',
            'NATIVE_HOST_SHELL_VERSION' => '7.4.6',
            'GITHUB_EVENT_NAME' => 'pull_request',
            'GITHUB_SHA' => self::MERGE_SHA,
            'GITHUB_RUN_ID' => '42',
            'GITHUB_RUN_ATTEMPT' => '1',
            'NATIVE_HOST_PR_HEAD_SHA' => self::PR_HEAD_SHA,
            'NATIVE_HOST_STEP_RESULTS' => "gate success 0\ntests success 0\n",
        ];
    }

    /**
     * A stand-in for the child processes the collector starts: Composer's
     * version, and `pwsh`/`sh` running the argument probe. The shells are
     * modelled by lexing the rendering they receive.
     *
     * @return \Closure(list<string>): ?string
     */
    private static function shellModel(?string $composer = '2.10.3', bool $shellStarts = true, bool $dropLastArgument = false): \Closure
    {
        return static function (array $command) use ($composer, $shellStarts, $dropLastArgument): ?string {
            if (in_array('--version', $command, true)) {
                return $composer === null ? null : "\e[32mComposer\e[39m version \e[33m{$composer}\e[39m 2026-09-01 00:00:00\n";
            }
            if (!$shellStarts) {
                return null;
            }
            $tokens = match ($command[0]) {
                'pwsh' => self::parsePowerShell($command[4]),
                'sh' => self::parsePosix($command[2]),
            };
            $received = array_slice($tokens, 2);
            if ($dropLastArgument) {
                array_pop($received);
            }

            return json_encode($received, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        };
    }

    /**
     * The words of `& '...' '...'`: single-quoted literals in which any
     * PowerShell single quotation mark is written doubled.
     *
     * @return list<string>
     */
    private static function parsePowerShell(string $rendered): array
    {
        self::assertStringStartsWith('& ', $rendered);
        $quote = "['\u{2018}\u{2019}\u{201A}\u{201B}]";
        self::assertSame(1, preg_match("/^& (?:'(?:[^'\u{2018}-\u{201B}]|({$quote})\\1)*'(?: |\$))+\$/u", $rendered), $rendered);
        preg_match_all("/'((?:[^'\u{2018}-\u{201B}]|({$quote})\\2)*)'/u", $rendered, $matches);

        return array_map(
            static fn(string $literal): string => preg_replace("/({$quote})\\1/u", '$1', $literal) ?? $literal,
            $matches[1],
        );
    }

    /** @return list<string> the words of a rendering built from bare words, '...' and \' */
    private static function parsePosix(string $rendered): array
    {
        $tokens = [];
        $token = null;
        $quoted = false;
        $length = strlen($rendered);
        for ($i = 0; $i < $length; $i++) {
            $character = $rendered[$i];
            if ($quoted) {
                $character === "'" ? $quoted = false : $token .= $character;
            } elseif ($character === "'") {
                $quoted = true;
                $token ??= '';
            } elseif ($character === '\\') {
                $token = ($token ?? '') . ($rendered[++$i] ?? '');
            } elseif ($character === ' ') {
                if ($token !== null) {
                    $tokens[] = $token;
                    $token = null;
                }
            } else {
                $token = ($token ?? '') . $character;
            }
        }
        if ($token !== null) {
            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * @param list<array{string, string}> $cases
     *
     * @return array{string, string}
     */
    private function writeLogs(string $directory, string $name, array $cases, int $junitSkipped): array
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }
        $failures = count(array_filter($cases, static fn(array $case): bool => $case[1] === 'FAILED'));
        $testcases = '';
        $events = '<e:started id="1" name="CLI Arguments"/>';
        foreach ($cases as $index => [$method, $status]) {
            $id = $index + 2;
            $testcases .= sprintf('<testcase name="%s" class="Fx\ATest" classname="Fx.ATest" assertions="1"/>', $method);
            $events .= sprintf(
                '<e:started id="%d" parentId="1" name="%s"><sources><phpunit:methodSource className="Fx\ATest" methodName="%s"/></sources></e:started><e:finished id="%d"><result status="%s"/></e:finished>',
                $id,
                $method,
                $method,
                $id,
                $status,
            );
        }
        $junit = $directory . "/{$name}.junit.xml";
        $otr = $directory . "/{$name}.otr.xml";
        file_put_contents($junit, sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><testsuites><testsuite name="CLI Arguments" tests="%d" assertions="%d" errors="0" failures="%d" skipped="%d">%s</testsuite></testsuites>',
            count($cases),
            count($cases),
            $failures,
            $junitSkipped,
            $testcases,
        ));
        file_put_contents($otr, '<?xml version="1.0"?><e:events xmlns="https://schemas.opentest4j.org/reporting/core/0.2.0" xmlns:e="https://schemas.opentest4j.org/reporting/events/0.2.0" xmlns:phpunit="https://schema.phpunit.de/otr/phpunit/0.1.0">' . $events . '<e:finished id="1"><result status="SUCCESSFUL"/></e:finished></e:events>');

        return [$junit, $otr];
    }

    private function scratch(): string
    {
        if ($this->scratch === null) {
            $this->scratch = sys_get_temp_dir() . '/waaseyaa_native_host_evidence_' . bin2hex(random_bytes(6));
            mkdir($this->scratch, 0o777, true);
        }

        return $this->scratch;
    }
}
