<?php

declare(strict_types=1);

/**
 * Host-neutral decisions of the #2647 skeleton Docker secret gate
 * (FW-2678-PORTABLE-DOCKER-RELEASE-04, #2678).
 *
 * bin/check-skeleton-docker-secret-exclusion builds real images, so its proof
 * stays Linux-owned in ci/skeleton-create-project. Everything it decides from
 * what it observed lives here instead, as plain functions over strings,
 * arrays and files: parsing the Dockerfile for context escapes, reading the
 * build-context inventory, scanning for sentinels, reading the generated
 * secrets, classifying the Docker probes, choosing the exit code, and
 * assembling the verdict. None of them starts a process, needs Docker or
 * branches on the host, so the native-host contract runs their tests on
 * native Windows and Linux alike.
 *
 * Deliberately dependency-free, like the gate that loads it: the hosted
 * Docker lane runs the gate before, and without, `composer install`.
 */

const SDSE_EXIT_PASS = 0;
const SDSE_EXIT_LEAK = 1;
const SDSE_EXIT_HARNESS = 2;
const SDSE_EXIT_NO_DOCKER = 3;

/** Longest sentinel the scan searches for; bounds the streaming-scan overlap window. */
const SDSE_SENTINEL_WINDOW = 512;

/**
 * Whether a build-context entry is a dotenv file (`.env` or `.env.*`, at any
 * depth). `.env.example` is one too; callers decide that it may ship.
 */
function sdse_is_dotenv(string $entry): bool
{
    $base = basename($entry);

    return $base === '.env' || str_starts_with($base, '.env.');
}

/**
 * The build-context inventory the daemon received, read from the entry list
 * of a `docker export` of a `FROM scratch` probe that copied the whole context
 * to /context. `docker export` adds daemon-owned entries (.dockerenv, /dev,
 * resolver files) that were never part of the build context, so only entries
 * under `context/` count.
 *
 * @param list<string> $rootfsEntries
 *
 * @return array{inventoryPaths: list<string>, dotenvPaths: list<string>}
 */
function sdse_context_inventory(array $rootfsEntries): array
{
    $inventory = [];
    foreach ($rootfsEntries as $entry) {
        if (!str_starts_with($entry, 'context/')) {
            continue;
        }
        $relative = rtrim(substr($entry, strlen('context/')), '/');
        if ($relative !== '') {
            $inventory[] = $relative;
        }
    }
    sort($inventory);

    return [
        'inventoryPaths' => $inventory,
        'dotenvPaths' => array_values(array_filter($inventory, 'sdse_is_dotenv')),
    ];
}

/**
 * Search a file for any of $needles, both as stored and gzip-decoded, so a
 * compressed layer blob cannot hide a secret from the scan.
 *
 * @param list<string> $needles
 *
 * @return list<string> the needles that were found
 */
function sdse_scan_file(string $path, array $needles): array
{
    if ($needles === []) {
        return [];
    }

    $found = sdse_stream_contains($path, $needles);

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return $found;
    }
    $magic = (string) fread($handle, 2);
    fclose($handle);

    if ($magic === "\x1f\x8b") {
        $gz = gzopen($path, 'rb');
        if ($gz !== false) {
            $found = array_values(array_unique(array_merge($found, sdse_stream_contains($path, $needles, $gz))));
            gzclose($gz);
        }
    }

    return $found;
}

/**
 * @param list<string>  $needles
 * @param resource|null $stream an already-open (possibly decompressing) handle
 *
 * @return list<string>
 */
function sdse_stream_contains(string $path, array $needles, $stream = null): array
{
    $handle = $stream ?? fopen($path, 'rb');
    if ($handle === false) {
        return [];
    }

    $found = [];
    $carry = '';
    while (!feof($handle)) {
        $chunk = fread($handle, 1 << 20);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $buffer = $carry . $chunk;
        foreach ($needles as $needle) {
            if (!in_array($needle, $found, true) && str_contains($buffer, $needle)) {
                $found[] = $needle;
            }
        }
        $carry = substr($buffer, -SDSE_SENTINEL_WINDOW);
    }

    if ($stream === null) {
        fclose($handle);
    }

    return $found;
}

/**
 * Reject a Dockerfile that could place content in a layer without it passing
 * through the (proven clean) build context: a remote `ADD`/`COPY` source, or
 * a `COPY`/`ADD` that names a dotenv source explicitly. `--from=` sources come
 * from another stage, not the context. Continuation lines are joined and a
 * finding names the line its instruction starts on; CRLF parses like LF.
 *
 * @return list<string>
 */
function sdse_dockerfile_context_escapes(string $dockerfile): array
{
    $problems = [];
    $lines = preg_split('/\R/', $dockerfile) ?: [];
    $continued = '';
    $continuedFrom = 0;

    foreach ($lines as $index => $raw) {
        $line = trim($raw);
        if ($continued === '' && ($line === '' || str_starts_with($line, '#'))) {
            continue;
        }
        if (str_ends_with($line, '\\')) {
            if ($continued === '') {
                $continuedFrom = $index + 1;
            }
            $continued .= rtrim(substr($line, 0, -1)) . ' ';
            continue;
        }
        $number = $continued === '' ? $index + 1 : $continuedFrom;
        $line = trim($continued . $line);
        $continued = '';

        if (preg_match('/^(COPY|ADD)\s+(.*)$/i', $line, $match) !== 1) {
            continue;
        }

        $instruction = strtoupper($match[1]);
        $arguments = preg_split('/\s+/', trim($match[2])) ?: [];
        $fromAnotherStage = false;
        while ($arguments !== [] && str_starts_with($arguments[0], '--')) {
            $flag = strtolower((string) array_shift($arguments));
            if (str_starts_with($flag, '--from=')) {
                $fromAnotherStage = true;
            }
        }
        // The destination is the final argument; everything before it is a source.
        array_pop($arguments);

        foreach ($arguments as $source) {
            if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $source) === 1) {
                $problems[] = sprintf(
                    'skeleton/Dockerfile line %d: %s fetches %s from outside the build context, so the '
                    . 'context inventory no longer bounds what can land in a layer.',
                    $number,
                    $instruction,
                    $source,
                );
                continue;
            }
            if ($fromAnotherStage) {
                continue;
            }
            $base = basename(rtrim($source, '/'));
            if ($base === '.env' || (str_starts_with($base, '.env.') && $base !== '.env.example')) {
                $problems[] = sprintf(
                    'skeleton/Dockerfile line %d: %s names %s explicitly, which would defeat the '
                    . '.dockerignore exclusion.',
                    $number,
                    $instruction,
                    $source,
                );
            }
        }
    }

    return $problems;
}

/**
 * The value post-create-setup.php generated for $key in .env. Anything that
 * does not look like generated material is a harness error, never a sentinel.
 *
 * @throws RuntimeException
 */
function sdse_generated_value(string $env, string $key): string
{
    if (preg_match('/^' . preg_quote($key, '/') . '=(.+)$/m', $env, $match) !== 1) {
        throw new RuntimeException("post-create-setup.php did not populate {$key} in .env.");
    }
    $value = trim($match[1]);
    if (strlen($value) < 16) {
        throw new RuntimeException(
            "{$key} does not look like generated material (got " . var_export($value, true) . ').',
        );
    }

    return $value;
}

/**
 * Classify Docker into exactly one of three states from the gate's two probes.
 * The kinds are kept distinct because only one of them may ever reach the
 * skippable exit-3 path.
 *
 * The caller has already proven that its launcher works, so a
 * `docker --version` that fails to START is a `docker` that is not on PATH:
 * genuinely 'unavailable'. Past that point this exact binary launched moments
 * ago from this exact PATH, so a `docker info` that fails to START cannot be
 * an absent daemon (an absent daemon still lets the CLI start, it just
 * answers with an error). It is a harness or launcher fault. Only a
 * `docker info` that actually RAN and exited non-zero is an unavailable
 * daemon. $info is invoked only once `docker --version` has answered.
 *
 * @param array{launched: bool, code: int} $version
 * @param callable(): array{launched: bool, code: int} $info
 *
 * @return array{kind: 'ok'|'unavailable'|'harness', reason: string}
 */
function sdse_classify_docker(array $version, callable $info): array
{
    if (!$version['launched']) {
        return [
            'kind' => 'unavailable',
            'reason' => 'the `docker` CLI is not on PATH (the launcher is known-good; `docker` itself did not start).',
        ];
    }
    if ($version['code'] !== 0) {
        return [
            'kind' => 'unavailable',
            'reason' => 'the `docker` CLI is on PATH but did not answer --version.',
        ];
    }

    $probe = $info();
    if (!$probe['launched']) {
        return [
            'kind' => 'harness',
            'reason' => '`docker info` could not be started, even though `docker --version` launched from the '
                . 'same PATH moments earlier. An absent daemon still lets the CLI start, so this is a harness '
                . 'or launcher fault, not an unavailable daemon.',
        ];
    }
    if ($probe['code'] !== 0) {
        return [
            'kind' => 'unavailable',
            'reason' => 'the `docker` CLI is present but no daemon answered `docker info`.',
        ];
    }

    return ['kind' => 'ok', 'reason' => ''];
}

/**
 * What a Docker classification forces before any inspection runs: the exit
 * code and the operator message, or null when the inspection may proceed.
 *
 * Only an unavailable daemon with --allow-missing-docker may exit 3. An
 * unavailable daemon without the flag is a hard failure, because this gate is
 * the only proof of #2647. A probe that could not be started, and any kind
 * this function does not know, is a harness error (exit 2) that the flag does
 * not reach: turning a broken harness into a pass-shaped skip is the defect
 * this gate exists to refuse.
 *
 * @param array{kind: string, reason: string} $docker
 *
 * @return array{exit: int, message: string}|null
 */
function sdse_docker_state_outcome(array $docker, bool $allowMissingDocker): ?array
{
    $reason = $docker['reason'];

    return match ($docker['kind']) {
        'ok' => null,
        'unavailable' => $allowMissingDocker
            ? [
                'exit' => SDSE_EXIT_NO_DOCKER,
                'message' => "Skeleton Docker secret gate: SKIPPED — {$reason}\n",
            ]
            : [
                'exit' => SDSE_EXIT_HARNESS,
                'message' => <<<TEXT
                    Skeleton Docker secret gate: Docker is REQUIRED and unavailable.

                      {$reason}

                    This gate is the only proof that a generated .env secret stays out of the
                    skeleton image. Reading skeleton/.dockerignore as text does not satisfy it.
                    Install or start Docker, or pass --allow-missing-docker to exit 3 instead
                    of failing. Hosted CI must never pass that flag.

                    TEXT,
            ],
        'harness' => [
            'exit' => SDSE_EXIT_HARNESS,
            'message' => <<<TEXT
                Skeleton Docker secret gate: a Docker probe could not be started.

                  {$reason}

                This is NOT a verdict that Docker is unavailable, and --allow-missing-docker
                does not apply to it. Fix the harness, then re-run.

                TEXT,
        ],
        default => [
            'exit' => SDSE_EXIT_HARNESS,
            'message' => sprintf(
                "Skeleton Docker secret gate: the Docker probe reported an unknown state %s.\n\n"
                . "This is NOT a verdict that Docker is unavailable, and --allow-missing-docker\n"
                . "does not apply to it. Fix the harness, then re-run.\n",
                var_export($docker['kind'], true),
            ),
        ],
    };
}

/**
 * Positive-control failures. With .dockerignore removed, every sentinel MUST
 * be observed on the image filesystem and in some saved layer, and some dotenv
 * file must reach the inventory; otherwise a clean subject would be the
 * harness failing to look, not the leak being closed.
 *
 * @param array{dotenvPaths: list<string>, rootfsHits: list<string>, layerHits: array<string, list<string>>} $control
 * @param array<string, string> $sentinels label => sentinel
 *
 * @return list<string>
 */
function sdse_control_failures(array $control, array $sentinels): array
{
    $failures = [];
    if ($control['dotenvPaths'] === []) {
        $failures[] = 'positive control: no dotenv file appeared in the build-context inventory even '
            . 'with .dockerignore removed. The inventory reader is broken and a PASS would be meaningless.';
    }
    foreach ($sentinels as $label => $sentinel) {
        if (!in_array($sentinel, $control['rootfsHits'], true)) {
            $failures[] = "positive control: {$label} was not observed in the image filesystem with "
                . '.dockerignore removed; the filesystem scan cannot detect a leak.';
        }
        if (($control['layerHits'][$sentinel] ?? []) === []) {
            $failures[] = "positive control: {$label} was not observed in any saved layer with "
                . '.dockerignore removed; the layer scan cannot detect a leak.';
        }
    }

    return $failures;
}

/**
 * Subject failures: the skeleton context exactly as shipped. No dotenv file
 * other than `.env.example` may reach the context, no sentinel may be
 * readable in the image filesystem or any saved layer, and `.env.example`
 * must survive the exclusion.
 *
 * @param array{inventoryPaths: list<string>, dotenvPaths: list<string>, rootfsHits: list<string>, layerHits: array<string, list<string>>} $subject
 * @param array<string, string> $sentinels label => sentinel
 *
 * @return list<string>
 */
function sdse_subject_failures(array $subject, array $sentinels): array
{
    $failures = [];
    foreach ($subject['dotenvPaths'] as $entry) {
        if (basename($entry) === '.env.example') {
            continue;
        }
        $failures[] = "build-context inventory: dotenv file reached the build context ({$entry}).";
    }

    foreach ($sentinels as $label => $sentinel) {
        if (in_array($sentinel, $subject['rootfsHits'], true)) {
            $failures[] = "image filesystem: {$label} is readable in the image rootfs.";
        }
        foreach ($subject['layerHits'][$sentinel] ?? [] as $blob) {
            $failures[] = "saved layers: {$label} is readable in layer blob {$blob}.";
        }
    }

    if (!in_array('.env.example', $subject['inventoryPaths'], true)) {
        $failures[] = '.env.example did not reach the build context; the intentional `!.env.example` '
            . 'negation in skeleton/.dockerignore has regressed.';
    }

    return $failures;
}
