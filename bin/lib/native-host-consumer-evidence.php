<?php

declare(strict_types=1);

require_once __DIR__ . '/native-host-evidence.php';

/**
 * Native-host consumer CLI evidence (FW-2678-NATIVE-HOST-SKELETON-CLI-02,
 * #2678).
 *
 * Two existing consumer lanes build a fresh project from the candidate and
 * complete site:init and install:init: `site-reference-consumer` on
 * ubuntu-24.04 and `ci/skeleton-create-project-windows` on windows-2025. Each
 * then runs the `consumer_cli` command of tools/native-host-contract.json in
 * that consumer. This library does not run it. It validates what the lanes
 * did and records it:
 *
 * - `nhc_collect()` binds one lane's record to its checkout, the candidate
 *   revision the consumer was built from, the installed `waaseyaa/*` cohort,
 *   the completed lifecycle, the CLI exit code and catalogue, and the runner,
 *   shell and runtime identity;
 * - `nhc_verify_set()` accepts exactly one passing Linux and one passing
 *   Windows record from the same run with the same subject and an equivalent
 *   cohort.
 *
 * The shared subject is the originating checked-out candidate, never a
 * scratch commit. Linux archives the checkout it runs in (the harness's
 * candidate_revision) and recommits only the skeleton as a scratch project,
 * so its consumer root-package reference is that scratch commit; the record
 * proves the scratch commit's tree is the candidate's `skeleton/` tree and
 * never claims the two references are equal.
 *
 * Composer path-repository references hash a package's manifest and the
 * repository options, not its code, so they cannot identify the installed
 * content. The cohort is identified instead by content: every installed file
 * must equal the candidate revision's blob at the same path, and each
 * package's digest covers the candidate paths and blobs it installed. Files
 * the candidate's export-ignore policy withholds from both the archive and
 * Composer's mirror are recorded as withheld.
 */

const NHC_EVIDENCE_SCHEMA = 'waaseyaa.native_host_consumer_cli_evidence';
const NHC_ARTIFACT_PREFIX = 'native-host-consumer-evidence-';
const NHC_STEP_IDS = ['lifecycle', 'consumer-cli'];
const NHC_CANDIDATE_BINDINGS = ['harness-archive', 'checkout'];
const NHC_BOOT_SOURCES = ['process', 'consumer-dotenv'];

/** Scratch-commit relation recorded for a harness-archive (Linux) lane. */
const NHC_SCRATCH_RELATION = 'scratch-commit-of-candidate-skeleton';

/** Git's 40-hex object name. */
const NHC_SHA_PATTERN = '/^[0-9a-f]{40}$/D';

/**
 * Consumer-section problems of an otherwise valid native-host contract.
 *
 * @param array<mixed> $contract
 *
 * @return list<string>
 */
function nhc_validate_contract(array $contract): array
{
    $section = $contract['consumer_cli'] ?? null;
    if (!is_array($section)) {
        return ['consumer_cli must be an object'];
    }
    $errors = [];

    $argv = $section['argv'] ?? null;
    if (!is_array($argv) || $argv === [] || !array_is_list($argv)) {
        $errors[] = 'consumer_cli.argv must be a non-empty list';
    } else {
        foreach ($argv as $position => $token) {
            if (!is_string($token) || $token === '' || $token === '--%' || preg_match('/^[\x20-\x7E]+$/', $token) !== 1 || str_contains($token, '"')) {
                $errors[] = "consumer_cli.argv[{$position}] must be non-empty printable ASCII without a double quote or the stop-parsing token";
            }
        }
        if ($argv[0] !== 'php') {
            $errors[] = 'consumer_cli.argv[0] must be php';
        }
    }

    $required = $section['required_commands'] ?? null;
    if (!is_array($required) || $required === [] || !array_is_list($required)
        || array_filter($required, static fn(mixed $name): bool => !is_string($name) || preg_match('/^[a-z][a-z0-9:_-]*$/D', $name) !== 1) !== []) {
        $errors[] = 'consumer_cli.required_commands must be a non-empty list of command names';
    } elseif (count($required) !== count(array_unique($required))) {
        $errors[] = 'consumer_cli.required_commands contains a duplicate';
    }

    $artifacts = $section['lifecycle_artifacts'] ?? null;
    $relative = static fn(mixed $path): bool => is_string($path) && array_filter(
        explode('/', $path),
        static fn(string $segment): bool => $segment === '.' || $segment === '..' || preg_match('/^[A-Za-z0-9._-]+$/D', $segment) !== 1,
    ) === [];
    if (!is_array($artifacts) || $artifacts === [] || !array_is_list($artifacts)
        || array_filter($artifacts, static fn(mixed $path): bool => !$relative($path)) !== []) {
        $errors[] = 'consumer_cli.lifecycle_artifacts must be a non-empty list of relative paths';
    }

    $lanes = $section['lanes'] ?? null;
    $hosts = is_array($contract['hosts'] ?? null) ? array_keys($contract['hosts']) : [];
    if (!is_array($lanes) || array_is_list($lanes)) {
        $errors[] = 'consumer_cli.lanes must be an object';
        $lanes = [];
    } else {
        $laneHosts = array_keys($lanes);
        sort($laneHosts);
        sort($hosts);
        if ($laneHosts !== $hosts) {
            $errors[] = 'consumer_cli.lanes must name exactly the contract hosts';
        }
    }
    foreach ($lanes as $host => $lane) {
        if (!is_array($lane) || !is_string($lane['job'] ?? null) || preg_match('/^[a-z0-9-]+$/D', $lane['job']) !== 1) {
            $errors[] = "consumer_cli.lanes.{$host}.job must be a workflow job key";
        }
        if (!is_array($lane) || !in_array($lane['candidate_binding'] ?? null, NHC_CANDIDATE_BINDINGS, true)) {
            $errors[] = "consumer_cli.lanes.{$host}.candidate_binding must be one of " . implode(', ', NHC_CANDIDATE_BINDINGS);
        }
        $boot = is_array($lane) ? ($lane['boot_environment'] ?? null) : null;
        if (!is_array($boot) || !is_string($boot['app_env'] ?? null) || preg_match('/^[a-z]+$/D', $boot['app_env']) !== 1
            || !in_array($boot['source'] ?? null, NHC_BOOT_SOURCES, true)) {
            $errors[] = "consumer_cli.lanes.{$host}.boot_environment must be {app_env, source}";
        }
    }

    return $errors;
}

/** @return array<string, mixed> */
function nhc_load_contract(string $path): array
{
    $contract = nhe_load_contract($path);
    $errors = nhc_validate_contract($contract);
    if ($errors !== []) {
        throw new RuntimeException("the native-host contract {$path} has an invalid consumer_cli section:\n- " . implode("\n- ", $errors));
    }

    return $contract;
}

/**
 * The command names a `list --raw` output lists, in output order: the first
 * word of every line. A byte-order mark, carriage returns and colour codes
 * are ignored. PowerShell 7.4+ redirects a native command's bytes unchanged;
 * Windows PowerShell 5.1, the local replay shell where pwsh is absent, writes
 * UTF-16LE with a byte-order mark, which is decoded first.
 *
 * @return list<string>
 */
function nhc_catalogue(string $raw): array
{
    if (str_starts_with($raw, "\xFF\xFE")) {
        $decoded = @iconv('UTF-16LE', 'UTF-8', substr($raw, 2));
        $raw = is_string($decoded) ? $decoded : '';
    }
    $raw = preg_replace(['/^\xEF\xBB\xBF/', '/\e\[[0-9;]*[A-Za-z]/'], '', $raw) ?? $raw;
    $names = [];
    foreach (preg_split('/\R/', $raw) ?: [] as $line) {
        if (preg_match('/^([A-Za-z0-9][A-Za-z0-9:._-]*)(?:\s|$)/', $line, $match) === 1) {
            $names[] = $match[1];
        }
    }

    return $names;
}

/** Git's blob object name for $bytes. */
function nhc_blob_sha(string $bytes): string
{
    return sha1('blob ' . strlen($bytes) . "\0" . $bytes);
}

/**
 * Parse `git ls-tree -r -z --full-tree <rev>`: path => blob object name.
 * Only blob entries are returned; null when the listing is malformed.
 *
 * @return array<string, string>|null
 */
function nhc_parse_tree(string $raw): ?array
{
    $tree = [];
    foreach (explode("\0", $raw) as $entry) {
        if ($entry === '') {
            continue;
        }
        if (preg_match('/^(\d{6}) (blob|tree|commit) ([0-9a-f]{40})\t(.+)$/sD', $entry, $match) !== 1) {
            return null;
        }
        if ($match[2] === 'blob') {
            $tree[$match[4]] = $match[3];
        }
    }

    return $tree;
}

/**
 * Deterministic digest of one package's installed candidate files: SHA-256
 * over the sorted `<path>\0<blob>` lines, with the candidate tree's paths
 * (relative to the package) and blob names. It is computed only from files
 * already proven equal to the candidate, so every host that installed the
 * same candidate files computes the same digest.
 *
 * @param array<string, string> $files package-relative candidate path => blob
 */
function nhc_package_digest(array $files): string
{
    ksort($files, SORT_STRING);
    $lines = '';
    foreach ($files as $path => $blob) {
        $lines .= $path . "\0" . $blob . "\n";
    }

    return hash('sha256', $lines);
}

/**
 * Compare one installed package directory with the candidate files under its
 * prefix. Every installed file must be a candidate file with the candidate's
 * content; a text file whose only difference is CRLF line endings (a Windows
 * checkout of an LF blob) matches after normalization and is counted.
 *
 * Candidate files Composer did not install are withheld, not missing: the
 * candidate's export-ignore policy withholds them from both the Linux archive
 * and Composer's path mirror. They are recorded, and the digest covers only
 * what was installed, so the two lanes agree only when they installed the same
 * files. On a case-insensitive filesystem two candidate directories that
 * differ only in case share one directory, so paths are matched without case.
 *
 * @param array<string, string> $tree candidate path => blob
 *
 * @return array{files: int, withheld: list<string>, digest: string, eol_normalized: int, problems: list<string>}
 */
function nhc_compare_package(string $installedDirectory, string $prefix, array $tree, bool $caseInsensitive): array
{
    $candidate = [];
    foreach ($tree as $path => $blob) {
        if ($prefix === '' || str_starts_with($path, $prefix)) {
            $candidate[substr($path, strlen($prefix))] = $blob;
        }
    }
    $key = static fn(string $path): string => $caseInsensitive ? strtolower($path) : $path;
    $lookup = [];
    $ambiguous = [];
    foreach (array_keys($candidate) as $relative) {
        if (isset($lookup[$key($relative)])) {
            $ambiguous[$key($relative)] = true;
        }
        $lookup[$key($relative)] = $relative;
    }

    $installed = [];
    if (is_dir($installedDirectory)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($installedDirectory, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO),
        );
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && ($file->isFile() || $file->isLink())) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($installedDirectory) + 1));
                $installed[$relative] = $file->getPathname();
            }
        }
    }

    $verified = [];
    $unexpected = [];
    $changed = [];
    $normalized = 0;
    foreach ($installed as $relative => $absolute) {
        $path = $lookup[$key($relative)] ?? null;
        if ($path === null) {
            $unexpected[] = $relative;
            continue;
        }
        $bytes = is_link($absolute) || isset($ambiguous[$key($relative)]) ? null : @file_get_contents($absolute);
        if (is_string($bytes) && nhc_blob_sha($bytes) === $candidate[$path]) {
            $verified[$path] = $candidate[$path];
        } elseif (is_string($bytes) && str_contains($bytes, "\r\n") && nhc_blob_sha(str_replace("\r\n", "\n", $bytes)) === $candidate[$path]) {
            $verified[$path] = $candidate[$path];
            $normalized++;
        } else {
            $changed[] = $relative;
        }
    }
    $attempted = array_flip(array_map(static fn(string $relative): string => $lookup[$key($relative)], $changed));
    $withheld = array_keys(array_diff_key($candidate, $verified, $attempted));
    sort($withheld, SORT_STRING);

    $problems = [];
    if (!isset($verified['composer.json'])) {
        $problems[] = 'its composer.json is not the installed candidate manifest';
    }
    foreach (['is not a candidate file' => $unexpected, 'differs from the candidate' => $changed] as $problem => $paths) {
        if ($paths !== []) {
            sort($paths, SORT_STRING);
            $shown = array_slice($paths, 0, 5);
            $problems[] = sprintf(
                '%d installed file(s) %s: %s%s',
                count($paths),
                $problem,
                implode(', ', $shown),
                count($paths) > count($shown) ? ', ...' : '',
            );
        }
    }

    return ['files' => count($verified), 'withheld' => $withheld, 'digest' => nhc_package_digest($verified), 'eol_normalized' => $normalized, 'problems' => $problems];
}

/**
 * The installed `waaseyaa/*` cohort, each package bound to its candidate
 * source by content. A metapackage installs no files; its identity is the
 * candidate manifest.
 *
 * @param array<string, string> $tree candidate path => blob
 * @param callable(string): ?string $readCandidate reads a candidate file by path
 *
 * @return array{cohort: array<string, mixed>, violations: list<string>, incomplete: list<string>}
 */
function nhc_cohort(string $consumerRoot, array $tree, callable $readCandidate, bool $caseInsensitive): array
{
    $violations = [];
    $incomplete = [];
    $comparison = $caseInsensitive ? 'case-insensitive' : 'exact';
    $empty = ['digest' => null, 'package_count' => 0, 'path_comparison' => $comparison, 'packages' => []];

    // Candidate package names: the root manifest and every packages/<dir>/.
    $sources = [];
    foreach (array_keys($tree) as $path) {
        if ($path !== 'composer.json' && preg_match('#^packages/[^/]+/composer\.json$#D', $path) !== 1) {
            continue;
        }
        $manifest = $readCandidate($path);
        $bytes = is_string($manifest) ? $manifest : '';
        if (!is_string($manifest) || (nhc_blob_sha($bytes) !== $tree[$path] && nhc_blob_sha(str_replace("\r\n", "\n", $bytes)) !== $tree[$path])) {
            $violations[] = "the checkout's {$path} is not the candidate revision's";
            continue;
        }
        $name = json_decode($bytes, true)['name'] ?? null;
        if (is_string($name) && str_starts_with($name, 'waaseyaa/')) {
            $sources[$name] = substr($path, 0, -strlen('composer.json'));
        }
    }

    $raw = @file_get_contents($consumerRoot . '/vendor/composer/installed.json');
    $installed = is_string($raw) ? json_decode($raw, true) : null;
    $packages = is_array($installed) ? ($installed['packages'] ?? null) : null;
    if (!is_array($packages)) {
        $incomplete[] = 'the consumer has no readable vendor/composer/installed.json';

        return ['cohort' => $empty, 'violations' => $violations, 'incomplete' => $incomplete];
    }

    $records = [];
    foreach ($packages as $package) {
        $name = is_array($package) ? ($package['name'] ?? null) : null;
        if (!is_string($name) || !str_starts_with($name, 'waaseyaa/')) {
            continue;
        }
        $record = [
            'name' => $name,
            'version' => is_string($package['version'] ?? null) ? $package['version'] : null,
            'type' => is_string($package['type'] ?? null) ? $package['type'] : null,
            'dist_type' => is_string($package['dist']['type'] ?? null) ? $package['dist']['type'] : null,
            'dist_reference' => is_string($package['dist']['reference'] ?? null) ? $package['dist']['reference'] : null,
            'candidate_path' => null,
            'files' => 0,
            'withheld' => [],
            'content_digest' => null,
            'eol_normalized' => 0,
        ];
        if (!isset($sources[$name])) {
            $violations[] = "the consumer installed {$name}, which is not a candidate package";
            $records[$name] = $record;
            continue;
        }
        $record['candidate_path'] = $sources[$name] === '' ? '.' : rtrim($sources[$name], '/');
        $installPath = $package['install-path'] ?? null;
        if ($record['type'] === 'metapackage' && $installPath === null) {
            $record['content_digest'] = nhc_package_digest(['composer.json' => $tree[$sources[$name] . 'composer.json']]);
            $records[$name] = $record;
            continue;
        }
        $directory = is_string($installPath) && $installPath !== '' ? realpath($consumerRoot . '/vendor/composer/' . $installPath) : false;
        if ($directory === false || !is_dir($directory)) {
            $violations[] = "the consumer's {$name} install path is missing";
            $records[$name] = $record;
            continue;
        }
        $compared = nhc_compare_package($directory, $sources[$name], $tree, $caseInsensitive);
        $record['files'] = $compared['files'];
        $record['withheld'] = $compared['withheld'];
        $record['content_digest'] = $compared['digest'];
        $record['eol_normalized'] = $compared['eol_normalized'];
        foreach ($compared['problems'] as $problem) {
            $violations[] = "{$name}: {$problem}";
        }
        $records[$name] = $record;
    }
    ksort($records, SORT_STRING);
    if (!isset($records['waaseyaa/framework'])) {
        $violations[] = 'the consumer did not install waaseyaa/framework';
    }

    $identity = array_map(
        static fn(array $record): array => [$record['name'], $record['version'], $record['content_digest']],
        array_values($records),
    );

    return [
        'cohort' => [
            'digest' => hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'package_count' => count($records),
            'path_comparison' => $comparison,
            'packages' => array_values($records),
        ],
        'violations' => $violations,
        'incomplete' => $incomplete,
    ];
}

/**
 * The consumer's root package, from Composer's generated installed.php.
 *
 * @return array{name: ?string, pretty_version: ?string, reference: ?string}|null
 */
function nhc_root_package(string $consumerRoot): ?array
{
    $path = $consumerRoot . '/vendor/composer/installed.php';
    if (!is_file($path)) {
        return null;
    }
    try {
        $data = (static fn(string $file): mixed => require $file)($path);
    } catch (Throwable) {
        return null;
    }
    $root = is_array($data) ? ($data['root'] ?? null) : null;
    if (!is_array($root)) {
        return null;
    }

    return [
        'name' => is_string($root['name'] ?? null) ? $root['name'] : null,
        'pretty_version' => is_string($root['pretty_version'] ?? null) ? $root['pretty_version'] : null,
        'reference' => is_string($root['reference'] ?? null) ? $root['reference'] : null,
    ];
}

/**
 * The APP_ENV a dotenv file assigns, without reading any other value.
 */
function nhc_dotenv_app_env(string $bytes): ?string
{
    return preg_match('/^[ \t]*(?:export[ \t]+)?APP_ENV[ \t]*=[ \t]*["\']?([A-Za-z]+)["\']?[ \t]*\r?$/m', $bytes, $match) === 1 ? $match[1] : null;
}

/** Whether a dotenv file assigns a non-empty WAASEYAA_APP_SECRET. The value is never returned. */
function nhc_dotenv_has_secret(string $bytes): bool
{
    return preg_match('/^[ \t]*(?:export[ \t]+)?WAASEYAA_APP_SECRET[ \t]*=[ \t]*["\']?[^\s"\'#]+/m', $bytes) === 1;
}

/**
 * Collect and validate one consumer lane's evidence.
 *
 * @param array<string, mixed> $contract
 * @param array<string, string|false> $env
 * @param callable(non-empty-list<string>): ?string $runner child processes (Composer's version)
 * @param callable(list<string>): ?string $git Git in the checkout; null when it fails
 *
 * @return array{evidence: array<string, mixed>, exit: int}
 */
function nhc_collect(array $contract, string $host, string $root, array $env, callable $runner, callable $git, ?string $checkedOutHead): array
{
    $section = $contract['consumer_cli'];
    $lane = $section['lanes'][$host] ?? null;
    if (!is_array($lane)) {
        throw new InvalidArgumentException("host {$host} has no consumer lane in the contract");
    }
    $value = static fn(string $name): ?string => is_string($env[$name] ?? null) && $env[$name] !== '' ? $env[$name] : null;

    $identity = nhe_host_identity($contract, $host, $env, $runner);
    $violations = $identity['violations'];
    $incomplete = $identity['incomplete'];

    // Source subject: the originating checked-out candidate.
    $subject = nhe_subject($env, $checkedOutHead);
    array_push($violations, ...$subject['violations']);
    array_push($incomplete, ...$subject['incomplete']);
    $repository = $value('GITHUB_REPOSITORY');
    if ($repository === null) {
        $incomplete[] = 'GITHUB_REPOSITORY is not set';
    }

    // The candidate the consumer was built from.
    $candidateRevision = $checkedOutHead;
    if ($lane['candidate_binding'] === 'harness-archive') {
        $candidateRevision = $value('WAASEYAA_CONSUMER_CANDIDATE_REVISION');
        if ($candidateRevision === null) {
            $incomplete[] = 'the harness did not hand over the candidate revision it archived';
        } elseif (preg_match(NHC_SHA_PATTERN, $candidateRevision) !== 1) {
            $violations[] = 'the harness candidate revision is not a 40-character SHA';
        } elseif ($candidateRevision !== $checkedOutHead) {
            $violations[] = "the harness archived {$candidateRevision}, not the checked-out candidate " . ($checkedOutHead ?? 'unknown');
        }
    }
    $candidate = ['revision' => $candidateRevision, 'binding' => $lane['candidate_binding'], 'skeleton_tree' => null];

    // Governed step results.
    $steps = nhe_parse_step_results((string) ($env['NATIVE_HOST_STEP_RESULTS'] ?? ''), NHC_STEP_IDS);
    array_push($violations, ...$steps['violations']);

    $consumerRoot = $value('WAASEYAA_CONSUMER_ROOT');
    if ($consumerRoot !== null && !is_dir($consumerRoot)) {
        $violations[] = 'the handed-over consumer root is not a directory';
        $consumerRoot = null;
    } elseif ($consumerRoot === null) {
        $incomplete[] = 'the lane did not hand over its consumer root';
    }

    // Lifecycle completion: the artifacts site:init and install:init leave.
    $lifecycle = [];
    foreach ($section['lifecycle_artifacts'] as $artifact) {
        $lifecycle[$artifact] = $consumerRoot !== null && is_file($consumerRoot . '/' . $artifact);
        if ($consumerRoot !== null && !$lifecycle[$artifact]) {
            $violations[] = "the consumer lacks {$artifact}, so its lifecycle did not complete";
        }
    }

    // The CLI result and catalogue.
    $cli = [
        'argv' => $section['argv'],
        'powershell' => nhe_render_powershell($section['argv']),
        'outcome' => $steps['results']['consumer-cli']['outcome'] ?? null,
        'exit_code' => $steps['results']['consumer-cli']['exit_code'] ?? null,
        'required_commands' => $section['required_commands'],
        'missing_commands' => $section['required_commands'],
        'catalogue' => [],
    ];
    $stdoutPath = $value('NATIVE_HOST_CLI_STDOUT');
    $stdout = $stdoutPath === null ? false : @file_get_contents($stdoutPath);
    if (!is_string($stdout)) {
        $violations[] = 'the consumer CLI output was not captured';
    } else {
        $catalogue = array_values(array_unique(nhc_catalogue($stdout)));
        $cli['catalogue'] = $catalogue;
        $cli['missing_commands'] = array_values(array_diff($section['required_commands'], $catalogue));
        foreach ($cli['missing_commands'] as $missing) {
            $violations[] = "the consumer catalogue does not list {$missing}";
        }
    }

    // Boot environment the CLI step inherited.
    $boot = $lane['boot_environment'];
    $observedBoot = ['app_env' => null, 'source' => null, 'app_secret' => 'absent'];
    $processEnv = $value('APP_ENV');
    if ($boot['source'] === 'process') {
        $observedBoot = ['app_env' => $processEnv, 'source' => 'process', 'app_secret' => $value('WAASEYAA_APP_SECRET') !== null ? 'present' : 'absent'];
    } elseif ($processEnv !== null) {
        $observedBoot = ['app_env' => $processEnv, 'source' => 'process', 'app_secret' => $value('WAASEYAA_APP_SECRET') !== null ? 'present' : 'absent'];
    } elseif ($consumerRoot !== null) {
        $dotenv = @file_get_contents($consumerRoot . '/.env');
        if (is_string($dotenv)) {
            $observedBoot = ['app_env' => nhc_dotenv_app_env($dotenv), 'source' => 'consumer-dotenv', 'app_secret' => nhc_dotenv_has_secret($dotenv) ? 'present' : 'absent'];
        }
    }
    // Without a consumer a dotenv boot cannot be observed; that is already incomplete.
    $observable = $boot['source'] === 'process' || $processEnv !== null || $consumerRoot !== null;
    if ($observable && ($observedBoot['source'] !== $boot['source'] || $observedBoot['app_env'] !== $boot['app_env'] || $observedBoot['app_secret'] !== 'present')) {
        $violations[] = sprintf(
            'the consumer booted with APP_ENV %s from %s and %s application secret, not APP_ENV %s from %s',
            json_encode($observedBoot['app_env']),
            json_encode($observedBoot['source']),
            $observedBoot['app_secret'] === 'present' ? 'an' : 'no',
            $boot['app_env'],
            $boot['source'],
        );
    }

    // Installed cohort, bound to the candidate tree by content.
    $cohort = ['digest' => null, 'package_count' => 0, 'packages' => []];
    $rootPackage = null;
    if (is_string($candidateRevision) && preg_match(NHC_SHA_PATTERN, $candidateRevision) === 1 && $consumerRoot !== null) {
        $listing = $git(['ls-tree', '-r', '-z', '--full-tree', $candidateRevision]);
        $tree = is_string($listing) ? nhc_parse_tree($listing) : null;
        if ($tree === null || $tree === []) {
            $incomplete[] = "the candidate tree of {$candidateRevision} could not be listed";
        } else {
            $readCandidate = static fn(string $path): ?string => is_string($bytes = @file_get_contents($root . '/' . $path)) ? $bytes : null;
            // Windows filesystems are case-insensitive; Linux ones are not.
            $collected = nhc_cohort($consumerRoot, $tree, $readCandidate, PHP_OS_FAMILY === 'Windows');
            $cohort = $collected['cohort'];
            array_push($violations, ...$collected['violations']);
            array_push($incomplete, ...$collected['incomplete']);
        }

        $skeletonTree = $git(['rev-parse', '--verify', "{$candidateRevision}:skeleton"]);
        $candidate['skeleton_tree'] = is_string($skeletonTree) && preg_match(NHC_SHA_PATTERN, trim($skeletonTree)) === 1 ? trim($skeletonTree) : null;
        if ($candidate['skeleton_tree'] === null) {
            $incomplete[] = "the candidate skeleton tree of {$candidateRevision} could not be resolved";
        }

        $rootPackage = nhc_root_package($consumerRoot);
        if ($rootPackage === null) {
            $incomplete[] = 'the consumer has no readable root package in vendor/composer/installed.php';
        }
    }

    // The Linux scratch commit: a different reference, the candidate skeleton tree.
    if ($rootPackage !== null) {
        if ($lane['candidate_binding'] === 'harness-archive') {
            $projectRevision = $value('WAASEYAA_CONSUMER_PROJECT_REVISION');
            $projectSource = $value('WAASEYAA_CONSUMER_PROJECT_SOURCE');
            $scratchTree = $projectRevision !== null && $projectSource !== null && preg_match(NHC_SHA_PATTERN, $projectRevision) === 1
                ? $git(['-C', $projectSource, 'rev-parse', '--verify', "{$projectRevision}^{tree}"])
                : null;
            $scratchTree = is_string($scratchTree) && preg_match(NHC_SHA_PATTERN, trim($scratchTree)) === 1 ? trim($scratchTree) : null;
            $rootPackage['relation'] = NHC_SCRATCH_RELATION;
            $rootPackage['scratch_commit'] = ['revision' => $projectRevision, 'tree' => $scratchTree];
            if ($projectRevision === null || $projectSource === null) {
                $incomplete[] = 'the harness did not hand over its scratch project commit';
            } elseif ($scratchTree === null) {
                $incomplete[] = 'the scratch project commit tree could not be resolved';
            } else {
                if ($rootPackage['reference'] !== $projectRevision) {
                    $violations[] = 'the consumer root package reference ' . json_encode($rootPackage['reference']) . " is not the scratch project commit {$projectRevision}";
                }
                if ($candidate['skeleton_tree'] !== null && $scratchTree !== $candidate['skeleton_tree']) {
                    $violations[] = "the scratch project commit tree {$scratchTree} is not the candidate skeleton tree {$candidate['skeleton_tree']}";
                }
            }
        } else {
            $rootPackage['relation'] = 'created-from-candidate-checkout-skeleton';
        }
    }

    $result = $violations !== [] ? 'fail' : ($incomplete !== [] ? 'incomplete' : 'pass');
    $evidence = [
        'schema' => NHC_EVIDENCE_SCHEMA,
        'schema_version' => NHE_SCHEMA_VERSION,
        'result' => $result,
        'host' => $host,
        'lane' => ['job' => $lane['job'], 'candidate_binding' => $lane['candidate_binding']],
        'contract' => ['path' => 'tools/native-host-contract.json', 'section' => 'consumer_cli', 'sha256' => nhe_contract_digest($contract)],
        'subject' => $subject['subject'] + ['repository' => $repository],
        'candidate' => $candidate,
        'root_package' => $rootPackage,
        'cohort' => $cohort,
        'lifecycle' => ['artifacts' => $lifecycle],
        'cli' => $cli,
        'boot_environment' => $observedBoot,
        'runner' => $identity['runner'],
        'hosted_shell' => $identity['hosted_shell'],
        'runtime' => $identity['runtime'],
        'steps' => array_map(
            static fn(string $id): array => ['id' => $id] + ($steps['results'][$id] ?? ['outcome' => null, 'exit_code' => null]),
            NHC_STEP_IDS,
        ),
        'violations' => $violations,
        'incomplete' => $incomplete,
    ];

    return [
        'evidence' => $evidence,
        'exit' => $violations !== [] ? NHE_EXIT_VIOLATION : ($incomplete !== [] ? NHE_EXIT_INCOMPLETE : NHE_EXIT_PASS),
    ];
}

/**
 * Why one consumer record cannot stand as passing evidence for $host, re-derived
 * from its fields rather than trusted from its `result`.
 *
 * @param array<mixed> $record
 * @param array<string, mixed> $contract
 *
 * @return list<string>
 */
function nhc_record_problems(array $record, string $host, array $contract, ?string $verifierHead, ?int $runId, ?int $runAttempt): array
{
    $section = $contract['consumer_cli'];
    $lane = $section['lanes'][$host];
    $definition = $contract['hosts'][$host];
    $at = static function (string $path) use ($record): mixed {
        $value = $record;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    };
    $steps = [];
    foreach (is_array($at('steps')) ? $at('steps') : [] as $step) {
        if (is_array($step) && is_string($step['id'] ?? null)) {
            $steps[$step['id']][] = $step;
        }
    }
    $catalogue = $at('cli.catalogue');
    $runtimeInRange = static function (string $tool) use ($at, $contract): bool {
        $version = $at("runtime.{$tool}");

        return is_string($version) && nhe_version_in_range($version, $contract['runtime'][$tool]['min'], $contract['runtime'][$tool]['below']);
    };
    $sha = static fn(mixed $value): bool => is_string($value) && preg_match(NHC_SHA_PATTERN, $value) === 1;

    $checks = [
        'schema' => $at('schema') === NHC_EVIDENCE_SCHEMA && $at('schema_version') === NHE_SCHEMA_VERSION,
        'result pass' => $at('result') === 'pass' && $at('violations') === [] && $at('incomplete') === [],
        'lane' => $at('lane.job') === $lane['job'] && $at('lane.candidate_binding') === $lane['candidate_binding'],
        'contract digest' => $at('contract.sha256') === nhe_contract_digest($contract),
        'checked-out HEAD' => $sha($verifierHead) && $at('subject.checked_out_head') === $verifierHead,
        'repository' => is_string($at('subject.repository')) && $at('subject.repository') !== '',
        'run id' => $runId !== null && $at('subject.run_id') === $runId,
        'run attempt' => is_int($at('subject.run_attempt')) && $runAttempt !== null && $at('subject.run_attempt') <= $runAttempt,
        'candidate revision' => $sha($verifierHead) && $at('candidate.revision') === $verifierHead && $at('candidate.binding') === $lane['candidate_binding'],
        'candidate skeleton tree' => $sha($at('candidate.skeleton_tree')),
        'lifecycle step' => count($steps['lifecycle'] ?? []) === 1 && ($steps['lifecycle'][0]['outcome'] ?? null) === 'success' && ($steps['lifecycle'][0]['exit_code'] ?? null) === 0,
        'consumer-cli step' => count($steps['consumer-cli'] ?? []) === 1 && ($steps['consumer-cli'][0]['outcome'] ?? null) === 'success' && ($steps['consumer-cli'][0]['exit_code'] ?? null) === 0,
        'lifecycle artifacts' => $at('lifecycle.artifacts') === array_fill_keys($section['lifecycle_artifacts'], true),
        'CLI argv' => $at('cli.argv') === $section['argv'],
        'CLI exit code' => $at('cli.exit_code') === 0 && $at('cli.outcome') === 'success',
        'required catalogue entries' => is_array($catalogue) && array_diff($section['required_commands'], $catalogue) === [],
        'cohort' => is_string($at('cohort.digest')) && preg_match('/^[0-9a-f]{64}$/D', $at('cohort.digest')) === 1
            && is_array($at('cohort.packages')) && $at('cohort.packages') !== [] && $at('cohort.package_count') === count($at('cohort.packages')),
        'boot environment' => $at('boot_environment') === ['app_env' => $lane['boot_environment']['app_env'], 'source' => $lane['boot_environment']['source'], 'app_secret' => 'present'],
        'runner' => $at('runner.label') === $definition['runner'] && $at('runner.runner_os') === $definition['runner_os']
            && $at('runner.os_family') === $definition['php_os_family'] && is_string($at('runner.os')) && $at('runner.os') !== ''
            && is_string($at('runner.image_os')) && is_string($at('runner.image_version')),
        'hosted shell' => $at('hosted_shell.name') === $contract['harness_shell'] && is_string($at('hosted_shell.version')) && preg_match('/^\d+\.\d+/', $at('hosted_shell.version')) === 1,
        'runtime' => $runtimeInRange('php') && $runtimeInRange('composer') && $runtimeInRange('sqlite'),
    ];
    if ($lane['candidate_binding'] === 'harness-archive') {
        // The scratch recommit: its reference is recorded, never equated with
        // the candidate; its tree must be the candidate's skeleton tree.
        $checks['scratch project commit'] = $at('root_package.relation') === NHC_SCRATCH_RELATION
            && $sha($at('root_package.scratch_commit.revision'))
            && $at('root_package.reference') === $at('root_package.scratch_commit.revision')
            && $at('root_package.reference') !== $at('candidate.revision')
            && $at('root_package.scratch_commit.tree') === $at('candidate.skeleton_tree');
    }

    $problems = [];
    foreach ($checks as $check => $passed) {
        if (!$passed) {
            $problems[] = "the {$host} consumer record fails the {$check} check";
        }
    }

    return $problems;
}

/**
 * Accept exactly one passing Linux and one passing Windows consumer record
 * from this run: the same subject and candidate, an equivalent installed
 * cohort, a completed lifecycle and a zero-exit CLI listing every required
 * command.
 *
 * @param array<string, mixed> $contract
 * @param list<string> $hosts
 * @param array<string, string|false> $env
 *
 * @return array{hosts: array<string, string>, violations: list<string>, exit: int}
 */
function nhc_verify_set(string $directory, array $hosts, array $contract, array $env, ?string $verifierHead): array
{
    $violations = [];
    $lanes = array_keys($contract['consumer_cli']['lanes']);
    if (array_diff($hosts, $lanes) !== [] || array_diff($lanes, $hosts) !== [] || count($hosts) !== count(array_unique($hosts))) {
        $violations[] = 'the verified hosts must be exactly the consumer lanes: ' . implode(', ', $lanes);
    }
    if (!is_string($verifierHead) || preg_match(NHC_SHA_PATTERN, $verifierHead) !== 1) {
        $violations[] = "the verifier's checked-out HEAD could not be resolved";
    }
    $runId = is_string($env['GITHUB_RUN_ID'] ?? null) && preg_match('/^\d+$/D', $env['GITHUB_RUN_ID']) === 1 ? (int) $env['GITHUB_RUN_ID'] : null;
    $runAttempt = is_string($env['GITHUB_RUN_ATTEMPT'] ?? null) && preg_match('/^\d+$/D', $env['GITHUB_RUN_ATTEMPT']) === 1 ? (int) $env['GITHUB_RUN_ATTEMPT'] : null;
    if ($runId === null || $runAttempt === null) {
        $violations[] = 'GITHUB_RUN_ID and GITHUB_RUN_ATTEMPT must be set for the verifier';
    }

    // One artifact per lane, each holding one record for that lane's host.
    $claims = [];
    foreach (glob($directory . '/' . NHC_ARTIFACT_PREFIX . '*', GLOB_ONLYDIR) ?: [] as $artifact) {
        $name = substr(basename($artifact), strlen(NHC_ARTIFACT_PREFIX));
        if (!in_array($name, $hosts, true)) {
            $violations[] = 'unexpected consumer evidence artifact ' . NHC_ARTIFACT_PREFIX . $name;
            continue;
        }
        $raw = @file_get_contents($artifact . '/evidence.json');
        $record = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($record) || array_is_list($record)) {
            $violations[] = "the {$name} consumer evidence record is missing or not a JSON object";
            $claims[$name][] = null;
            continue;
        }
        $claimed = $record['host'] ?? null;
        if ($claimed !== $name) {
            $violations[] = 'the ' . NHC_ARTIFACT_PREFIX . "{$name} artifact carries a record for " . json_encode($claimed);
        }
        $claims[is_string($claimed) ? $claimed : $name][] = $record;
    }

    $records = [];
    $summary = [];
    foreach ($hosts as $host) {
        $found = $claims[$host] ?? [];
        if ($found === []) {
            $violations[] = "the {$host} consumer evidence is missing";
            $summary[$host] = 'missing';
            continue;
        }
        if (count($found) > 1) {
            $violations[] = "the {$host} consumer evidence is duplicated";
            $summary[$host] = 'duplicated';
            continue;
        }
        if ($found[0] === null) {
            $summary[$host] = 'unreadable';
            continue;
        }
        $records[$host] = $found[0];
        $summary[$host] = is_string($found[0]['result'] ?? null) ? $found[0]['result'] : 'unknown';
        if (isset($contract['consumer_cli']['lanes'][$host])) {
            array_push($violations, ...nhc_record_problems($found[0], $host, $contract, $verifierHead, $runId, $runAttempt));
        }
    }

    // The two lanes of one run share one subject, candidate and cohort.
    $projection = static function (array $record): array {
        $subject = is_array($record['subject'] ?? null) ? $record['subject'] : [];
        $shared = array_intersect_key($subject, array_flip(['profile', 'event_name', 'github_sha', 'dispatch_sha', 'pull_request_head_sha', 'repository']));
        ksort($shared);
        $packages = [];
        foreach (is_array($record['cohort']['packages'] ?? null) ? $record['cohort']['packages'] : [] as $package) {
            $packages[] = is_array($package) ? [$package['name'] ?? null, $package['version'] ?? null, $package['content_digest'] ?? null] : null;
        }

        return [
            'subject' => $shared,
            'candidate' => [$record['candidate']['revision'] ?? null, $record['candidate']['skeleton_tree'] ?? null],
            'cohort' => [$record['cohort']['digest'] ?? null, $packages],
        ];
    };
    $first = null;
    foreach ($records as $host => $record) {
        $current = $projection($record);
        if ($first === null) {
            $first = [$host, $current];
            continue;
        }
        foreach (['subject' => 'subjects', 'candidate' => 'candidates', 'cohort' => 'installed package cohorts'] as $part => $label) {
            if ($current[$part] !== $first[1][$part]) {
                $violations[] = "the {$host} and {$first[0]} consumer records bind different {$label}";
            }
        }
    }

    return ['hosts' => $summary, 'violations' => $violations, 'exit' => $violations === [] ? NHE_EXIT_PASS : NHE_EXIT_VIOLATION];
}
