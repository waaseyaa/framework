<?php

declare(strict_types=1);

require_once __DIR__ . '/repository-bash.php';

/**
 * Native-host contract evidence (FW-2678-NATIVE-HOST-CONTRACT-01, #2678).
 *
 * The `native-host-contract` CI matrix runs the commands listed in
 * tools/native-host-contract.json, one workflow step each, on ubuntu-24.04
 * and windows-2025. This library does not run, select, skip or reorder any of
 * them. It validates what the hosted steps already did and records it:
 *
 * - `collect` binds one leaf's evidence to its exact checkout, host, runtime,
 *   hosted shell, step outcomes and PHPUnit logs, and fails closed on any
 *   missing identity, unexecuted step, skip, incomplete test or empty
 *   selection;
 * - `verify-set` accepts exactly one passing record per contract host, all
 *   bound to the verifier's own checkout and contract digest.
 *
 * Every child process (Git, Composer, the replay shells) goes through
 * repository_bounded_output(): argv arrays, a deadline, null stdin and a
 * file-backed stdout. Plain functions, no autoloader.
 */

const NHE_CONTRACT_SCHEMA = 'waaseyaa.native_host_contract';
const NHE_EVIDENCE_SCHEMA = 'waaseyaa.native_host_contract_evidence';
const NHE_SCHEMA_VERSION = 1;

const NHE_EXIT_PASS = 0;
const NHE_EXIT_VIOLATION = 1;
const NHE_EXIT_HARNESS = 2;
const NHE_EXIT_INCOMPLETE = 3;

/** PHPUnit options every contract PHPUnit command must carry. */
const NHE_STRICT_PHPUNIT_OPTIONS = [
    '--no-coverage',
    '--do-not-cache-result',
    '--fail-on-empty-test-suite',
    '--fail-on-skipped',
    '--fail-on-incomplete',
];

const NHE_COMMAND_KINDS = ['setup', 'gate', 'phpunit'];
const NHE_PROGRAMS = ['php', 'composer'];

/**
 * Subject profiles: which SHA the checkout must equal for each event.
 * `pull_request` checks out the merge ref (GITHUB_SHA); the pull-request head
 * is recorded but never treated as the tested source.
 */
const NHE_SUBJECT_PROFILES = [
    'pull_request' => 'merge-ref-sha',
    'push' => 'main-sha',
];

/** @return array<string, mixed> */
function nhe_load_contract(string $path): array
{
    $raw = @file_get_contents($path);
    if (!is_string($raw)) {
        throw new RuntimeException("cannot read the native-host contract {$path}");
    }
    try {
        $contract = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException("the native-host contract {$path} is not JSON: {$exception->getMessage()}");
    }
    if (!is_array($contract)) {
        throw new RuntimeException("the native-host contract {$path} is not an object");
    }
    $errors = nhe_validate_contract($contract);
    if ($errors !== []) {
        throw new RuntimeException("the native-host contract {$path} is invalid:\n- " . implode("\n- ", $errors));
    }

    return $contract;
}

/**
 * @param array<mixed> $contract
 *
 * @return list<string>
 */
function nhe_validate_contract(array $contract): array
{
    $errors = [];
    if (($contract['schema'] ?? null) !== NHE_CONTRACT_SCHEMA || ($contract['schema_version'] ?? null) !== NHE_SCHEMA_VERSION) {
        $errors[] = sprintf('schema must be %s version %d', NHE_CONTRACT_SCHEMA, NHE_SCHEMA_VERSION);
    }
    if (($contract['harness_shell'] ?? null) !== 'pwsh') {
        $errors[] = 'harness_shell must be pwsh';
    }

    $hosts = $contract['hosts'] ?? null;
    if (!is_array($hosts) || $hosts === []) {
        $errors[] = 'hosts must be a non-empty object';
        $hosts = [];
    }
    foreach ($hosts as $host => $definition) {
        foreach (['runner', 'runner_os', 'php_os_family', 'architecture', 'replay_shell'] as $field) {
            if (!is_array($definition) || !is_string($definition[$field] ?? null) || $definition[$field] === '') {
                $errors[] = "hosts.{$host}.{$field} must be a non-empty string";
            }
        }
        if (is_array($definition) && !in_array($definition['replay_shell'] ?? null, ['powershell', 'posix_sh'], true)) {
            $errors[] = "hosts.{$host}.replay_shell must be powershell or posix_sh";
        }
    }

    $extensions = $contract['php_extensions'] ?? null;
    if (!is_array($extensions) || $extensions === [] || !array_is_list($extensions)
        || array_filter($extensions, static fn(mixed $extension): bool => !is_string($extension) || preg_match('/^[a-z0-9_]+$/', $extension) !== 1) !== []) {
        $errors[] = 'php_extensions must be a non-empty list of extension names';
    }

    foreach (['php', 'composer', 'sqlite'] as $tool) {
        $range = $contract['runtime'][$tool] ?? null;
        if (!is_array($range)
            || !is_string($range['min'] ?? null) || preg_match('/^\d+\.\d+\.\d+$/', $range['min']) !== 1
            || !is_string($range['below'] ?? null) || preg_match('/^\d+\.\d+\.\d+$/', $range['below']) !== 1
            || version_compare($range['min'], $range['below'], '>=')) {
            $errors[] = "runtime.{$tool} must be {min, below} with min < below";
        }
    }

    $commands = $contract['commands'] ?? null;
    if (!is_array($commands) || $commands === [] || !array_is_list($commands)) {
        $errors[] = 'commands must be a non-empty list';
        $commands = [];
    }
    $ids = [];
    foreach ($commands as $index => $command) {
        $id = is_array($command) ? ($command['id'] ?? null) : null;
        if (!is_string($id) || preg_match('/^[a-z][a-z0-9-]*$/', $id) !== 1) {
            $errors[] = "commands[{$index}].id must be a lowercase step id";
            continue;
        }
        if (isset($ids[$id])) {
            $errors[] = "commands[{$index}].id {$id} is duplicated";
        }
        $ids[$id] = true;
        if (!in_array($command['kind'] ?? null, NHE_COMMAND_KINDS, true)) {
            $errors[] = "{$id}: kind must be one of " . implode(', ', NHE_COMMAND_KINDS);
        }
        $argv = $command['argv'] ?? null;
        if (!is_array($argv) || $argv === [] || !array_is_list($argv)) {
            $errors[] = "{$id}: argv must be a non-empty list";
            continue;
        }
        foreach ($argv as $position => $token) {
            if (!is_string($token) || $token === '' || preg_match('/^[\x20-\x7E]+$/', $token) !== 1 || str_contains($token, '"')) {
                $errors[] = "{$id}: argv[{$position}] must be non-empty printable ASCII without a double quote";
            }
        }
        if (!in_array($argv[0], NHE_PROGRAMS, true)) {
            $errors[] = "{$id}: argv[0] must be one of " . implode(', ', NHE_PROGRAMS);
        }
        if (($command['kind'] ?? null) === 'phpunit') {
            foreach (NHE_STRICT_PHPUNIT_OPTIONS as $option) {
                if (!in_array($option, $argv, true)) {
                    $errors[] = "{$id}: a PHPUnit command must pass {$option}";
                }
            }
            foreach (['--log-junit', '--log-otr'] as $option) {
                if (nhe_option_value($argv, $option) === null) {
                    $errors[] = "{$id}: a PHPUnit command must pass {$option} <path>";
                }
            }
            $expected = $command['expected_methods'] ?? null;
            if (!is_array($expected) || $expected === [] || !array_is_list($expected)) {
                $errors[] = "{$id}: expected_methods must be a non-empty list";
            } else {
                foreach ($expected as $method) {
                    if (!is_string($method) || preg_match('/^[A-Za-z0-9_\\\\]+::\w+$/', $method) !== 1) {
                        $errors[] = "{$id}: expected method " . json_encode($method) . ' must be Class::method';
                    }
                }
                if (count($expected) !== count(array_unique($expected))) {
                    $errors[] = "{$id}: expected_methods contains a duplicate";
                }
            }
        } elseif (array_key_exists('expected_methods', $command)) {
            $errors[] = "{$id}: only PHPUnit commands declare expected_methods";
        }
    }

    return $errors;
}

/**
 * The value following $option in $argv, or null.
 *
 * @param list<string> $argv
 */
function nhe_option_value(array $argv, string $option): ?string
{
    $position = array_search($option, $argv, true);
    if (!is_int($position) || !isset($argv[$position + 1])) {
        return null;
    }

    return $argv[$position + 1];
}

/**
 * SHA-256 of the contract's canonical JSON, so the digest is the same on a
 * CRLF (Windows) and an LF (Linux) checkout of the same file.
 *
 * @param array<mixed> $contract
 */
function nhe_contract_digest(array $contract): string
{
    return hash('sha256', json_encode(nhe_canonicalize($contract), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

function nhe_canonicalize(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (!array_is_list($value)) {
        ksort($value, SORT_STRING);
    }

    return array_map(nhe_canonicalize(...), $value);
}

/**
 * Native PowerShell rendering: bare words where PowerShell passes them
 * through unchanged, single quotes (with '' for a quote) otherwise. The
 * program must be a bare word, or PowerShell would read a string expression.
 *
 * @param non-empty-list<string> $argv
 */
function nhe_render_powershell(array $argv): string
{
    return nhe_render($argv, '~^[A-Za-z0-9_./-]+$~', static fn(string $token): string => "'" . str_replace("'", "''", $token) . "'");
}

/**
 * POSIX sh rendering: bare words where sh passes them through unchanged,
 * single quotes (with '\'' for a quote) otherwise.
 *
 * @param non-empty-list<string> $argv
 */
function nhe_render_posix(array $argv): string
{
    return nhe_render($argv, '~^[A-Za-z0-9_./=:,@%+-]+$~', static fn(string $token): string => "'" . str_replace("'", "'\\''", $token) . "'");
}

/**
 * @param non-empty-list<string> $argv
 * @param callable(string): string $quote
 */
function nhe_render(array $argv, string $bare, callable $quote): string
{
    if (preg_match($bare, $argv[0]) !== 1) {
        throw new InvalidArgumentException("the program {$argv[0]} is not a bare word");
    }
    $rendered = [];
    foreach ($argv as $token) {
        $rendered[] = preg_match($bare, $token) === 1 ? $token : $quote($token);
    }

    return implode(' ', $rendered);
}

/**
 * Parse the explicit `<id> <outcome> <exit code>` lines the workflow passes
 * for the governed steps. Every contract id must appear exactly once, with
 * outcome `success` and exit code 0.
 *
 * @param list<string> $ids contract step ids, in contract order
 *
 * @return array{results: array<string, array{outcome: string, exit_code: int|null}>, violations: list<string>}
 */
function nhe_parse_step_results(string $raw, array $ids): array
{
    $results = [];
    $violations = [];
    foreach (preg_split('/\R/', $raw) ?: [] as $number => $line) {
        if (trim($line) === '') {
            continue;
        }
        $fields = preg_split('/\s+/', trim($line)) ?: [];
        if (count($fields) !== 3) {
            $violations[] = sprintf('step result line %d must be "<id> <outcome> <exit code>": %s', $number + 1, json_encode(trim($line)));
            if (isset($fields[0]) && in_array($fields[0], $ids, true) && !isset($results[$fields[0]])) {
                $results[$fields[0]] = ['outcome' => $fields[1] ?? '', 'exit_code' => null];
            }
            continue;
        }
        [$id, $outcome, $exit] = $fields;
        if (!in_array($id, $ids, true)) {
            $violations[] = "step {$id} is not a governed contract step";
            continue;
        }
        if (isset($results[$id])) {
            $violations[] = "step {$id} is reported more than once";
            continue;
        }
        $exitCode = preg_match('/^-?\d+$/', $exit) === 1 ? (int) $exit : null;
        $results[$id] = ['outcome' => $outcome, 'exit_code' => $exitCode];
        if ($outcome !== 'success') {
            $violations[] = "step {$id} finished {$outcome}";
        }
        if ($exitCode === null) {
            $violations[] = "step {$id} recorded no numeric exit code (" . json_encode($exit) . ')';
        } elseif ($exitCode !== 0) {
            $violations[] = "step {$id} exited {$exitCode}";
        }
    }
    foreach ($ids as $id) {
        if (!isset($results[$id])) {
            $violations[] = "step {$id} has no recorded result";
        }
    }

    return ['results' => $results, 'violations' => $violations];
}

/**
 * The hosted evidence subject for this checkout.
 *
 * @param array<string, string|false> $env
 *
 * @return array{subject: array<string, mixed>, violations: list<string>, incomplete: list<string>}
 */
function nhe_subject(array $env, ?string $checkedOutHead): array
{
    $violations = [];
    $incomplete = [];
    $value = static fn(string $name): ?string => is_string($env[$name] ?? null) && $env[$name] !== '' ? $env[$name] : null;
    $sha = static fn(?string $candidate): bool => is_string($candidate) && preg_match('/^[0-9a-f]{40}$/D', $candidate) === 1;

    $event = $value('GITHUB_EVENT_NAME');
    $githubSha = $value('GITHUB_SHA');
    $runId = $value('GITHUB_RUN_ID');
    $runAttempt = $value('GITHUB_RUN_ATTEMPT');
    $dispatchSha = $value('NATIVE_HOST_DISPATCH_SHA');
    $pullRequestHead = $value('NATIVE_HOST_PR_HEAD_SHA');

    foreach (['GITHUB_EVENT_NAME' => $event, 'GITHUB_SHA' => $githubSha, 'GITHUB_RUN_ID' => $runId, 'GITHUB_RUN_ATTEMPT' => $runAttempt] as $name => $present) {
        if ($present === null) {
            $incomplete[] = "{$name} is not set";
        }
    }
    if (!$sha($checkedOutHead)) {
        $incomplete[] = 'the checked-out HEAD could not be resolved';
    }
    if ($githubSha !== null && !$sha($githubSha)) {
        $violations[] = 'GITHUB_SHA is not a 40-character SHA';
    }
    if ($runId !== null && preg_match('/^\d+$/', $runId) !== 1 || $runAttempt !== null && preg_match('/^[1-9]\d*$/', $runAttempt) !== 1) {
        $violations[] = 'GITHUB_RUN_ID and GITHUB_RUN_ATTEMPT must be positive integers';
    }

    $profile = null;
    $expected = null;
    if ($event === 'workflow_dispatch') {
        if ($dispatchSha !== null) {
            $profile = 'dispatched-sha';
            $expected = $dispatchSha;
            if (!$sha($dispatchSha)) {
                $violations[] = 'the dispatched SHA is not a 40-character SHA';
            }
        } else {
            $profile = 'dispatched-ref';
            $expected = $githubSha;
        }
    } elseif ($event !== null && isset(NHE_SUBJECT_PROFILES[$event])) {
        $profile = NHE_SUBJECT_PROFILES[$event];
        $expected = $githubSha;
    } elseif ($event !== null) {
        $violations[] = "event {$event} has no native-host subject profile";
    }
    if ($event === 'pull_request' && !$sha($pullRequestHead)) {
        $violations[] = 'a pull_request run must record the pull-request head SHA';
    }
    if ($event !== 'pull_request' && $pullRequestHead !== null) {
        $violations[] = "a {$event} run must not record a pull-request head SHA";
    }
    if ($profile !== null && $sha($checkedOutHead) && $expected !== null && $checkedOutHead !== $expected) {
        $violations[] = "the checked-out HEAD {$checkedOutHead} is not the {$profile} subject {$expected}";
    }

    return [
        'subject' => [
            'profile' => $profile,
            'checked_out_head' => $checkedOutHead,
            'github_sha' => $githubSha,
            'dispatch_sha' => $dispatchSha,
            'pull_request_head_sha' => $pullRequestHead,
            'event_name' => $event,
            'run_id' => $runId === null ? null : (int) $runId,
            'run_attempt' => $runAttempt === null ? null : (int) $runAttempt,
        ],
        'violations' => $violations,
        'incomplete' => $incomplete,
    ];
}

function nhe_version_in_range(?string $version, string $min, string $below): bool
{
    return is_string($version)
        && preg_match('/^\d+\.\d+\.\d+/', $version) === 1
        && version_compare($version, $min, '>=')
        && version_compare($version, $below, '<');
}

function nhe_normalize_architecture(string $machine): string
{
    return match (strtolower($machine)) {
        'x86_64', 'amd64', 'x64' => 'x86_64',
        'aarch64', 'arm64' => 'arm64',
        default => strtolower($machine),
    };
}

/**
 * The command that asks this host's `composer` for its version, or null when
 * no Composer is on PATH.
 *
 * POSIX starts `composer` from PATH. A Windows Composer is normally a
 * .bat/.cmd shim, which CreateProcess cannot start from an argument array and
 * which cmd.exe mis-resolves when handed the bare name: PHP quotes the name,
 * and a quoted batch file found through PATH sees `%~dp0` as the working
 * directory, so the shim looks for composer.phar in the checkout. The shim is
 * therefore resolved to an absolute path here (PATH × PATHEXT) and handed to
 * cmd.exe by that path.
 *
 * @param array<string, string|false> $env
 *
 * @return non-empty-list<string>|null
 */
function nhe_composer_command(string $osFamily, array $env): ?array
{
    if ($osFamily !== 'Windows') {
        return ['composer', '--no-ansi', '--version'];
    }
    $lookup = static function (string $name) use ($env): ?string {
        foreach ($env as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0 && is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    };
    $extensions = array_values(array_intersect(
        array_map('strtolower', explode(';', $lookup('PATHEXT') ?? '.COM;.EXE;.BAT;.CMD')),
        ['.com', '.exe', '.bat', '.cmd'],
    ));
    foreach (explode(';', $lookup('PATH') ?? '') as $directory) {
        $directory = trim($directory, " \t\"");
        if ($directory === '') {
            continue;
        }
        foreach ($extensions as $extension) {
            $candidate = rtrim($directory, '\\/') . '\\composer' . $extension;
            if (is_file($candidate)) {
                return in_array($extension, ['.bat', '.cmd'], true)
                    ? ['cmd.exe', '/d', '/c', $candidate, '--no-ansi', '--version']
                    : [$candidate, '--no-ansi', '--version'];
            }
        }
    }

    return null;
}

/**
 * The version Composer reports for $command, or null.
 *
 * @param non-empty-list<string>|null $command
 * @param callable(non-empty-list<string>): ?string $runner
 */
function nhe_composer_version(?array $command, callable $runner): ?string
{
    if ($command === null) {
        return null;
    }
    $output = $runner($command);
    if (!is_string($output)) {
        return null;
    }
    $plain = preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $output) ?? $output;

    return preg_match('/Composer version (\d+\.\d+\.\d+)/', $plain, $match) === 1 ? $match[1] : null;
}

/**
 * Test, assertion and outcome counts from one PHPUnit command's JUnit and
 * Open Test Reporting logs. JUnit folds incomplete tests into `skipped`; OTR
 * reports them as ABORTED, so the two logs are read together and must agree.
 *
 * @return array{counts: array<string, int>, methods: list<string>, violations: list<string>}
 */
function nhe_phpunit_results(string $junitPath, string $otrPath): array
{
    $counts = ['tests' => 0, 'assertions' => 0, 'errors' => 0, 'failures' => 0, 'skipped' => 0, 'incomplete' => 0];
    $methods = [];
    $violations = [];

    $junit = nhe_xml($junitPath);
    if ($junit === null) {
        return ['counts' => $counts, 'methods' => [], 'violations' => ["the JUnit log {$junitPath} is missing or unreadable"]];
    }
    $root = null;
    foreach ($junit->documentElement?->childNodes ?? [] as $node) {
        if ($node instanceof DOMElement && $node->tagName === 'testsuite') {
            $root = $node;
            break;
        }
    }
    if ($root === null) {
        $violations[] = "the JUnit log {$junitPath} has no test suite";
    } else {
        foreach (['tests', 'assertions', 'errors', 'failures', 'skipped'] as $field) {
            $counts[$field] = (int) $root->getAttribute($field);
        }
    }
    foreach ($junit->getElementsByTagName('testcase') as $case) {
        $name = preg_replace('/ with data set .*$|#\d+$/s', '', $case->getAttribute('name')) ?? '';
        $methods[] = $case->getAttribute('class') . '::' . $name;
    }

    $otr = nhe_xml($otrPath);
    if ($otr === null) {
        $violations[] = "the OTR log {$otrPath} is missing or unreadable";
    } else {
        $tests = [];
        foreach ($otr->getElementsByTagNameNS('https://schemas.opentest4j.org/reporting/events/0.2.0', 'started') as $started) {
            if ($started->getElementsByTagNameNS('https://schema.phpunit.de/otr/phpunit/0.1.0', 'methodSource')->length > 0) {
                $tests[$started->getAttribute('id')] = true;
            }
        }
        $otrSkipped = 0;
        $otrAborted = 0;
        foreach ($otr->getElementsByTagNameNS('https://schemas.opentest4j.org/reporting/events/0.2.0', 'finished') as $finished) {
            if (!isset($tests[$finished->getAttribute('id')])) {
                continue;
            }
            foreach ($finished->getElementsByTagNameNS('https://schemas.opentest4j.org/reporting/core/0.2.0', 'result') as $result) {
                $status = $result->getAttribute('status');
                $otrSkipped += $status === 'SKIPPED' ? 1 : 0;
                $otrAborted += $status === 'ABORTED' ? 1 : 0;
            }
        }
        $counts['incomplete'] = $otrAborted;
        if ($counts['skipped'] !== $otrSkipped + $otrAborted) {
            $violations[] = sprintf('JUnit reports %d skipped or incomplete tests but OTR reports %d skipped and %d incomplete', $counts['skipped'], $otrSkipped, $otrAborted);
        }
        $counts['skipped'] = $otrSkipped;
    }

    return ['counts' => $counts, 'methods' => array_values(array_unique($methods)), 'violations' => $violations];
}

function nhe_xml(string $path): ?DOMDocument
{
    if (!is_file($path)) {
        return null;
    }
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try {
        $loaded = $document->load($path, LIBXML_NONET);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }

    return $loaded ? $document : null;
}

/**
 * @param array<string, int> $counts
 * @param list<string> $methods
 * @param list<string> $expectedMethods
 *
 * @return list<string>
 */
function nhe_phpunit_violations(string $id, array $counts, array $methods, array $expectedMethods): array
{
    $violations = [];
    if ($counts['tests'] === 0) {
        $violations[] = "{$id} executed no tests";
    }
    foreach (['errors', 'failures', 'skipped', 'incomplete'] as $field) {
        if ($counts[$field] !== 0) {
            $violations[] = "{$id} reported {$counts[$field]} {$field}";
        }
    }
    foreach (array_diff($expectedMethods, $methods) as $missing) {
        $violations[] = "{$id} did not execute the expected method {$missing}";
    }
    foreach (array_diff($methods, $expectedMethods) as $unexpected) {
        $violations[] = "{$id} executed {$unexpected}, which the contract does not list";
    }

    return $violations;
}

/**
 * Round-trip one rendering through a real shell: the shell starts a PHP probe
 * in place of the program, and the probe echoes the arguments it received.
 * Returns null on an exact match, otherwise a reason; $started reports whether
 * the shell produced any answer at all.
 *
 * @param non-empty-list<string> $argv
 * @param callable(non-empty-list<string>): ?string $runner
 */
function nhe_round_trip(array $argv, string $shell, string $probeScript, callable $runner, bool &$started): ?string
{
    $probe = ['php', $probeScript, ...array_slice($argv, 1)];
    $command = match ($shell) {
        'pwsh' => ['pwsh', '-NoProfile', '-NonInteractive', '-Command', nhe_render_powershell($probe)],
        'sh' => ['sh', '-c', nhe_render_posix($probe)],
        default => throw new InvalidArgumentException("unknown replay shell {$shell}"),
    };
    $output = $runner($command);
    $started = is_string($output);
    if (!$started) {
        return "{$shell} could not run the rendering";
    }
    $received = json_decode(trim($output), true);
    if ($received !== array_slice($argv, 1)) {
        return "{$shell} delivered " . json_encode($received, JSON_UNESCAPED_SLASHES) . ' instead of the contract arguments';
    }

    return null;
}

/**
 * Collect and validate one leaf's evidence.
 *
 * @param array<string, mixed> $contract
 * @param array<string, string|false> $env
 * @param callable(non-empty-list<string>): ?string $runner
 *
 * @return array{evidence: array<string, mixed>, exit: int}
 */
function nhe_collect(array $contract, string $host, string $root, array $env, callable $runner, ?string $checkedOutHead): array
{
    $violations = [];
    $incomplete = [];
    $definition = $contract['hosts'][$host] ?? null;
    if (!is_array($definition)) {
        throw new InvalidArgumentException("host {$host} is not in the contract");
    }
    $value = static fn(string $name): ?string => is_string($env[$name] ?? null) && $env[$name] !== '' ? $env[$name] : null;

    // Host and runner identity.
    $runnerOs = $value('RUNNER_OS');
    $runnerLabel = $value('NATIVE_HOST_RUNNER_LABEL');
    $architecture = nhe_normalize_architecture(php_uname('m'));
    foreach (['RUNNER_OS' => $runnerOs, 'NATIVE_HOST_RUNNER_LABEL' => $runnerLabel, 'ImageOS' => $value('ImageOS'), 'ImageVersion' => $value('ImageVersion')] as $name => $present) {
        if ($present === null) {
            $incomplete[] = "{$name} is not set";
        }
    }
    if (PHP_OS_FAMILY !== $definition['php_os_family']) {
        $violations[] = "the {$host} leaf ran on PHP OS family " . PHP_OS_FAMILY;
    }
    if ($runnerOs !== null && $runnerOs !== $definition['runner_os']) {
        $violations[] = "the {$host} leaf ran on RUNNER_OS {$runnerOs}";
    }
    if ($runnerLabel !== null && $runnerLabel !== $definition['runner']) {
        $violations[] = "the {$host} leaf ran on runner {$runnerLabel}, not {$definition['runner']}";
    }
    if ($architecture !== $definition['architecture']) {
        $violations[] = "the {$host} leaf ran on architecture {$architecture}, not {$definition['architecture']}";
    }

    // Hosted harness shell: CI machinery, never a contributor prerequisite.
    $shell = $value('NATIVE_HOST_SHELL');
    $shellVersion = $value('NATIVE_HOST_SHELL_VERSION');
    if ($shell !== $contract['harness_shell']) {
        $violations[] = 'the hosted shell is ' . json_encode($shell) . ", not {$contract['harness_shell']}";
    }
    if ($shellVersion === null || preg_match('/^\d+\.\d+/', $shellVersion) !== 1) {
        $incomplete[] = 'the hosted shell version was not recorded';
    }

    // Source subject.
    $subject = nhe_subject($env, $checkedOutHead);
    array_push($violations, ...$subject['violations']);
    array_push($incomplete, ...$subject['incomplete']);

    // Runtime.
    $sqlite = class_exists(SQLite3::class) ? (SQLite3::version()['versionString'] ?? null) : null;
    $runtime = [
        'php' => PHP_VERSION,
        'composer' => nhe_composer_version(nhe_composer_command(PHP_OS_FAMILY, $env), $runner),
        'sqlite' => is_string($sqlite) ? $sqlite : null,
        'node' => null,
        'node_required' => false,
    ];
    foreach (['php', 'composer', 'sqlite'] as $tool) {
        $range = $contract['runtime'][$tool];
        if ($runtime[$tool] === null) {
            $incomplete[] = "the {$tool} version could not be resolved";
        } elseif (!nhe_version_in_range($runtime[$tool], $range['min'], $range['below'])) {
            $violations[] = "{$tool} {$runtime[$tool]} is outside >={$range['min']} <{$range['below']}";
        }
    }

    // Governed step results.
    $ids = array_column($contract['commands'], 'id');
    $steps = nhe_parse_step_results((string) ($env['NATIVE_HOST_STEP_RESULTS'] ?? ''), $ids);
    array_push($violations, ...$steps['violations']);

    // PHPUnit logs and replay renderings.
    $probeScript = tempnam(sys_get_temp_dir(), 'nhe');
    if ($probeScript === false || file_put_contents($probeScript, '<?php echo json_encode(array_slice($argv, 1), JSON_UNESCAPED_SLASHES);') === false) {
        throw new RuntimeException('cannot write the argument probe');
    }
    $commands = [];
    try {
        foreach ($contract['commands'] as $command) {
            $id = $command['id'];
            $record = [
                'id' => $id,
                'kind' => $command['kind'],
                'argv' => $command['argv'],
                'outcome' => $steps['results'][$id]['outcome'] ?? null,
                'exit_code' => $steps['results'][$id]['exit_code'] ?? null,
            ];
            $record[$definition['replay_shell']] = $definition['replay_shell'] === 'powershell'
                ? nhe_render_powershell($command['argv'])
                : nhe_render_posix($command['argv']);

            $shells = ['pwsh'];
            if ($definition['replay_shell'] === 'posix_sh') {
                $shells[] = 'sh';
            }
            foreach ($shells as $replayShell) {
                $started = false;
                $problem = nhe_round_trip($command['argv'], $replayShell, $probeScript, $runner, $started);
                $record['round_trip'][$replayShell] = $problem === null ? 'verified' : $problem;
                if ($problem !== null) {
                    if ($started) {
                        $violations[] = "{$id}: {$problem}";
                    } else {
                        $incomplete[] = "{$id}: {$problem}";
                    }
                }
            }

            if ($command['kind'] === 'phpunit') {
                $junit = $root . '/' . nhe_option_value($command['argv'], '--log-junit');
                $otr = $root . '/' . nhe_option_value($command['argv'], '--log-otr');
                $results = nhe_phpunit_results($junit, $otr);
                $record['phpunit'] = $results['counts'] + ['methods' => count($results['methods'])];
                foreach ($results['violations'] as $problem) {
                    $violations[] = "{$id}: {$problem}";
                }
                array_push($violations, ...nhe_phpunit_violations($id, $results['counts'], $results['methods'], $command['expected_methods']));
            }
            $commands[] = $record;
        }
    } finally {
        @unlink($probeScript);
    }

    $result = $violations !== [] ? 'fail' : ($incomplete !== [] ? 'incomplete' : 'pass');
    $evidence = [
        'schema' => NHE_EVIDENCE_SCHEMA,
        'schema_version' => NHE_SCHEMA_VERSION,
        'result' => $result,
        'host' => $host,
        'contract' => ['path' => 'tools/native-host-contract.json', 'sha256' => nhe_contract_digest($contract)],
        'subject' => $subject['subject'],
        'runner' => [
            'label' => $runnerLabel,
            'runner_os' => $runnerOs,
            'runner_arch' => $value('RUNNER_ARCH'),
            'runner_environment' => $value('RUNNER_ENVIRONMENT'),
            'image_os' => $value('ImageOS'),
            'image_version' => $value('ImageVersion'),
            'os_family' => PHP_OS_FAMILY,
            'os' => php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('v'),
            'architecture' => $architecture,
        ],
        'hosted_shell' => ['name' => $shell, 'version' => $shellVersion, 'role' => 'hosted-harness-only'],
        'runtime' => $runtime,
        'replay' => [
            'shell' => $definition['replay_shell'],
            'preconditions' => [
                'checkout' => $checkedOutHead,
                'php' => sprintf('>=%s <%s with extensions %s', $contract['runtime']['php']['min'], $contract['runtime']['php']['below'], implode(', ', $contract['php_extensions'])),
                'composer' => sprintf('>=%s <%s', $contract['runtime']['composer']['min'], $contract['runtime']['composer']['below']),
            ],
        ],
        'commands' => $commands,
        'violations' => $violations,
        'incomplete' => $incomplete,
    ];

    return [
        'evidence' => $evidence,
        'exit' => $violations !== [] ? NHE_EXIT_VIOLATION : ($incomplete !== [] ? NHE_EXIT_INCOMPLETE : NHE_EXIT_PASS),
    ];
}

/**
 * Accept exactly one passing record per contract host, each bound to the
 * verifier's own checkout, contract digest and workflow run.
 *
 * @param array<string, mixed> $contract
 * @param list<string> $hosts
 * @param array<string, string|false> $env
 *
 * @return array{hosts: array<string, string>, violations: list<string>, exit: int}
 */
function nhe_verify_set(string $directory, array $hosts, array $contract, array $env, ?string $verifierHead): array
{
    $violations = [];
    $digest = nhe_contract_digest($contract);
    if (array_diff($hosts, array_keys($contract['hosts'])) !== [] || array_diff(array_keys($contract['hosts']), $hosts) !== []) {
        $violations[] = 'the verified hosts must be exactly the contract hosts: ' . implode(', ', array_keys($contract['hosts']));
    }
    if (!is_string($verifierHead) || preg_match('/^[0-9a-f]{40}$/D', $verifierHead) !== 1) {
        $violations[] = "the verifier's checked-out HEAD could not be resolved";
    }
    $runId = is_string($env['GITHUB_RUN_ID'] ?? null) ? (int) $env['GITHUB_RUN_ID'] : null;
    $runAttempt = is_string($env['GITHUB_RUN_ATTEMPT'] ?? null) ? (int) $env['GITHUB_RUN_ATTEMPT'] : null;
    if ($runId === null || $runAttempt === null) {
        $violations[] = 'GITHUB_RUN_ID and GITHUB_RUN_ATTEMPT must be set for the verifier';
    }

    $present = [];
    foreach (glob($directory . '/native-host-evidence-*', GLOB_ONLYDIR) ?: [] as $artifact) {
        $present[substr(basename($artifact), strlen('native-host-evidence-'))] = $artifact;
    }
    foreach (array_diff(array_keys($present), $hosts) as $extra) {
        $violations[] = "unexpected evidence artifact native-host-evidence-{$extra}";
    }

    $records = [];
    $summary = [];
    foreach ($hosts as $host) {
        if (!isset($present[$host])) {
            $violations[] = "the {$host} evidence artifact is missing";
            $summary[$host] = 'missing';
            continue;
        }
        $raw = @file_get_contents($present[$host] . '/evidence.json');
        $record = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($record)) {
            $violations[] = "the {$host} evidence record is missing or not JSON";
            $summary[$host] = 'unreadable';
            continue;
        }
        $records[$host] = $record;
        $summary[$host] = (string) ($record['result'] ?? 'unknown');
        $subject = is_array($record['subject'] ?? null) ? $record['subject'] : [];
        $checks = [
            'schema' => ($record['schema'] ?? null) === NHE_EVIDENCE_SCHEMA && ($record['schema_version'] ?? null) === NHE_SCHEMA_VERSION,
            'host' => ($record['host'] ?? null) === $host,
            'result pass' => ($record['result'] ?? null) === 'pass',
            'contract digest' => ($record['contract']['sha256'] ?? null) === $digest,
            'checked-out HEAD' => ($subject['checked_out_head'] ?? null) === $verifierHead,
            'run id' => ($subject['run_id'] ?? null) === $runId,
            'run attempt' => is_int($subject['run_attempt'] ?? null) && $runAttempt !== null && $subject['run_attempt'] <= $runAttempt,
        ];
        foreach ($checks as $check => $passed) {
            if (!$passed) {
                $violations[] = "the {$host} evidence record fails the {$check} check";
            }
        }
    }

    // The leaves of one run share one subject; only the attempt may differ.
    $shared = ['profile', 'event_name', 'github_sha', 'dispatch_sha', 'pull_request_head_sha'];
    $first = null;
    foreach ($records as $host => $record) {
        $projection = array_intersect_key(is_array($record['subject'] ?? null) ? $record['subject'] : [], array_flip($shared));
        ksort($projection);
        if ($first === null) {
            $first = [$host, $projection];
        } elseif ($projection !== $first[1]) {
            $violations[] = "the {$host} and {$first[0]} evidence records bind different subjects";
        }
    }

    return ['hosts' => $summary, 'violations' => $violations, 'exit' => $violations === [] ? NHE_EXIT_PASS : NHE_EXIT_VIOLATION];
}
