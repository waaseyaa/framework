<?php

declare(strict_types=1);

/**
 * Assertion engine for the Studio-alpha packaged acceptance harness (#2789).
 *
 * The bash driver owns process lifecycle and ordering; this owns every claim
 * that can be decided from bytes. It is deliberately a separate executable from
 * `split-artifact-acceptance.php`: that engine owns the packaging boundary and
 * this one consumes its seal rather than re-deriving it, so there is one
 * sealing authority and one place a packaging question is answered.
 *
 * Usage:
 *   studio-alpha-acceptance.php surfaces
 *   studio-alpha-acceptance.php assert artifact-binding SEAL CONSUMER CANDIDATE
 *   studio-alpha-acceptance.php assert env-hygiene CONSUMER
 *   studio-alpha-acceptance.php assert concealed-404 BASE_URL TYPE DENIED_ID MISSING_ID
 *   studio-alpha-acceptance.php assert ownership CONSUMER UNIT_ID PATH[,PATH...]
 *   studio-alpha-acceptance.php snapshot CONSUMER DIR
 *   studio-alpha-acceptance.php restore CONSUMER DIR
 *   studio-alpha-acceptance.php assert restored CONSUMER DIR
 *   studio-alpha-acceptance.php self-test CONSUMER DIR
 */

const GOVERNED_STATE = [
    '.waaseyaa',
    'AGENTS.md',
    'bin',
    'config',
    'composer.json',
    'composer.site-recipes.json',
    'public',
    'src',
    'templates',
    'tests',
];

/** @param list<string> $argv */
function main(array $argv): int
{
    $command = $argv[1] ?? '';

    return match ($command) {
        'surfaces' => surfaces(),
        'assert' => assertion(array_slice($argv, 2)),
        'snapshot' => snapshot($argv[2] ?? '', $argv[3] ?? ''),
        'restore' => restore($argv[2] ?? '', $argv[3] ?? ''),
        'self-test' => selfTest($argv[2] ?? '', $argv[3] ?? ''),
        default => fail("Unknown command: {$command}"),
    };
}

/** @param list<string> $arguments */
function assertion(array $arguments): int
{
    $surface = $arguments[0] ?? '';

    return match ($surface) {
        'artifact-binding' => assertArtifactBinding($arguments[1] ?? '', $arguments[2] ?? '', $arguments[3] ?? ''),
        'env-hygiene' => assertEnvHygiene($arguments[1] ?? ''),
        'concealed-404' => assertConcealedNotFound(
            $arguments[1] ?? '',
            $arguments[2] ?? '',
            $arguments[3] ?? '',
            $arguments[4] ?? '',
            $arguments[5] ?? '',
            $arguments[6] ?? '',
        ),
        'ownership' => assertOwnership($arguments[1] ?? '', $arguments[2] ?? '', $arguments[3] ?? ''),
        'restored' => assertRestored($arguments[1] ?? '', $arguments[2] ?? ''),
        'unchanged' => assertUnchanged($arguments[1] ?? '', $arguments[2] ?? ''),
        'interruption-envelope' => assertInterruptionEnvelope($arguments[1] ?? ''),
        'recovery-envelope' => assertRecoveryEnvelope($arguments[1] ?? ''),
        default => fail("Unknown surface: {$surface}"),
    };
}

function surfaces(): int
{
    $roster = readJson(__DIR__ . '/fixtures/studio-alpha-acceptance-surfaces.json');
    foreach ($roster['surfaces'] as $surface) {
        printf("  [%-8s] %-30s %s\n", $surface['state'], $surface['id'], wordwrap($surface['summary'], 96, "\n" . str_repeat(' ', 44)));
    }
    $reserved = array_values(array_filter($roster['surfaces'], static fn(array $s): bool => $s['state'] !== 'live'));
    printf("\n  %d live surface(s), %d reserved.\n", count($roster['surfaces']) - count($reserved), count($reserved));

    return 0;
}

/**
 * Every installed waaseyaa/* must have come from the sealed artifact
 * repository. A path repository, a VCS checkout or a symlink would make the
 * whole run a proof about the working tree rather than about the candidate.
 */
function assertArtifactBinding(string $sealPath, string $consumer, string $candidate): int
{
    $seal = readJson($sealPath);
    $installed = readJson($consumer . '/vendor/composer/installed.json');
    $sealed = [];
    foreach ($seal['members'] ?? [] as $member) {
        $sealed[$member['name']] = $member;
    }
    if ($sealed === []) {
        return fail('The seal declares no members.');
    }

    $checked = 0;
    foreach ($installed['packages'] ?? [] as $package) {
        $name = $package['name'];
        if (!str_starts_with($name, 'waaseyaa/')) {
            continue;
        }
        ++$checked;
        if (!isset($sealed[$name])) {
            return fail("Installed {$name} is not in the sealed candidate: it cannot have come from the artifact repository.");
        }
        $type = $package['type'] ?? 'library';
        $source = $package['installation-source'] ?? null;
        if ($type === 'metapackage') {
            // A metapackage is resolved and never extracted, so demanding a
            // dist installation of one would be demanding the impossible.
            if ($source !== null) {
                return fail("Metapackage {$name} recorded an installation source: {$source}.");
            }
            continue;
        }
        if ($source !== 'dist') {
            return fail("{$name} was not installed from dist (installation-source: " . var_export($source, true) . ').');
        }
        $reference = $package['dist']['url'] ?? '';
        if (!str_ends_with((string) $reference, '.zip')) {
            return fail("{$name} was not installed from a local artifact zip (dist.url: {$reference}).");
        }
        $installPath = $consumer . '/vendor/' . $name;
        if (is_link($installPath)) {
            return fail("{$name} is a symlink into the checkout, not installed bytes.");
        }
        if (!is_dir($installPath)) {
            return fail("{$name} has no installed directory at vendor/{$name}.");
        }
    }

    if ($checked === 0) {
        return fail('No waaseyaa/* package is installed in the consumer.');
    }
    foreach (consumerRepositories($consumer) as $repository) {
        $repositoryType = $repository['type'] ?? '';
        if (in_array($repositoryType, ['path', 'vcs', 'git'], true)) {
            return fail("The consumer declares a {$repositoryType} repository; the artifact repository must be the only source of waaseyaa/*.");
        }
    }

    $sealedCandidate = (string) ($seal['commit'] ?? $seal['candidate'] ?? '');
    if ($sealedCandidate !== '' && $sealedCandidate !== $candidate) {
        return fail("The seal binds {$sealedCandidate}, not the candidate under test ({$candidate}).");
    }

    printf("    artifact-binding OK: %d installed waaseyaa/* member(s), all from sealed archive bytes.\n", $checked);

    return 0;
}

/** @return list<array<string, mixed>> */
function consumerRepositories(string $consumer): array
{
    $manifest = readJson($consumer . '/composer.json');
    $repositories = $manifest['repositories'] ?? [];

    return array_values(array_filter(
        is_array($repositories) ? $repositories : [],
        static fn(mixed $repository): bool => is_array($repository),
    ));
}

/**
 * A dev fallback account masks field-read and entity-access denials, so an
 * acceptance run with one enabled proves strictly less than it appears to. The
 * variable must be absent from the generated environment AND unset in the
 * process that drives the proof.
 */
function assertEnvHygiene(string $consumer): int
{
    $envPath = $consumer . '/.env';
    if (!is_file($envPath)) {
        return fail('The consumer has no .env; the create-project script did not run.');
    }
    foreach (file($envPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^\s*WAASEYAA_DEV_FALLBACK_ACCOUNT\s*=\s*(\S+)/', $line, $match) === 1) {
            return fail("The generated environment sets WAASEYAA_DEV_FALLBACK_ACCOUNT={$match[1]}; the acceptance consumer must not have one at all.");
        }
    }
    $processValue = getenv('WAASEYAA_DEV_FALLBACK_ACCOUNT');
    if (is_string($processValue) && $processValue !== '') {
        return fail("WAASEYAA_DEV_FALLBACK_ACCOUNT={$processValue} is set in the harness process environment.");
    }

    print "    env-hygiene OK: no dev fallback account in the consumer environment or this process.\n";

    return 0;
}

/**
 * The single-read boundary must answer a view-denied resource and a missing
 * resource with the same bytes. Comparing the two full documents is the whole
 * assertion: a difference anywhere — status, title, code, detail, member order
 * — is an existence oracle.
 */
function assertConcealedNotFound(
    string $baseUrl,
    string $type,
    string $deniedId,
    string $missingId,
    string $username,
    string $password,
): int {
    // The denial must be a real one: a real, authenticated, unprivileged
    // identity that genuinely cannot view the resource. An anonymous probe
    // would leave "denied because unauthenticated" and "denied by policy"
    // indistinguishable, and a privileged one would prove nothing at all.
    $session = $username === '' ? null : authenticate($baseUrl, $username, $password);

    $denied = httpGet("{$baseUrl}/api/{$type}/{$deniedId}", $session);
    $missing = httpGet("{$baseUrl}/api/{$type}/{$missingId}", $session);

    if ($denied['status'] !== 404) {
        return fail("The view-denied read returned {$denied['status']}, not 404: " . trim($denied['body']));
    }
    if ($missing['status'] !== 404) {
        return fail("The missing read returned {$missing['status']}, not 404: " . trim($missing['body']));
    }

    $deniedDocument = decodeDocument($denied['body'], 'denied');
    $missingDocument = decodeDocument($missing['body'], 'missing');
    $deniedError = $deniedDocument['errors'][0] ?? [];
    $missingError = $missingDocument['errors'][0] ?? [];

    if (($deniedError['code'] ?? null) !== 'ENTITY_NOT_FOUND') {
        return fail('The view-denied 404 does not carry the typed concealed code: ' . json_encode($deniedError, JSON_UNESCAPED_SLASHES));
    }
    if (($missingError['code'] ?? null) !== 'ENTITY_NOT_FOUND') {
        return fail('The missing 404 does not carry the typed concealed code: ' . json_encode($missingError, JSON_UNESCAPED_SLASHES));
    }

    // The ids differ by construction, so the documents are compared with the
    // probe id normalized out; every other byte must match exactly.
    $normalize = static fn(string $body, string $id): string => str_replace("'{$id}'", "'<id>'", $body);
    $deniedNormalized = $normalize($denied['body'], $deniedId);
    $missingNormalized = $normalize($missing['body'], $missingId);
    if ($deniedNormalized !== $missingNormalized) {
        return fail(
            "The denied and missing single reads are not byte-identical.\n      denied : {$deniedNormalized}\n      missing: {$missingNormalized}",
        );
    }

    printf("    concealed-404 OK: both reads returned identical %s bytes.\n", $deniedError['code']);

    return 0;
}

/** @return array<string, mixed> */
function decodeDocument(string $body, string $label): array
{
    try {
        $document = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        fail("The {$label} read did not return JSON: " . $exception->getMessage() . ' — ' . substr($body, 0, 200));
    }

    return is_array($document) ? $document : [];
}

/** @return array{status: int, body: string, headers: list<string>} */
function httpGet(string $url, ?string $cookie = null): array
{
    $headers = "Accept: application/vnd.api+json\r\n";
    if ($cookie !== null && $cookie !== '') {
        $headers .= "Cookie: {$cookie}\r\n";
    }
    $context = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => $headers,
        'ignore_errors' => true,
        'timeout' => 20,
    ]]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    $responseHeaders = $http_response_header ?? [];
    foreach ($responseHeaders as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match) === 1) {
            $status = (int) $match[1];
        }
    }

    return ['status' => $status, 'body' => $body === false ? '' : $body, 'headers' => $responseHeaders];
}

/** Sign the seeded unprivileged identity in and return its session cookie. */
function authenticate(string $baseUrl, string $username, string $password): string
{
    $payload = json_encode(['username' => $username, 'password' => $password], JSON_THROW_ON_ERROR);
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
        'content' => $payload,
        'ignore_errors' => true,
        'timeout' => 20,
    ]]);
    $body = @file_get_contents("{$baseUrl}/api/auth/login", false, $context);
    $status = 0;
    $cookie = '';
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match) === 1) {
            $status = (int) $match[1];
        }
        if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $match) === 1) {
            $cookie = trim($match[1]);
        }
    }
    if ($status !== 200) {
        fail("The seeded unprivileged identity could not sign in (HTTP {$status}): " . substr((string) $body, 0, 300));
    }
    if ($cookie === '') {
        fail('The login response carried no session cookie, so the denial probe would be anonymous.');
    }

    return $cookie;
}

/**
 * The interruption is reported, never repaired: the envelope must name the
 * abandonment and carry no result, because a result would mean the publication
 * completed after all.
 */
function assertInterruptionEnvelope(string $outputPath): int
{
    $envelope = readEnvelope($outputPath, 'interrupted apply');
    if (!array_key_exists('result', $envelope) || $envelope['result'] !== null) {
        return fail('The interrupted apply reported a result; it must abandon the publication instead.');
    }
    $message = (string) ($envelope['errors'][0]['message'] ?? '');
    if (!str_contains($message, 'Interrupted after the transaction journal was durable')) {
        return fail('The interrupted apply envelope does not name the durable-journal interruption: ' . $message);
    }
    if (!str_contains($message, 'APP_ENV=development')) {
        return fail('The interruption envelope does not record the development-only seam: ' . $message);
    }

    print "    interruption envelope OK: publication abandoned after its journal was durable.\n";

    return 0;
}

/** The next ordinary apply must recover first, then complete its own work. */
function assertRecoveryEnvelope(string $outputPath): int
{
    $envelope = readEnvelope($outputPath, 'recovering apply');
    $result = $envelope['result'] ?? null;
    if (!is_array($result)) {
        return fail('The recovering apply produced no result document.');
    }
    if (($result['recovered_interrupted_transaction'] ?? false) !== true) {
        return fail('The recovering apply did not report recovering the interrupted transaction.');
    }
    if (!in_array($result['outcome'] ?? '', ['applied', 'no_changes'], true)) {
        return fail('The recovering apply did not complete: outcome ' . var_export($result['outcome'] ?? null, true));
    }
    $receipts = $envelope['receipts'] ?? [];
    if (($receipts[0]['operation'] ?? '') !== 'site.recover') {
        return fail('Recovery is not the first receipt of the recovering apply.');
    }

    printf("    recovery envelope OK: recovered first, then %s.\n", $result['outcome']);

    return 0;
}

/** @return array<string, mixed> */
function readEnvelope(string $outputPath, string $label): array
{
    $raw = trim((string) @file_get_contents($outputPath));
    if ($raw === '') {
        fail("The {$label} produced no output at {$outputPath}.");
    }
    // The command emits one canonical JSON document on stdout; anything the
    // runtime wrote before it is diagnostics, so decode the last line.
    $lines = array_values(array_filter(array_map('trim', explode("\n", $raw)), static fn(string $line): bool => $line !== ''));
    $candidate = $lines[array_key_last($lines)];

    try {
        $decoded = json_decode($candidate, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        fail("The {$label} did not emit a JSON envelope: " . $exception->getMessage() . " — {$candidate}");
    }

    return is_array($decoded) ? $decoded : [];
}

/** A refusal must write nothing: the governed state has to be bit-for-bit unmoved. */
function assertUnchanged(string $consumer, string $directory): int
{
    $recorded = trim((string) @file_get_contents($directory . '/.digest'));
    $current = governedDigest($consumer);
    if ($recorded === '') {
        return fail('No recorded digest to compare the refusal against.');
    }
    if ($recorded !== $current) {
        return fail("The refusal changed governed state ({$current} != {$recorded}).");
    }

    print "    zero-write OK: the refused request left governed state untouched.\n";

    return 0;
}

/**
 * The hand extension must be recorded as an owned generation unit, not merely
 * present on disk: files a generator wrote without recording ownership are
 * exactly the ungoverned state #2789 removes.
 */
function assertOwnership(string $consumer, string $unitId, string $expectedPaths): int
{
    $metadata = readJson($consumer . '/.waaseyaa/generated.json');
    $unit = null;
    foreach ($metadata['units'] ?? [] as $candidate) {
        if (($candidate['id'] ?? null) === $unitId) {
            $unit = $candidate;
        }
    }
    if ($unit === null) {
        return fail("Generated ownership records no {$unitId} unit.");
    }
    if (($unit['disposition'] ?? null) !== 'seeded') {
        return fail("Unit {$unitId} is not seeded: " . var_export($unit['disposition'] ?? null, true));
    }

    $owned = [];
    foreach ($metadata['artifacts'] ?? [] as $row) {
        if (($row['unit'] ?? 'site') === $unitId) {
            $owned[] = $row['path'];
        }
    }
    sort($owned, SORT_STRING);
    $expected = array_values(array_filter(array_map('trim', explode(',', $expectedPaths))));
    sort($expected, SORT_STRING);
    if ($owned !== $expected) {
        return fail("Unit {$unitId} owns " . json_encode($owned) . ', expected ' . json_encode($expected) . '.');
    }
    foreach ($owned as $path) {
        if (!is_file($consumer . '/' . $path)) {
            return fail("Recorded artifact {$path} is not on disk.");
        }
    }

    printf("    hand-extension OK: %s owns %d recorded artifact(s).\n", $unitId, count($owned));

    return 0;
}

/**
 * Snapshot the governed state before destructive probes. Restoration is what
 * makes the later probes honest: a harness that ends on a wrecked consumer
 * cannot claim the candidate is still governed.
 */
function snapshot(string $consumer, string $directory): int
{
    if ($consumer === '' || $directory === '') {
        return fail('snapshot requires a consumer and a target directory.');
    }
    ensureDirectory($directory);
    foreach (GOVERNED_STATE as $entry) {
        $source = $consumer . '/' . $entry;
        if (!file_exists($source)) {
            continue;
        }
        copyTree($source, $directory . '/' . $entry);
    }
    file_put_contents($directory . '/.digest', governedDigest($consumer) . "\n");
    printf("    snapshot OK: governed state captured at %s\n", $directory);

    return 0;
}

function restore(string $consumer, string $directory): int
{
    foreach (GOVERNED_STATE as $entry) {
        removeTree($consumer . '/' . $entry);
        $source = $directory . '/' . $entry;
        if (file_exists($source)) {
            copyTree($source, $consumer . '/' . $entry);
        }
    }
    print "    restore OK: governed state returned to its snapshot.\n";

    return 0;
}

function assertRestored(string $consumer, string $directory): int
{
    $recorded = trim((string) @file_get_contents($directory . '/.digest'));
    $current = governedDigest($consumer);
    if ($recorded === '') {
        return fail('The snapshot recorded no digest.');
    }
    if ($recorded !== $current) {
        return fail("The consumer's governed state does not match its snapshot ({$current} != {$recorded}).");
    }

    print "    observation-then-restoration OK: governed state is byte-identical to the snapshot.\n";

    return 0;
}

function governedDigest(string $consumer): string
{
    $rows = [];
    foreach (GOVERNED_STATE as $entry) {
        $source = $consumer . '/' . $entry;
        if (!file_exists($source)) {
            continue;
        }
        if (is_file($source)) {
            $rows[] = $entry . "\0" . hash_file('sha256', $source);
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($consumer) + 1);
            $rows[] = $relative . "\0" . hash_file('sha256', $file->getPathname());
        }
    }
    sort($rows, SORT_STRING);

    return hash('sha256', implode("\n", $rows));
}

/**
 * A harness that has only ever passed is not evidence. Every decidable
 * assertion is re-run against a deliberately corrupted copy and must fail.
 */
function selfTest(string $consumer, string $directory): int
{
    $failures = [];
    $scratch = sys_get_temp_dir() . '/waaseyaa-studio-alpha-selftest-' . bin2hex(random_bytes(6));
    ensureDirectory($scratch);

    try {
        // Control 1: a mutated governed state must not match its snapshot.
        $mutated = $scratch . '/mutated';
        copyTree($consumer . '/.waaseyaa', $mutated . '/.waaseyaa');
        file_put_contents($mutated . '/.waaseyaa/negative-control', "seeded corruption\n");
        if (governedDigest($mutated) === trim((string) @file_get_contents($directory . '/.digest'))) {
            $failures[] = 'A corrupted governed state matched the recorded snapshot digest.';
        }

        // Control 2: ownership assertion must reject a unit that is not recorded.
        if (assertionProcess(['assert', 'ownership', $consumer, 'scaffold:content-type:never-published', 'src/Entity/Never.php']) === 0) {
            $failures[] = 'The ownership assertion accepted a unit that was never published.';
        }

        // Control 3: env hygiene must reject a fallback account.
        $fallbackConsumer = $scratch . '/fallback';
        ensureDirectory($fallbackConsumer);
        file_put_contents($fallbackConsumer . '/.env', "APP_ENV=local\nWAASEYAA_DEV_FALLBACK_ACCOUNT=true\n");
        if (assertionProcess(['assert', 'env-hygiene', $fallbackConsumer]) === 0) {
            $failures[] = 'The environment assertion accepted a dev fallback account.';
        }
    } finally {
        removeTree($scratch);
    }

    if ($failures !== []) {
        return fail("Seeded negative controls did not fire:\n  - " . implode("\n  - ", $failures));
    }

    print "    self-test OK: 3 seeded negative controls all fired.\n";

    return 0;
}

/** @param list<string> $arguments */
function assertionProcess(array $arguments): int
{
    $command = implode(' ', array_map('escapeshellarg', [PHP_BINARY, __FILE__, ...$arguments]));
    $output = [];
    $exitCode = 1;
    exec($command . ' 2>&1', $output, $exitCode);

    return $exitCode;
}

/** @return array<string, mixed> */
function readJson(string $path): array
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        fail("Unreadable JSON document: {$path}");
    }
    try {
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        fail("Invalid JSON in {$path}: " . $exception->getMessage());
    }

    return is_array($decoded) ? $decoded : [];
}

function ensureDirectory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0o755, true) && !is_dir($path)) {
        fail("Unable to create directory: {$path}");
    }
}

function copyTree(string $source, string $target): void
{
    if (is_file($source)) {
        ensureDirectory(dirname($target));
        copy($source, $target);
        chmod($target, fileperms($source) & 0o777);

        return;
    }
    ensureDirectory($target);
    foreach (scandir($source) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        copyTree($source . '/' . $entry, $target . '/' . $entry);
    }
}

function removeTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        removeTree($path . '/' . $entry);
    }
    @rmdir($path);
}

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

exit(main($argv));
