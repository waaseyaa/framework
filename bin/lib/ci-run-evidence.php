<?php

declare(strict_types=1);

/**
 * Frozen evidence about real CI runs — collection, offline classification, and
 * deterministic reporting (FW-CI-CHECK-ROSTER-AUDIT-01, Task 4; measurement
 * owner #2869; GitHub mirror #3087).
 *
 * Three executables share this library:
 *
 *   bin/collect-ci-run-evidence   — the ONE live pass over GitHub. Resolves a
 *                                   frozen cohort spec into a dataset of runs,
 *                                   attempts, jobs and evidence state.
 *   bin/classify-ci-run-evidence  — an offline pass that adds classifications.
 *                                   It touches GitHub in exactly one place, and
 *                                   only under --with-logs: the job logs and
 *                                   test artifacts needed to decide whether a
 *                                   random-order shard failure was unique.
 *   bin/report-ci-measurement     — deterministic markdown over the frozen
 *                                   dataset. No network, no clock.
 *
 * Two rules govern every field this library writes:
 *
 *   1. Provenance is never upgraded. Every field is typed `observed` (read back
 *      verbatim from a GitHub API response) or `derived` (computed here), per
 *      docs/specs/delivery-telemetry.md. A missing value stays missing; it is
 *      never substituted, interpolated, or back-filled from a neighbour.
 *   2. Nothing is `derived` from a value that was not `observed` in the same
 *      dataset. Log- and artifact-derived facts therefore carry an `observed`
 *      fetch record (id, byte length, SHA-256, fetched_at) alongside them.
 *
 * Billed runner minutes are structurally unavailable: on a public repository
 * `GET /actions/runs/{id}/timing` reports `duration_ms: 0` per job, and
 * organization billing needs an `admin:org` scope this tooling does not hold.
 * Summed job-wall time multiplied by GitHub's published per-OS multiplier is
 * therefore recorded as a COST PROXY and labelled as one everywhere it appears.
 *
 * Plain functions with a `cre_` prefix. The GitHub transport is injected as a
 * callable so tests drive the whole pipeline from fixtures without a network.
 */

use Symfony\Component\Yaml\Yaml;

final class CiRunEvidenceFailure extends RuntimeException {}

const CRE_SCHEMA_VERSION = 1;
const CRE_COLLECTOR_VERSION = '1.0.0';
const CRE_CLASSIFIER_VERSION = '1.0.0';
const CRE_LOG_PARSER_VERSION = '1.0.0';
const CRE_REPORT_VERSION = '1.0.0';

/**
 * GitHub's published per-OS runner multipliers for private-repository billing.
 * They are used here ONLY to weight summed job-wall seconds into a comparable
 * cost proxy; this repository is public, so no multiplier corresponds to money
 * actually spent. The convention is recorded in the dataset next to the value.
 */
const CRE_RUNNER_MULTIPLIERS = [
    'ubuntu' => 1,
    'windows' => 2,
    'macos' => 10,
];

/**
 * Step names that mark a failure as setup or infrastructure rather than the
 * job's own execution. The list is bounded, literal, and emitted into the
 * dataset so the classification can be audited without this source. A failure
 * whose first failed step is NOT in this list is a root execution failure.
 */
const CRE_INFRASTRUCTURE_STEP_NAMES = [
    'Checkout',
    'Checkout repository',
    'Complete job',
    'Download the dependency archive',
    'Download the shard plan',
    'Download the random-order plan',
    'Initialize containers',
    'Install dependencies',
    'Install the pinned admin toolchain',
    'Post Checkout',
    'Restore the Composer cache',
    'Set up job',
    'Setup Node',
    'Setup PHP',
    'Stop containers',
];

/**
 * Classification precedence, first match wins. Emitted into the dataset so a
 * reader can see which rule claimed a job before the others were consulted.
 */
const CRE_CLASSIFICATION_PRECEDENCE = [
    'cancellation',
    'expected_conditional_skip',
    'unexpected_skip_or_missing_prerequisite',
    'derivative_aggregate_failure',
    'setup_or_infrastructure_failure',
    'root_execution_failure',
    'publication_only',
    'success',
    'unclassified',
];

// ---------------------------------------------------------------------------
// Transport
// ---------------------------------------------------------------------------

/**
 * How many times a TRANSIENT transport failure is retried before the pass
 * gives up, and the base of the exponential backoff between attempts.
 *
 * A several-hundred-call collection meets an occasional GitHub 5xx; treating
 * one as evidence of anything would be wrong, and aborting a whole cohort over
 * one would make the tool unusable. Only transient failures are retried, every
 * attempt is counted as a real API call, and any retry is recorded in the
 * dataset so a collection never reads as cleaner than it was.
 */
const CRE_MAX_TRANSIENT_RETRIES = 4;
const CRE_RETRY_BASE_DELAY_MS = 750;

/**
 * The live GitHub transport: `gh api <path>` through proc_open.
 *
 * `gh.exe` is a native Windows executable, so proc_open drives it directly.
 * (The historical proc_open trouble in this repository was the bash `bin/git`
 * adapter, not proc_open itself.) The array command form is used so no shell
 * quoting is involved on any platform.
 *
 * The returned callable takes an API path and returns
 * `array{status:int, body:string}`. On success the status is 200; on failure it
 * is the HTTP status parsed out of gh's own message when gh reported one, and 0
 * when it did not — a transport-level error such as a dropped connection.
 * Callers fail closed on anything that is not 200.
 *
 * @return callable(string, string): array{status:int, body:string}
 */
function cre_gh_runner(string $executable = 'gh'): callable
{
    return static function (string $path, string $mode) use ($executable): array {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open([$executable, 'api', $path], $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new CiRunEvidenceFailure("unable to start `{$executable} api {$path}`.");
        }
        // Binary-safe: log and zip bodies come back through the same pipe.
        $body = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        unset($mode);

        if ($exit === 0) {
            return ['status' => 200, 'body' => $body];
        }

        $detail = trim($error) !== '' ? trim($error) : trim(substr($body, 0, 400));
        $status = preg_match('/\bHTTP (\d{3})\b/', $detail, $match) === 1 ? (int) $match[1] : 0;

        return ['status' => $status, 'body' => $detail];
    };
}

/**
 * Whether a failed response is worth another attempt.
 *
 * A 5xx is the server saying "not now", and a transport-level failure with no
 * HTTP status at all — a dropped connection, a TLS reset, an unexpected EOF —
 * is the same kind of event. Everything else (404, 410, 403, 422) is the server
 * giving a real answer, and retrying it would turn a fact into noise. An
 * exhausted rate limit is never transient: it is a hard stop.
 */
function cre_is_transient_failure(int $status, string $body): bool
{
    if (stripos($body, 'rate limit') !== false) {
        return false;
    }
    if ($status >= 500) {
        return true;
    }
    if ($status !== 0) {
        return false;
    }

    return preg_match(
        '/server error|bad gateway|service unavailable|gateway time-?out|timed? ?out|'
        . 'connection (reset|refused|closed)|unexpected EOF|EOF occurred|TLS|temporarily unavailable|'
        . 'no such host|network is unreachable/i',
        $body,
    ) === 1;
}

/**
 * Performs one GET, retrying only transient failures.
 *
 * Every attempt is appended to the call log as its own entry — a retry IS an
 * extra API call and the dataset says so — and each entry records the attempt
 * number and the status it saw.
 *
 * @param callable(string, string): array{status:int, body:string} $runner
 * @param list<array{path:string, attempt:int, status:int}> $callLog
 * @return array{status:int, body:string}
 */
function cre_api_attempt(callable $runner, string $path, string $mode, array &$callLog): array
{
    $response = ['status' => 0, 'body' => 'no attempt was made'];
    for ($attempt = 1; $attempt <= CRE_MAX_TRANSIENT_RETRIES; ++$attempt) {
        $response = $runner($path, $mode);
        $status = (int) ($response['status'] ?? 0);
        $body = (string) ($response['body'] ?? '');
        $callLog[] = ['path' => $path, 'attempt' => $attempt, 'status' => $status];

        if ($status === 200) {
            return $response;
        }
        if (stripos($body, 'rate limit') !== false) {
            throw new CiRunEvidenceFailure("GitHub rate limit exhausted while fetching {$path}: {$body}");
        }
        if (!cre_is_transient_failure($status, $body) || $attempt === CRE_MAX_TRANSIENT_RETRIES) {
            return $response;
        }
        usleep(CRE_RETRY_BASE_DELAY_MS * 1000 * (2 ** ($attempt - 1)));
    }

    return $response;
}

/**
 * A fail-closed JSON GET. Any non-200 status that survives the transient-retry
 * budget, or an unparsable body, aborts the whole collection: a partial dataset
 * is worse than no dataset.
 *
 * @param callable(string, string): array{status:int, body:string} $runner
 * @return array<mixed>
 */
function cre_api_json(callable $runner, string $path, array &$callLog = []): array
{
    $response = cre_api_attempt($runner, $path, 'json', $callLog);
    if (($response['status'] ?? 0) !== 200) {
        throw new CiRunEvidenceFailure(sprintf(
            'GitHub request failed for %s (status %s): %s',
            $path,
            (string) ($response['status'] ?? 'none'),
            trim(substr((string) ($response['body'] ?? ''), 0, 400)),
        ));
    }
    try {
        $decoded = json_decode((string) $response['body'], true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new CiRunEvidenceFailure("malformed JSON from {$path}: " . $exception->getMessage());
    }
    if (!is_array($decoded)) {
        throw new CiRunEvidenceFailure("unexpected non-object JSON from {$path}.");
    }

    return $decoded;
}

/**
 * A raw GET, for job logs and artifact zips.
 *
 * Returns the body bytes, or null when the resource is genuinely gone — an
 * expired log or artifact is an evidence state, not an absent fact. A transient
 * failure that survives the retry budget is NOT gone, so it throws rather than
 * being recorded as an expiry that never happened.
 *
 * @param callable(string, string): array{status:int, body:string} $runner
 */
function cre_api_raw(callable $runner, string $path, array &$callLog = []): ?string
{
    $response = cre_api_attempt($runner, $path, 'raw', $callLog);
    $status = (int) ($response['status'] ?? 0);
    $body = (string) ($response['body'] ?? '');

    if ($status === 200) {
        return $body;
    }
    if (cre_is_transient_failure($status, $body)) {
        throw new CiRunEvidenceFailure(sprintf(
            'GitHub kept failing transiently for %s (status %s) after %d attempts; '
            . 'that is not an expiry and is not recorded as one: %s',
            $path,
            (string) $status,
            CRE_MAX_TRANSIENT_RETRIES,
            trim(substr($body, 0, 200)),
        ));
    }

    return null;
}

/**
 * Summarises a call log into the transport facts the dataset records: how many
 * requests were actually made, and how many of them were retries of a transient
 * failure.
 *
 * @param list<array{path:string, attempt:int, status:int}> $callLog
 * @return array{api_calls:int, retried_requests:int, retried_paths:list<string>}
 */
function cre_transport_summary(array $callLog): array
{
    $retriedPaths = [];
    $retries = 0;
    foreach ($callLog as $entry) {
        if (($entry['attempt'] ?? 1) > 1) {
            ++$retries;
            $retriedPaths[] = (string) $entry['path'];
        }
    }
    $retriedPaths = array_values(array_unique($retriedPaths));
    sort($retriedPaths, SORT_STRING);

    return [
        'api_calls' => count($callLog),
        'retried_requests' => $retries,
        'retried_paths' => $retriedPaths,
    ];
}

// ---------------------------------------------------------------------------
// Structural fingerprint
// ---------------------------------------------------------------------------

/**
 * The comparability signature of one inventory job: identity, expansion,
 * literal matrix shape, dependency edges, aggregate gate token, and visible
 * contexts. Deliberately excludes everything a reformat, a comment, a runner
 * bump or a step edit would change — comparability is job STRUCTURE, not
 * workflow bytes.
 *
 * @param array<string, mixed> $job
 * @return array<string, mixed>
 */
function cre_job_signature(array $job): array
{
    $needs = array_values(array_map('strval', (array) ($job['needs'] ?? [])));
    sort($needs, SORT_STRING);

    $contexts = [];
    foreach ((array) ($job['contexts'] ?? []) as $context) {
        $contexts[] = $context['context'] ?? null;
    }
    sort($contexts, SORT_STRING);

    $matrix = null;
    if (is_array($job['matrix'] ?? null)) {
        $matrix = [
            'resolution' => $job['matrix']['resolution'] ?? null,
            // A literal matrix contributes its axes and values; an unresolved
            // one contributes its raw expression, so widening an expression
            // matrix still changes the fingerprint.
            'axes' => $job['matrix']['axes'] ?? null,
            'raw_expression' => $job['matrix']['raw_expression'] ?? null,
        ];
    }

    return [
        'key' => (string) ($job['key'] ?? ''),
        'expansion' => $job['expansion'] ?? null,
        'matrix' => $matrix,
        'needs' => $needs,
        'aggregate_gate' => $job['aggregate']['gate'] ?? null,
        'contexts' => $contexts,
    ];
}

/**
 * SHA-256 over the canonical signature of every job in one workflow of a
 * generated inventory document.
 *
 * @param array<string, mixed> $inventory a `cwi_build_inventory` document
 */
function cre_fingerprint(array $inventory, string $workflowFile): string
{
    foreach ((array) ($inventory['workflows'] ?? []) as $workflow) {
        if (($workflow['file'] ?? null) !== $workflowFile) {
            continue;
        }
        $signatures = [];
        foreach ((array) ($workflow['jobs'] ?? []) as $job) {
            $signatures[(string) $job['key']] = cre_job_signature($job);
        }
        ksort($signatures, SORT_STRING);

        return hash('sha256', json_encode($signatures, JSON_THROW_ON_ERROR));
    }

    throw new CiRunEvidenceFailure("inventory contains no workflow named {$workflowFile}.");
}

/**
 * Fingerprints one workflow file's source bytes by composing a throwaway root
 * that holds only that file and running THIS repository's Task 2 generator
 * over it. The generator is the only workflow parser in the pipeline; this
 * function never interprets YAML itself beyond a syntax pre-check.
 */
function cre_fingerprint_source(
    string $repositoryRoot,
    string $scratchDirectory,
    string $workflowFile,
    string $source,
): string {
    $root = rtrim(str_replace('\\', '/', $scratchDirectory), '/') . '/fp-' . substr(hash('sha256', $source), 0, 16);
    $workflows = $root . '/.github/workflows';
    if (!is_dir($workflows) && !@mkdir($workflows, 0o777, true) && !is_dir($workflows)) {
        throw new CiRunEvidenceFailure("unable to create fingerprint scratch root {$workflows}.");
    }
    if (file_put_contents($workflows . '/' . $workflowFile, $source) === false) {
        throw new CiRunEvidenceFailure("unable to stage {$workflowFile} under {$workflows}.");
    }

    // A pre-check with the same parser the generator uses, so an unparsable
    // historical revision fails with a useful message rather than as generator
    // noise on stderr.
    try {
        Yaml::parse($source);
    } catch (Throwable $exception) {
        throw new CiRunEvidenceFailure("unparsable {$workflowFile}: " . $exception->getMessage());
    }

    $generator = rtrim(str_replace('\\', '/', $repositoryRoot), '/') . '/bin/generate-ci-workflow-inventory';
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = @proc_open([PHP_BINARY, $generator, '--root=' . $root], $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new CiRunEvidenceFailure('unable to start the workflow inventory generator.');
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        throw new CiRunEvidenceFailure("inventory generator failed on {$workflowFile}: " . trim($stderr));
    }

    try {
        $document = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new CiRunEvidenceFailure('inventory generator emitted malformed JSON: ' . $exception->getMessage());
    }

    return cre_fingerprint((array) $document, $workflowFile);
}

/**
 * Maps every visible context in a generated inventory to the single job that
 * produces it. A context produced by more than one job maps to null with the
 * colliding jobs recorded, so a hosted job name is never silently attributed.
 *
 * @param array<string, mixed> $inventory
 * @return array<string, array{key:string|null, workflow:string|null, matrix:array<string,mixed>|null, reason:string|null, candidates:list<string>}>
 */
function cre_context_map(array $inventory): array
{
    $byContext = [];
    foreach ((array) ($inventory['workflows'] ?? []) as $workflow) {
        foreach ((array) ($workflow['jobs'] ?? []) as $job) {
            foreach ((array) ($job['contexts'] ?? []) as $context) {
                if (($context['context'] ?? null) === null) {
                    continue;
                }
                $byContext[(string) $context['context']][] = [
                    'workflow' => (string) $workflow['file'],
                    'key' => (string) $job['key'],
                    'matrix' => $context['matrix'] ?? null,
                ];
            }
        }
    }

    $map = [];
    foreach ($byContext as $context => $candidates) {
        $identities = array_values(array_unique(array_map(
            static fn(array $candidate): string => $candidate['workflow'] . '#' . $candidate['key'],
            $candidates,
        )));
        if (count($identities) > 1) {
            $map[(string) $context] = [
                'key' => null,
                'workflow' => null,
                'matrix' => null,
                'reason' => 'context-produced-by-more-than-one-job',
                'candidates' => $identities,
            ];
            continue;
        }
        $map[(string) $context] = [
            'key' => $candidates[0]['key'],
            'workflow' => $candidates[0]['workflow'],
            'matrix' => $candidates[0]['matrix'],
            'reason' => null,
            'candidates' => $identities,
        ];
    }
    ksort($map, SORT_STRING);

    return $map;
}

/**
 * Indexes the inventory's job records by `<workflow>#<key>` so the classifier
 * can read `needs`, `aggregate`, `if` and `structural_role` for a hosted job.
 *
 * @param array<string, mixed> $inventory
 * @return array<string, array<string, mixed>>
 */
function cre_inventory_jobs(array $inventory): array
{
    $jobs = [];
    foreach ((array) ($inventory['workflows'] ?? []) as $workflow) {
        foreach ((array) ($workflow['jobs'] ?? []) as $job) {
            $jobs[$workflow['file'] . '#' . $job['key']] = $job;
        }
    }
    ksort($jobs, SORT_STRING);

    return $jobs;
}

// ---------------------------------------------------------------------------
// Derived scalars
// ---------------------------------------------------------------------------

/**
 * Whole seconds between two ISO-8601 instants, or null when either is missing.
 * Never negative: GitHub occasionally reports a step completing in the same
 * second it started, and a clock artefact must not become a negative duration.
 */
function cre_seconds_between(?string $from, ?string $to): ?int
{
    if ($from === null || $to === null || $from === '' || $to === '') {
        return null;
    }
    $start = strtotime($from);
    $end = strtotime($to);
    if ($start === false || $end === false) {
        return null;
    }

    return max(0, $end - $start);
}

/**
 * GitHub's published multiplier for a runner label set. Unknown labels report
 * multiplier 1 with `matched: false`, so an unrecognised runner inflates
 * nothing and is visible in the dataset.
 *
 * @param list<string> $labels
 * @return array{multiplier:int, matched:bool, os:string|null}
 */
function cre_runner_multiplier(array $labels): array
{
    foreach ($labels as $label) {
        $lower = strtolower((string) $label);
        foreach (CRE_RUNNER_MULTIPLIERS as $os => $multiplier) {
            if (str_contains($lower, $os)) {
                return ['multiplier' => $multiplier, 'matched' => true, 'os' => $os];
            }
        }
    }

    return ['multiplier' => 1, 'matched' => false, 'os' => null];
}

/**
 * Nearest-rank quantile over a numeric sample: sort ascending, take the
 * element at 1-based index ceil(q * n). No interpolation, so every reported
 * value is a value that actually occurred in the sample.
 *
 * @param list<int|float> $values
 */
function cre_nearest_rank(array $values, float $quantile): int|float|null
{
    $values = array_values(array_filter($values, static fn($value): bool => $value !== null));
    if ($values === []) {
        return null;
    }
    sort($values);
    $rank = (int) ceil($quantile * count($values));

    return $values[max(1, min(count($values), $rank)) - 1];
}

// ---------------------------------------------------------------------------
// Collection
// ---------------------------------------------------------------------------

/**
 * Resolves a frozen cohort spec into a complete evidence dataset.
 *
 * Every attempt of every kept run is collected (`jobs?filter=all`), every
 * skipped run is recorded with its reason, and the whole pass fails closed:
 * one bad response aborts before anything is written.
 *
 * @param array<string, mixed> $spec the cohort spec document
 * @param callable(string, string): array{status:int, body:string} $runner
 * @param array{repository_root:string, scratch:string, inventory:array<string,mixed>, collected_at:string, refetch:list<int>|null} $context
 * @return array<string, mixed>
 */
function cre_collect(array $spec, callable $runner, array $context): array
{
    $repository = (string) $spec['repository'];
    $callLog = [];
    $fingerprints = [];
    $headFingerprints = [];

    // Two roots, deliberately distinct. `repository_root` is only where the
    // REFERENCE workflow is read from, so a test can point it at a throwaway
    // fixture tree. The generator always comes from THIS repository — the
    // fingerprint is defined as a reduction of this generator's output, and a
    // fixture tree carries no bin/ directory.
    $generatorRoot = dirname(__DIR__, 2);

    // The comparability reference: the fingerprint of each workflow as it
    // stands in the checked-out tree.
    foreach ((array) $spec['fingerprint']['workflows'] as $workflowFile) {
        $path = rtrim(str_replace('\\', '/', $context['repository_root']), '/') . '/.github/workflows/' . $workflowFile;
        $source = @file_get_contents($path);
        if ($source === false) {
            throw new CiRunEvidenceFailure("unable to read the reference workflow {$path}.");
        }
        $headFingerprints[$workflowFile] = cre_fingerprint_source(
            $generatorRoot,
            $context['scratch'],
            $workflowFile,
            str_replace("\r\n", "\n", $source),
        );
    }

    $runs = [];
    $jobs = [];
    $evidenceState = [];
    $incompleteRuns = [];
    $skipped = [];

    $resolved = [];
    $refetch = $context['refetch'];

    // A refetch refreshes the observed run and job facts for a fixed id list;
    // it does not rebuild the cohort. The walk's skip accounting is part of the
    // evidence for HOW the cohort was chosen, so it is carried forward from the
    // dataset being refreshed rather than silently emptied — an empty list
    // would read as "nothing was skipped", which is a different claim.
    $skippedSource = ['mode' => $refetch === null ? 'this-walk' : 'not-available'];
    $previousCohort = $context['previous_cohort'] ?? null;
    if ($refetch !== null && is_array($previousCohort) && ($previousCohort['skipped_runs'] ?? null) !== null) {
        $skipped = $previousCohort['skipped_runs'];
        $skippedSource = [
            'mode' => 'carried-forward',
            'from_collected_at' => $previousCohort['collected_at'] ?? null,
            'note' => 'A refetch replays a committed run-id list and never walks a listing, so this '
                . 'accounting is the original walk\'s, carried forward unchanged, not a fresh derivation.',
        ];
    }

    foreach ((array) $spec['cohorts'] as $cohortId => $cohort) {
        $kept = [];
        $red = 0;
        $page = 1;
        $scanned = 0;
        $workflowFile = (string) $cohort['workflow'];
        $reference = $headFingerprints[$workflowFile] ?? null;
        $enforce = (bool) ($cohort['enforce_fingerprint'] ?? true);
        // An exhaustive cohort is "all of them", not "at least N": the walk
        // never stops early, and it is complete only when the listing itself
        // ran out rather than when a page budget did.
        $exhaustive = (bool) ($cohort['exhaustive'] ?? false);
        $listingExhausted = false;
        // Pagination integrity state: the lowest run id of the previous page,
        // and every run id this cohort has already scanned.
        $previousPageMinimum = null;
        $seenIds = [];

        while (true) {
            if ($refetch !== null) {
                // Reproducibility mode: the committed run-id list IS the query,
                // so it is complete by definition and no listing is walked.
                $listingExhausted = true;
                $candidates = [];
                foreach ((array) $cohort['resolved_run_ids'] as $runId) {
                    $candidates[] = cre_api_json($runner, "repos/{$repository}/actions/runs/{$runId}", $callLog);
                }
            } else {
                $query = cre_run_query($cohort, $page);
                $url = "repos/{$repository}/actions/workflows/{$workflowFile}/runs?{$query}";

                // Fetch the page, and refuse to build a cohort on a listing
                // that does not strictly advance. A page that repeats or goes
                // backwards is refetched; if it keeps doing so the collection
                // fails closed rather than freezing a cohort with a hole in it.
                $violation = null;
                for ($attempt = 0; $attempt <= CRE_MAX_LISTING_REFETCHES; ++$attempt) {
                    $listing = cre_api_json($runner, $url, $callLog);
                    $candidates = (array) ($listing['workflow_runs'] ?? []);
                    $violation = cre_listing_page_violation($candidates, $previousPageMinimum, $seenIds);
                    if ($violation === null) {
                        break;
                    }
                    usleep(CRE_RETRY_BASE_DELAY_MS * 1000 * (2 ** $attempt));
                }
                if ($violation !== null) {
                    throw new CiRunEvidenceFailure(sprintf(
                        'inconsistent run listing for cohort %s at page %d (%s): %s. '
                        . 'The cohort would silently miss runs, so nothing is written.',
                        (string) $cohortId,
                        $page,
                        $url,
                        $violation,
                    ));
                }

                if ($candidates === []) {
                    $listingExhausted = true;
                    break;
                }

                $pageIds = [];
                foreach ($candidates as $candidate) {
                    $pageIds[] = (int) $candidate['id'];
                    $seenIds[(int) $candidate['id']] = true;
                }
                $previousPageMinimum = min($pageIds);

                if (count($candidates) < 100) {
                    // A short page is the last page; no further request is made
                    // and the listing is complete.
                    $listingExhausted = true;
                }
            }

            foreach ($candidates as $run) {
                ++$scanned;
                $runId = (int) $run['id'];
                $conclusion = (string) ($run['conclusion'] ?? '');

                if ($refetch === null) {
                    if (in_array($conclusion, (array) ($cohort['skip_conclusions'] ?? []), true)) {
                        $skipped[] = [
                            'cohort' => (string) $cohortId,
                            'run_id' => $runId,
                            'conclusion' => $conclusion,
                            'reason' => $conclusion === 'cancelled' ? 'cancelled-or-superseded' : $conclusion,
                        ];
                        continue;
                    }
                }

                $headSha = (string) $run['head_sha'];
                $contents = cre_api_json(
                    $runner,
                    "repos/{$repository}/contents/.github/workflows/{$workflowFile}?ref={$headSha}",
                    $callLog,
                );
                $blobSha = (string) $contents['sha'];
                if (!isset($fingerprints[$blobSha])) {
                    $source = base64_decode(str_replace("\n", '', (string) $contents['content']), true);
                    if ($source === false) {
                        throw new CiRunEvidenceFailure("undecodable {$workflowFile} content at {$headSha}.");
                    }
                    $fingerprints[$blobSha] = cre_fingerprint_source(
                        $generatorRoot,
                        $context['scratch'],
                        $workflowFile,
                        str_replace("\r\n", "\n", $source),
                    );
                }
                $fingerprint = $fingerprints[$blobSha];

                if ($refetch === null && $enforce && $reference !== null && $fingerprint !== $reference) {
                    $skipped[] = [
                        'cohort' => (string) $cohortId,
                        'run_id' => $runId,
                        'conclusion' => $conclusion,
                        'reason' => 'fingerprint-mismatch',
                        'ci_yml_blob_sha' => $blobSha,
                        'fingerprint' => $fingerprint,
                    ];
                    continue;
                }

                $collected = cre_collect_run(
                    $runner,
                    $repository,
                    $run,
                    $blobSha,
                    $fingerprint,
                    (string) $context['collected_at'],
                    $callLog,
                );
                foreach ($collected['runs'] as $attemptRecord) {
                    $runs[] = $attemptRecord;
                }
                foreach ($collected['jobs'] as $jobRecord) {
                    $jobs[] = $jobRecord;
                }
                foreach ($collected['incomplete_runs'] as $incompleteRecord) {
                    $incompleteRecord['cohort'] = (string) $cohortId;
                    $incompleteRuns[] = $incompleteRecord;
                }
                $evidenceState[] = $collected['evidence_state'];
                $kept[] = $runId;
                if ($conclusion === 'failure') {
                    ++$red;
                }

                if ($refetch === null && cre_cohort_satisfied($cohort, count($kept), $red)) {
                    break;
                }
            }

            if ($refetch !== null) {
                break;
            }
            if ($listingExhausted || cre_cohort_satisfied($cohort, count($kept), $red)) {
                break;
            }
            ++$page;
            if ($page > (int) ($cohort['max_pages'] ?? 10)) {
                break;
            }
        }

        $previousResolved = $previousCohort['resolved'][(string) $cohortId] ?? null;
        $resolved[(string) $cohortId] = [
            'workflow' => $workflowFile,
            'reference_fingerprint' => $reference,
            'enforce_fingerprint' => $enforce,
            'run_ids' => $kept,
            'run_count' => count($kept),
            'failure_count' => $red,
            // In refetch mode nothing is scanned, so the walk's own figure is
            // carried forward rather than replaced by the size of the replayed
            // list, which would understate how much was examined.
            'runs_scanned' => $refetch !== null && is_array($previousResolved)
                ? (int) ($previousResolved['runs_scanned'] ?? $scanned)
                : $scanned,
            'exhaustive' => $exhaustive,
            'listing_exhausted' => $listingExhausted,
            // For an exhaustive cohort the target is a floor, not a count: it
            // is met when the whole listing was consumed AND the floor holds.
            'target_met' => cre_cohort_floor_met($cohort, count($kept), $red, $listingExhausted),
        ];
    }

    // Stamp each run record with the cohort that claimed it.
    $cohortByRun = [];
    foreach ($resolved as $cohortId => $summary) {
        foreach ($summary['run_ids'] as $runId) {
            $cohortByRun[$runId] = $cohortId;
        }
    }
    foreach ($runs as $index => $run) {
        $runs[$index]['cohort'] = $cohortByRun[$run['run_id']] ?? null;
    }
    foreach ($jobs as $index => $job) {
        $jobs[$index]['cohort'] = $cohortByRun[$job['run_id']] ?? null;
    }

    usort($runs, static fn(array $a, array $b): int => [$a['run_id'], $a['run_attempt']] <=> [$b['run_id'], $b['run_attempt']]);
    usort($jobs, static fn(array $a, array $b): int => [$a['run_id'], $a['run_attempt'], $a['job_id']] <=> [$b['run_id'], $b['run_attempt'], $b['job_id']]);
    usort($skipped, static fn(array $a, array $b): int => [$a['cohort'], $a['run_id']] <=> [$b['cohort'], $b['run_id']]);
    usort(
        $incompleteRuns,
        static fn(array $a, array $b): int => [$a['run_id'], $a['attempt']] <=> [$b['run_id'], $b['attempt']],
    );
    usort($evidenceState, static fn(array $a, array $b): int => $a['run_id'] <=> $b['run_id']);

    return [
        'schema_version' => CRE_SCHEMA_VERSION,
        'kind' => 'ci-run-evidence-dataset',
        'change_record' => 'FW-CI-CHECK-ROSTER-AUDIT-01',
        'measurement_owner' => 'waaseyaa/framework#2869',
        'statement' => 'Frozen evidence about real CI runs, collected once from GitHub and classified offline. '
            . 'Every field is typed observed or derived in `provenance`; nothing is derived from a value that '
            . 'was not observed in this dataset. Billed runner minutes are structurally unavailable, so summed '
            . 'job-wall time weighted by GitHub\'s published runner multipliers is recorded as a cost proxy.',
        'collector' => array_merge([
            'tool' => 'bin/collect-ci-run-evidence',
            'version' => CRE_COLLECTOR_VERSION,
            'collected_at' => $context['collected_at'],
        ], cre_transport_summary($callLog)),
        'cohort' => [
            'spec' => $spec,
            // How this dataset was built. A `walk` derives the cohort by
            // paginating the run listings and therefore knows what it passed
            // over; a `refetch` replays an already-committed run-id list and
            // never walks, so it cannot re-derive that accounting and must not
            // be read as having found nothing to skip.
            'collection_mode' => $refetch === null ? 'walk' : 'refetch',
            'reference_fingerprints' => $headFingerprints,
            'resolved' => $resolved,
            'skipped_runs' => $skipped,
            'skipped_runs_source' => $skippedSource,
            'incomplete_runs' => $incompleteRuns,
        ],
        'runs' => $runs,
        'jobs' => $jobs,
        'evidence_state' => $evidenceState,
        'provenance' => cre_provenance(),
    ];
}

/**
 * How many times an inconsistent run-listing page is refetched before the
 * collection gives up on it.
 */
const CRE_MAX_LISTING_REFETCHES = 2;

/**
 * Checks that a run-listing page genuinely continues the previous one.
 *
 * A workflow run listing is strictly descending by run id, so page N+1's
 * highest id must be below page N's lowest, and no run may appear twice in one
 * walk. This is not a theoretical guard: a `page=1` request has been observed
 * returning page 2's content, which silently cost a whole cohort its most
 * recent hundred runs while every other signal — call count, exit status, page
 * count — looked perfectly healthy. Only the run ids gave it away.
 *
 * Returns null when the page is consistent, or a message naming the violation.
 *
 * @param list<array<string, mixed>> $candidates
 * @param array<int, true> $seenIds run ids already scanned in this cohort
 */
function cre_listing_page_violation(array $candidates, ?int $previousMinimum, array $seenIds): ?string
{
    $ids = [];
    foreach ($candidates as $run) {
        $ids[] = (int) $run['id'];
    }
    if ($ids === []) {
        return null;
    }

    $repeated = [];
    foreach ($ids as $id) {
        if (isset($seenIds[$id])) {
            $repeated[] = $id;
        }
    }
    if ($repeated !== []) {
        return sprintf(
            'the page repeats %d run id(s) already scanned in this cohort (for example %d); '
            . 'the listing is not advancing',
            count($repeated),
            $repeated[0],
        );
    }

    if ($previousMinimum !== null && max($ids) >= $previousMinimum) {
        return sprintf(
            'the page is not below the previous one: its highest run id %d is not less than the '
            . 'previous page\'s lowest %d, so the listing went backwards or repeated',
            max($ids),
            $previousMinimum,
        );
    }

    return null;
}

/**
 * Whether a cohort has met both of its floors. A cohort with no failure floor
 * is satisfied on run count alone.
 *
 * @param array<string, mixed> $cohort
 */
function cre_cohort_satisfied(array $cohort, int $kept, int $red): bool
{
    if (($cohort['exhaustive'] ?? false) === true) {
        // "All of them" is never satisfied early; the walk stops only when the
        // listing runs out.
        return false;
    }

    return cre_cohort_floor($cohort, $kept, $red);
}

/**
 * The cohort's own floor, independent of when the walk stopped.
 *
 * @param array<string, mixed> $cohort
 */
function cre_cohort_floor(array $cohort, int $kept, int $red): bool
{
    return $kept >= (int) $cohort['target_runs'] && $red >= (int) ($cohort['target_failures'] ?? 0);
}

/**
 * Whether a cohort ended up complete. An ordinary cohort is complete when its
 * floor is met; an exhaustive cohort additionally requires that the listing —
 * not a page budget — is what ended the walk, so a truncated exhaustive cohort
 * can never read as complete.
 *
 * @param array<string, mixed> $cohort
 */
function cre_cohort_floor_met(array $cohort, int $kept, int $red, bool $listingExhausted): bool
{
    if (($cohort['exhaustive'] ?? false) === true) {
        return $listingExhausted && cre_cohort_floor($cohort, $kept, $red);
    }

    return cre_cohort_floor($cohort, $kept, $red);
}

/**
 * The frozen query string for one cohort page. Emitted into the dataset with
 * the spec, so the listing that produced the cohort is auditable.
 *
 * @param array<string, mixed> $cohort
 */
function cre_run_query(array $cohort, int $page): string
{
    $parameters = ['status' => 'completed', 'per_page' => '100', 'page' => (string) $page];
    foreach (['event', 'branch'] as $key) {
        if (($cohort[$key] ?? null) !== null) {
            $parameters[$key] = (string) $cohort[$key];
        }
    }
    ksort($parameters, SORT_STRING);
    $pairs = [];
    foreach ($parameters as $key => $value) {
        $pairs[] = $key . '=' . rawurlencode($value);
    }

    return implode('&', $pairs);
}

/**
 * Collects one run: its attempts, every job of every attempt, the pull request
 * it belongs to, and the evidence state of its logs and artifacts.
 *
 * @param callable(string, string): array{status:int, body:string} $runner
 * @param array<string, mixed> $run
 * @return array{runs:list<array<string,mixed>>, jobs:list<array<string,mixed>>, incomplete_runs:list<array<string,mixed>>, evidence_state:array<string,mixed>}
 */
function cre_collect_run(
    callable $runner,
    string $repository,
    array $run,
    string $blobSha,
    string $fingerprint,
    string $checkedAt,
    array &$callLog,
): array {
    $runId = (int) $run['id'];
    $headSha = (string) $run['head_sha'];

    // `run.pull_requests[]` empties once a pull request is merged or closed, so
    // the commit-to-pulls endpoint is the fallback. Which path answered is
    // recorded rather than inferred.
    $prNumber = null;
    $prSource = 'none';
    if (($run['pull_requests'] ?? []) !== []) {
        $prNumber = (int) $run['pull_requests'][0]['number'];
        $prSource = 'run_payload';
    } else {
        $pulls = cre_api_json($runner, "repos/{$repository}/commits/{$headSha}/pulls", $callLog);
        if (isset($pulls[0]['number'])) {
            $prNumber = (int) $pulls[0]['number'];
            $prSource = 'commit_pulls';
        }
    }

    $apiJobs = [];
    $page = 1;
    while (true) {
        $response = cre_api_json(
            $runner,
            "repos/{$repository}/actions/runs/{$runId}/jobs?filter=all&per_page=100&page={$page}",
            $callLog,
        );
        $batch = (array) ($response['jobs'] ?? []);
        array_push($apiJobs, ...$batch);
        if (count($batch) < 100) {
            break;
        }
        ++$page;
        if ($page > 5) {
            break;
        }
    }

    $jobRecords = [];
    $byAttempt = [];
    foreach ($apiJobs as $job) {
        $attempt = (int) ($job['run_attempt'] ?? 1);
        $labels = array_values(array_map('strval', (array) ($job['labels'] ?? [])));
        $multiplier = cre_runner_multiplier($labels);
        $steps = (array) ($job['steps'] ?? []);
        $failed = [];
        foreach ($steps as $step) {
            if (in_array($step['conclusion'] ?? null, ['failure', 'timed_out'], true)) {
                $failed[] = [
                    'number' => (int) ($step['number'] ?? 0),
                    'name' => (string) ($step['name'] ?? ''),
                    'conclusion' => (string) ($step['conclusion'] ?? ''),
                ];
            }
        }
        usort($failed, static fn(array $a, array $b): int => $a['number'] <=> $b['number']);

        $record = [
            'run_id' => $runId,
            'run_attempt' => $attempt,
            'job_id' => (int) $job['id'],
            'name' => (string) $job['name'],
            'key' => null,
            'key_reason' => null,
            'matrix' => null,
            'runner_labels' => $labels,
            'runner_multiplier' => $multiplier['multiplier'],
            'runner_multiplier_matched' => $multiplier['matched'],
            'created_at' => $job['created_at'] ?? null,
            'started_at' => $job['started_at'] ?? null,
            'completed_at' => $job['completed_at'] ?? null,
            'conclusion' => $job['conclusion'] ?? null,
            'queue_seconds' => cre_seconds_between($job['created_at'] ?? null, $job['started_at'] ?? null),
            'wall_seconds' => cre_seconds_between($job['started_at'] ?? null, $job['completed_at'] ?? null),
            'steps' => [
                'total' => count($steps),
                'failed' => $failed,
                'first_failed' => $failed[0] ?? null,
            ],
        ];
        $jobRecords[] = $record;
        $byAttempt[$attempt][] = $record;
    }

    $runRecords = [];
    foreach ($byAttempt as $attempt => $attemptJobs) {
        $created = array_values(array_filter(array_column($attemptJobs, 'created_at')));
        $started = array_values(array_filter(array_column($attemptJobs, 'started_at')));
        $completed = array_values(array_filter(array_column($attemptJobs, 'completed_at')));
        sort($created);
        sort($started);
        sort($completed);

        $runRecords[] = [
            'repository' => $repository,
            'workflow' => (string) ($run['path'] ?? ''),
            'event' => (string) ($run['event'] ?? ''),
            'run_id' => $runId,
            'run_attempt' => (int) $attempt,
            'latest_attempt' => (int) ($run['run_attempt'] ?? 1),
            'head_sha' => $headSha,
            'pr_number' => $prNumber,
            'pr_source' => $prSource,
            'ci_yml_blob_sha' => $blobSha,
            'fingerprint' => $fingerprint,
            'created_at' => $run['created_at'] ?? null,
            'run_started_at' => $run['run_started_at'] ?? null,
            'updated_at' => $run['updated_at'] ?? null,
            'conclusion' => $run['conclusion'] ?? null,
            // Attempt-scoped and derived from this attempt's observed job
            // timestamps: run-level `run_started_at`/`updated_at` describe only
            // the LATEST attempt and would misattribute a rerun.
            'queue_seconds' => cre_seconds_between($created[0] ?? null, $started[0] ?? null),
            'wall_seconds' => cre_seconds_between($created[0] ?? null, $completed[count($completed) - 1] ?? null),
            'job_count' => count($attemptJobs),
        ];
    }

    // GitHub can report a completed run for which an attempt has NO job records
    // at all: a run that failed before any job was created, or a rerun whose
    // earlier attempt's jobs are no longer retained (`filter=all` returns only
    // what GitHub still holds, which is not always every attempt). Building run
    // records from the jobs listing alone would make such a run vanish from
    // `runs[]` and `jobs[]` entirely while it remained in `resolved.run_ids` —
    // a resolved run silently absent from the evidence. Emit a record built
    // from the run object itself instead, marked with `jobs_listed: 0`, and
    // name the gap in `incomplete_runs` so it is counted rather than lost.
    $incomplete = [];
    $latestAttempt = (int) ($run['run_attempt'] ?? 1);
    $attemptsWithJobs = array_map('intval', array_keys($byAttempt));

    $missingAttempts = [];
    if ($runRecords === []) {
        $missingAttempts[] = $latestAttempt;
    }
    if (!in_array(1, $attemptsWithJobs, true) && $latestAttempt > 1) {
        $missingAttempts[] = 1;
    }
    foreach (array_values(array_unique($missingAttempts)) as $attempt) {
        // The run object's `conclusion` describes the LATEST attempt, so it
        // must never be copied onto an earlier one: attempt 1 of a rerun run
        // whose attempt 2 succeeded has been observed concluding
        // `action_required` — it never executed, which is exactly why it has no
        // jobs. The per-attempt endpoint is the only honest source for that
        // attempt's own conclusion and timestamps, so it is read rather than
        // guessed, and stays `observed` rather than becoming an inference.
        $attemptRun = $run;
        if ($attempt !== $latestAttempt) {
            $attemptRun = cre_api_json(
                $runner,
                "repos/{$repository}/actions/runs/{$runId}/attempts/{$attempt}",
                $callLog,
            );
        }

        $runRecords[] = [
            'repository' => $repository,
            'workflow' => (string) ($run['path'] ?? ''),
            'event' => (string) ($run['event'] ?? ''),
            'run_id' => $runId,
            'run_attempt' => $attempt,
            'latest_attempt' => $latestAttempt,
            'head_sha' => $headSha,
            'pr_number' => $prNumber,
            'pr_source' => $prSource,
            'ci_yml_blob_sha' => $blobSha,
            'fingerprint' => $fingerprint,
            'created_at' => $attemptRun['created_at'] ?? null,
            'run_started_at' => $attemptRun['run_started_at'] ?? null,
            'updated_at' => $attemptRun['updated_at'] ?? null,
            'conclusion' => $attemptRun['conclusion'] ?? null,
            // No job timestamps exist to derive these from. They stay null
            // rather than borrowing a number from a different attempt.
            'queue_seconds' => null,
            'wall_seconds' => null,
            'job_count' => 0,
            'jobs_listed' => 0,
        ];
        $incomplete[] = [
            'run_id' => $runId,
            'attempt' => $attempt,
            'conclusion' => $attemptRun['conclusion'] ?? null,
            'latest_attempt' => $latestAttempt,
            'reason' => $attempt === $latestAttempt && $attemptsWithJobs === []
                ? 'github-listed-no-jobs-for-this-completed-run'
                : 'github-lists-no-jobs-for-this-earlier-attempt',
        ];
    }
    foreach ($runRecords as $index => $record) {
        $runRecords[$index]['jobs_listed'] = $record['jobs_listed'] ?? $record['job_count'];
    }

    usort($runRecords, static fn(array $a, array $b): int => $a['run_attempt'] <=> $b['run_attempt']);

    $artifacts = cre_api_json($runner, "repos/{$repository}/actions/runs/{$runId}/artifacts?per_page=100", $callLog);
    $artifactRecords = [];
    foreach ((array) ($artifacts['artifacts'] ?? []) as $artifact) {
        $artifactRecords[] = [
            'id' => (int) $artifact['id'],
            'name' => (string) $artifact['name'],
            'expired' => (bool) ($artifact['expired'] ?? false),
            'size_in_bytes' => (int) ($artifact['size_in_bytes'] ?? 0),
        ];
    }
    usort($artifactRecords, static fn(array $a, array $b): int => $a['name'] <=> $b['name']);

    return [
        'runs' => $runRecords,
        'jobs' => $jobRecords,
        'incomplete_runs' => $incomplete,
        'evidence_state' => [
            'run_id' => $runId,
            'checked_at' => $checkedAt,
            'artifacts_present' => $artifactRecords !== [],
            'artifacts' => $artifactRecords,
            // Log availability is a live property that the collector does not
            // spend a call per job to probe; the classifier records what it
            // actually managed to fetch under --with-logs.
            'logs_available' => 'not-probed',
        ],
    ];
}

/**
 * The provenance table: every dataset field typed observed or derived.
 *
 * @return array<string, mixed>
 */
function cre_provenance(): array
{
    return [
        'contract' => 'docs/specs/delivery-telemetry.md — provenance is never upgraded to a stronger kind. '
            . 'Nothing typed `derived` here is computed from a value not typed `observed` in this same dataset.',
        'collector_version' => CRE_COLLECTOR_VERSION,
        'classifier_version' => CRE_CLASSIFIER_VERSION,
        'log_parser_version' => CRE_LOG_PARSER_VERSION,
        'observed' => [
            'runs' => [
                'repository', 'workflow', 'event', 'run_id', 'run_attempt', 'latest_attempt', 'head_sha',
                'ci_yml_blob_sha', 'created_at', 'run_started_at', 'updated_at', 'conclusion',
            ],
            'jobs' => [
                'run_id', 'run_attempt', 'job_id', 'name', 'runner_labels', 'created_at', 'started_at',
                'completed_at', 'conclusion', 'steps.total', 'steps.failed',
            ],
            'evidence_state' => ['artifacts', 'artifacts_present', 'checked_at'],
            'classifications.log_observations' => ['job_id', 'byte_length', 'sha256', 'fetched_at'],
            'classifications.artifact_observations' => ['artifact_id', 'byte_length', 'sha256', 'fetched_at'],
        ],
        'derived' => [
            'runs' => ['pr_number', 'pr_source', 'fingerprint', 'queue_seconds', 'wall_seconds', 'job_count'],
            'jobs' => [
                'key', 'key_reason', 'matrix', 'runner_multiplier', 'runner_multiplier_matched',
                'queue_seconds', 'wall_seconds', 'steps.first_failed',
            ],
            'runs_incomplete' => [
                'A run record carrying `jobs_listed: 0` is derived from the run object alone, because '
                . 'GitHub listed no jobs for that attempt. Its queue and wall seconds are null rather than '
                . 'borrowed from another attempt, and `cohort.incomplete_runs` names it with a reason.',
            ],
            'cohort' => [
                'reference_fingerprints', 'resolved', 'skipped_runs', 'skipped_runs_source',
                'collection_mode', 'incomplete_runs',
            ],
            'evidence_state' => ['logs_available'],
            'collector' => ['api_calls', 'retried_requests', 'retried_paths'],
            'classifications' => [
                'job.classification', 'job.derivative_of', 'run.first_pass_outcome', 'run.rerun',
                'run.critical_path', 'run.cost_proxy_seconds', 'run.latency_seconds',
                'random_order_uniqueness', 'failing_identities',
            ],
        ],
        'unavailable' => [
            'billed_runner_minutes' => 'Structurally unavailable: GET /actions/runs/{id}/timing reports '
                . 'duration_ms 0 on a public repository, and organization billing requires an admin:org scope '
                . 'this tooling does not hold. Summed job-wall seconds weighted by GitHub\'s published runner '
                . 'multipliers is recorded as a COST PROXY, not as spend.',
        ],
    ];
}

// ---------------------------------------------------------------------------
// Classification
// ---------------------------------------------------------------------------

/**
 * The offline classification pass. Adds a `classifications` block: one record
 * per job, one per run attempt, plus random-order uniqueness for red pull
 * request runs when logs and artifacts were fetched.
 *
 * @param array<string, mixed> $dataset
 * @param array<string, mixed> $inventory
 * @param (callable(string, string): array{status:int, body:string})|null $runner
 * @return array<string, mixed>
 */
function cre_classify(array $dataset, array $inventory, ?callable $runner, array $options = []): array
{
    $withLogs = (bool) ($options['with_logs'] ?? false);
    $scratch = (string) ($options['scratch'] ?? sys_get_temp_dir());
    $now = (string) ($options['classified_at'] ?? gmdate('Y-m-d\TH:i:s\Z'));
    $contexts = cre_context_map($inventory);
    $inventoryJobs = cre_inventory_jobs($inventory);
    $callLog = [];

    // --- resolve every hosted job name to an inventory job -----------------
    $jobs = $dataset['jobs'];
    foreach ($jobs as $index => $job) {
        $entry = $contexts[$job['name']] ?? null;
        if ($entry === null) {
            $jobs[$index]['key'] = null;
            $jobs[$index]['key_reason'] = 'name-is-not-a-known-visible-context';
            continue;
        }
        if ($entry['key'] === null) {
            $jobs[$index]['key'] = null;
            $jobs[$index]['key_reason'] = $entry['reason'];
            continue;
        }
        $jobs[$index]['key'] = $entry['workflow'] . '#' . $entry['key'];
        $jobs[$index]['matrix'] = $entry['matrix'];
    }

    // --- index by run attempt ----------------------------------------------
    $byAttempt = [];
    foreach ($jobs as $index => $job) {
        $byAttempt[$job['run_id']][$job['run_attempt']][] = $index;
    }

    $jobClassifications = [];
    foreach ($byAttempt as $runId => $attempts) {
        foreach ($attempts as $attempt => $indexes) {
            $byKey = [];
            foreach ($indexes as $index) {
                if ($jobs[$index]['key'] !== null) {
                    $byKey[$jobs[$index]['key']][] = $index;
                }
            }
            foreach ($indexes as $index) {
                $jobClassifications[] = cre_classify_job($jobs[$index], $jobs, $byKey, $inventoryJobs);
            }
        }
    }
    usort(
        $jobClassifications,
        static fn(array $a, array $b): int => [$a['run_id'], $a['run_attempt'], $a['job_id']]
            <=> [$b['run_id'], $b['run_attempt'], $b['job_id']],
    );

    $classificationByJob = [];
    foreach ($jobClassifications as $record) {
        $classificationByJob[$record['job_id']] = $record['classification'];
    }

    // --- per run attempt ----------------------------------------------------
    $runClassifications = [];
    $headByRun = [];
    foreach ($dataset['runs'] as $run) {
        $headByRun[$run['run_id']][] = $run['head_sha'];
    }
    foreach ($dataset['runs'] as $run) {
        $indexes = $byAttempt[$run['run_id']][$run['run_attempt']] ?? [];
        $attemptJobs = array_map(static fn(int $index): array => $jobs[$index], $indexes);

        // An attempt with no job records at all cannot be costed. Summing over
        // nothing yields 0, and a 0 entering the distribution would assert that
        // the attempt was free -- which is only knowable for an attempt that
        // provably never executed, and is not knowable for one whose jobs
        // GitHub simply did not list. It stays null, and counts as missing.
        $cost = $attemptJobs === [] ? null : 0;
        $costComplete = $attemptJobs !== [];
        foreach ($attemptJobs as $job) {
            if ($job['wall_seconds'] === null) {
                $costComplete = false;
                continue;
            }
            $cost += $job['wall_seconds'] * $job['runner_multiplier'];
        }

        $runClassifications[] = [
            'run_id' => $run['run_id'],
            'run_attempt' => $run['run_attempt'],
            'cohort' => $run['cohort'] ?? null,
            'first_pass_outcome' => $run['run_attempt'] === 1 ? ($run['conclusion'] ?? null) : null,
            'latency_seconds' => $run['wall_seconds'],
            'cost_proxy_seconds' => $cost,
            'cost_proxy_complete' => $costComplete,
            'cost_proxy_basis' => 'sum(job wall seconds x GitHub published runner multiplier) — a cost proxy, not billed spend',
            'critical_path' => cre_critical_path($attemptJobs, $inventoryJobs),
            'failure_ownership' => $attemptJobs === []
                // No job records exist for this attempt, so no job can own its
                // outcome. Saying "no roots" would read as "nothing failed".
                ? ['roots' => [], 'derivative' => [], 'note' => 'no_jobs_listed']
                : cre_failure_ownership($attemptJobs, $classificationByJob),
        ];
    }

    // --- reruns and flakes --------------------------------------------------
    $reruns = [];
    foreach ($byAttempt as $runId => $attempts) {
        if (count($attempts) < 2) {
            continue;
        }
        ksort($attempts);
        $heads = array_values(array_unique($headByRun[$runId] ?? []));
        $attemptNumbers = array_keys($attempts);
        $previous = $attemptNumbers[count($attemptNumbers) - 2];
        $latest = $attemptNumbers[count($attemptNumbers) - 1];

        $conclusionByName = static function (array $indexes) use ($jobs): array {
            $map = [];
            foreach ($indexes as $index) {
                $map[$jobs[$index]['name']] = $jobs[$index]['conclusion'];
            }

            return $map;
        };
        $before = $conclusionByName($attempts[$previous]);
        $after = $conclusionByName($attempts[$latest]);

        $flipped = [];
        $persisted = [];
        foreach ($before as $name => $conclusion) {
            if (!in_array($conclusion, ['failure', 'timed_out'], true) || !array_key_exists($name, $after)) {
                continue;
            }
            if ($after[$name] === 'success') {
                $flipped[] = $name;
            } elseif (in_array($after[$name], ['failure', 'timed_out'], true)) {
                $persisted[] = $name;
            }
        }
        sort($flipped, SORT_STRING);
        sort($persisted, SORT_STRING);

        // Every attempt of one run id shares one head SHA by construction: a
        // new head produces a NEW run id and is therefore not a rerun at all.
        // The property is verified from the data rather than assumed.
        $sameHead = count($heads) === 1;
        $outcome = 'persistent_failure';
        if (!$sameHead) {
            $outcome = 'not_a_rerun_new_head';
        } elseif ($flipped !== [] && $persisted === []) {
            $outcome = 'flake';
        } elseif ($flipped !== []) {
            $outcome = 'partial_flake';
        }

        $reruns[] = [
            'run_id' => $runId,
            'attempts' => array_values($attemptNumbers),
            'head_shas' => $heads,
            'same_head' => $sameHead,
            'outcome' => $outcome,
            'flipped_red_to_green' => $flipped,
            'still_red' => $persisted,
            'rule' => 'Attempts of one run id share one head SHA; a red-to-green flip across attempts on the '
                . 'same head is a flake. A new head is a different run id and is not a rerun.',
        ];
    }
    usort($reruns, static fn(array $a, array $b): int => $a['run_id'] <=> $b['run_id']);

    // --- random-order uniqueness -------------------------------------------
    $uniqueness = cre_random_order_uniqueness(
        $dataset,
        $jobs,
        $runner,
        $withLogs,
        $scratch,
        $now,
        $callLog,
    );

    // Log availability is a live property the collector does not spend a call
    // per job to probe. It is filled in here from what the classifier actually
    // managed to fetch, so `not-probed` means exactly that and never doubles as
    // "there were none".
    $logOutcome = [];
    foreach ($uniqueness['records'] as $record) {
        $outcome = match (true) {
            $record['reason'] === 'logs_not_fetched' => 'not-probed',
            $record['reason'] === 'log_expired' => 'expired',
            default => 'available',
        };
        $logOutcome[$record['run_id']][$outcome] = true;
    }
    foreach ($dataset['evidence_state'] as $index => $entry) {
        $outcomes = $logOutcome[$entry['run_id']] ?? null;
        if ($outcomes === null) {
            // No random-order shard of this run failed, so no log was needed.
            $dataset['evidence_state'][$index]['logs_available'] = 'not-required';
            continue;
        }
        $dataset['evidence_state'][$index]['logs_available'] = match (true) {
            isset($outcomes['not-probed']) => 'not-probed',
            isset($outcomes['available'], $outcomes['expired']) => 'partially-expired',
            isset($outcomes['expired']) => 'expired',
            default => 'available',
        };
    }

    $dataset['jobs'] = $jobs;
    $dataset['classifications'] = [
        'classifier' => array_merge([
            'tool' => 'bin/classify-ci-run-evidence',
            'version' => CRE_CLASSIFIER_VERSION,
            'log_parser_version' => CRE_LOG_PARSER_VERSION,
            'classified_at' => $now,
            'with_logs' => $withLogs,
        ], cre_transport_summary($callLog)),
        'precedence' => CRE_CLASSIFICATION_PRECEDENCE,
        'infrastructure_step_names' => CRE_INFRASTRUCTURE_STEP_NAMES,
        'jobs' => $jobClassifications,
        'runs' => $runClassifications,
        'reruns' => $reruns,
        'random_order_uniqueness' => $uniqueness,
    ];

    return $dataset;
}

/**
 * Classifies one job against the classification precedence. Every branch
 * records the evidence it used, so a reader can re-derive the label.
 *
 * @param array<string, mixed> $job
 * @param list<array<string, mixed>> $jobs
 * @param array<string, list<int>> $byKey job key => indexes in this attempt
 * @param array<string, array<string, mixed>> $inventoryJobs
 * @return array<string, mixed>
 */
function cre_classify_job(array $job, array $jobs, array $byKey, array $inventoryJobs): array
{
    $inventoryJob = $job['key'] !== null ? ($inventoryJobs[$job['key']] ?? null) : null;
    $conclusion = $job['conclusion'];
    $classification = 'unclassified';
    $reason = null;
    $derivativeOf = [];

    // Prerequisite instances of this job within the same attempt.
    $prerequisites = [];
    if ($inventoryJob !== null && $job['key'] !== null) {
        $workflow = explode('#', $job['key'], 2)[0];
        foreach ((array) ($inventoryJob['needs'] ?? []) as $need) {
            foreach ($byKey[$workflow . '#' . $need] ?? [] as $index) {
                $prerequisites[] = $jobs[$index];
            }
        }
    }
    $nonSuccess = array_values(array_filter(
        $prerequisites,
        static fn(array $prerequisite): bool => $prerequisite['conclusion'] !== 'success',
    ));

    if ($conclusion === 'cancelled') {
        $classification = 'cancellation';
        $reason = 'run or job cancelled; a superseded-run cancellation is not a defect';
    } elseif ($conclusion === 'skipped') {
        if ($inventoryJob === null) {
            $classification = 'unexpected_skip_or_missing_prerequisite';
            $reason = $job['key_reason'] ?? 'unmapped-job';
        } elseif (($inventoryJob['expected_skip']['own_condition'] ?? false) === true) {
            // The inventory's own derivation rule, not a second opinion: a job
            // whose `if` contains `always()` CANNOT skip, so `always()` never
            // counts as a condition that explains a skip.
            $classification = 'expected_conditional_skip';
            $reason = 'job declares its own skippable `if` condition: '
                . (string) ($inventoryJob['if']['normalized'] ?? '');
        } elseif ($nonSuccess !== []) {
            $classification = 'unexpected_skip_or_missing_prerequisite';
            $reason = 'prerequisite did not succeed';
            $derivativeOf = array_values(array_map(
                static fn(array $prerequisite): string => $prerequisite['name'],
                $nonSuccess,
            ));
        } else {
            $classification = 'unexpected_skip_or_missing_prerequisite';
            $reason = 'unexplained: no own condition and every prerequisite succeeded';
        }
    } elseif (in_array($conclusion, ['failure', 'timed_out'], true)) {
        $firstFailed = $job['steps']['first_failed'] ?? null;
        if ($inventoryJob !== null && ($inventoryJob['aggregate'] ?? null) !== null && $nonSuccess !== []) {
            $classification = 'derivative_aggregate_failure';
            $reason = 'aggregate red because a prerequisite in its inventory lineage is not successful';
            $derivativeOf = array_values(array_map(
                static fn(array $prerequisite): string => $prerequisite['name'],
                $nonSuccess,
            ));
        } elseif ($firstFailed === null) {
            $classification = 'setup_or_infrastructure_failure';
            $reason = 'job failed with no failed step — a runner or infrastructure abort';
        } elseif (in_array($firstFailed['name'], CRE_INFRASTRUCTURE_STEP_NAMES, true)) {
            $classification = 'setup_or_infrastructure_failure';
            $reason = 'first failed step is a bounded setup or infrastructure step: ' . $firstFailed['name'];
        } else {
            $classification = 'root_execution_failure';
            $reason = 'first failed step is the job\'s own execution: ' . $firstFailed['name'];
        }
    } elseif ($conclusion === 'success') {
        if ($inventoryJob !== null && ($inventoryJob['structural_role'] ?? null) === 'publication') {
            $classification = 'publication_only';
            $reason = 'inventory structural role is publication';
        } else {
            $classification = 'success';
            $reason = null;
        }
    } else {
        $reason = 'conclusion outside the classified vocabulary: ' . var_export($conclusion, true);
    }

    return [
        'run_id' => $job['run_id'],
        'run_attempt' => $job['run_attempt'],
        'job_id' => $job['job_id'],
        'name' => $job['name'],
        'key' => $job['key'],
        'conclusion' => $conclusion,
        'classification' => $classification,
        'reason' => $reason,
        'derivative_of' => $derivativeOf,
    ];
}

/**
 * The longest dependency chain by ACTUAL timestamps: start at the job that
 * finished last, then repeatedly step to the prerequisite instance that
 * finished last, following the inventory's `needs` edges. The result is the
 * chain that actually gated the run's completion, not a static estimate.
 *
 * @param list<array<string, mixed>> $attemptJobs
 * @param array<string, array<string, mixed>> $inventoryJobs
 * @return array<string, mixed>
 */
function cre_critical_path(array $attemptJobs, array $inventoryJobs): array
{
    $completed = array_values(array_filter(
        $attemptJobs,
        static fn(array $job): bool => $job['completed_at'] !== null && $job['conclusion'] !== 'skipped',
    ));
    if ($completed === []) {
        return ['seconds' => null, 'chain' => [], 'reason' => 'no completed job with a timestamp'];
    }

    $byKey = [];
    foreach ($completed as $job) {
        if ($job['key'] !== null) {
            $byKey[$job['key']][] = $job;
        }
    }

    usort($completed, static fn(array $a, array $b): int => strcmp((string) $b['completed_at'], (string) $a['completed_at']));
    $current = $completed[0];
    $chain = [$current];
    $seen = [];

    while ($current['key'] !== null && !isset($seen[$current['key']])) {
        $seen[$current['key']] = true;
        $inventoryJob = $inventoryJobs[$current['key']] ?? null;
        if ($inventoryJob === null) {
            break;
        }
        $workflow = explode('#', $current['key'], 2)[0];
        $candidates = [];
        foreach ((array) ($inventoryJob['needs'] ?? []) as $need) {
            foreach ($byKey[$workflow . '#' . $need] ?? [] as $candidate) {
                $candidates[] = $candidate;
            }
        }
        if ($candidates === []) {
            break;
        }
        usort(
            $candidates,
            static fn(array $a, array $b): int => strcmp((string) $b['completed_at'], (string) $a['completed_at']),
        );
        $current = $candidates[0];
        $chain[] = $current;
    }

    $chain = array_reverse($chain);
    $first = $chain[0];
    $last = $chain[count($chain) - 1];

    return [
        'seconds' => cre_seconds_between($first['created_at'], $last['completed_at']),
        'chain' => array_values(array_map(static fn(array $job): array => [
            'name' => $job['name'],
            'key' => $job['key'],
            'queue_seconds' => $job['queue_seconds'],
            'wall_seconds' => $job['wall_seconds'],
        ], $chain)),
        'reason' => null,
    ];
}

/**
 * Splits an attempt's non-success jobs into root and derivative owners, so a
 * report can count one failure per cause instead of once per red check.
 *
 * @param list<array<string, mixed>> $attemptJobs
 * @param array<int, string> $classificationByJob
 * @return array<string, mixed>
 */
function cre_failure_ownership(array $attemptJobs, array $classificationByJob): array
{
    $roots = [];
    $derivative = [];
    foreach ($attemptJobs as $job) {
        $classification = $classificationByJob[$job['job_id']] ?? 'unclassified';
        if (in_array($classification, ['root_execution_failure', 'setup_or_infrastructure_failure'], true)) {
            $roots[] = $job['name'];
        } elseif (in_array($classification, ['derivative_aggregate_failure', 'unexpected_skip_or_missing_prerequisite'], true)) {
            $derivative[] = $job['name'];
        }
    }
    sort($roots, SORT_STRING);
    sort($derivative, SORT_STRING);

    return ['roots' => $roots, 'derivative' => $derivative];
}

// ---------------------------------------------------------------------------
// Random-order uniqueness
// ---------------------------------------------------------------------------

/**
 * For every failed `ci/random-order-shard-N` job in a red pull-request run,
 * decides whether the random-order execution detected something the ordinary
 * timing-balanced shards did not.
 *
 * The random-order shards upload NOTHING — they run PHPUnit with --no-coverage
 * and no --log-junit — so their failing-test identity exists only in the job's
 * console log. The ordinary shards' identities come from the `php-test-shard-N`
 * artifacts' JUnit XML. Both sides are therefore `derived`, over an `observed`
 * fetch record.
 *
 * @param array<string, mixed> $dataset
 * @param list<array<string, mixed>> $jobs
 * @param (callable(string, string): array{status:int, body:string})|null $runner
 * @return array<string, mixed>
 */
function cre_random_order_uniqueness(
    array $dataset,
    array $jobs,
    ?callable $runner,
    bool $withLogs,
    string $scratch,
    string $now,
    array &$callLog,
): array {
    $repository = (string) ($dataset['cohort']['spec']['repository'] ?? '');
    $artifactsByRun = [];
    foreach ($dataset['evidence_state'] as $state) {
        $artifactsByRun[$state['run_id']] = $state['artifacts'];
    }
    $cohortByRun = [];
    foreach ($dataset['runs'] as $run) {
        $cohortByRun[$run['run_id']] = ['cohort' => $run['cohort'] ?? null, 'event' => $run['event'], 'head_sha' => $run['head_sha']];
    }

    $records = [];
    $logObservations = [];
    $artifactObservations = [];
    $ordinaryCache = [];

    foreach ($jobs as $job) {
        if (!preg_match('#^ci/random-order-shard-\d+$#', (string) $job['name'])) {
            continue;
        }
        if (!in_array($job['conclusion'], ['failure', 'timed_out'], true)) {
            continue;
        }
        $runId = $job['run_id'];
        $record = [
            'run_id' => $runId,
            'run_attempt' => $job['run_attempt'],
            'job_id' => $job['job_id'],
            'name' => $job['name'],
            'head_sha' => $cohortByRun[$runId]['head_sha'] ?? null,
            'cohort' => $cohortByRun[$runId]['cohort'] ?? null,
            'verdict' => 'not_classifiable',
            'reason' => null,
            'random_order_identities' => [],
            'ordinary_identities' => [],
            'unique_identities' => [],
            'parser_version' => CRE_LOG_PARSER_VERSION,
            'confidence' => null,
            'raw_matched_lines' => [],
        ];

        if (!$withLogs || $runner === null) {
            $record['reason'] = 'logs_not_fetched';
            $records[] = $record;
            continue;
        }

        $log = cre_api_raw($runner, "repos/{$repository}/actions/jobs/{$job['job_id']}/logs", $callLog);
        if ($log === null) {
            $record['reason'] = 'log_expired';
            $records[] = $record;
            continue;
        }
        $logObservations[] = [
            'job_id' => $job['job_id'],
            'byte_length' => strlen($log),
            'sha256' => hash('sha256', $log),
            'fetched_at' => $now,
        ];

        $parsed = cre_parse_phpunit_log($log);
        $record['random_order_identities'] = $parsed['identities'];
        $record['raw_matched_lines'] = $parsed['raw_lines'];
        $record['confidence'] = $parsed['confidence'];
        if ($parsed['identities'] === []) {
            $record['reason'] = 'parser_miss: no PHPUnit failure block matched in the job log';
            $records[] = $record;
            continue;
        }

        if (!array_key_exists($runId, $ordinaryCache)) {
            $ordinaryCache[$runId] = cre_ordinary_shard_identities(
                $runner,
                $repository,
                $artifactsByRun[$runId] ?? [],
                $scratch,
                $now,
                $callLog,
            );
            foreach ($ordinaryCache[$runId]['observations'] as $observation) {
                $artifactObservations[] = $observation;
            }
        }
        $ordinary = $ordinaryCache[$runId];
        if ($ordinary['status'] !== 'ok') {
            $record['reason'] = $ordinary['status'];
            $records[] = $record;
            continue;
        }

        $record['ordinary_identities'] = $ordinary['identities'];
        $unique = array_values(array_diff(
            array_map('cre_normalise_identity', $parsed['identities']),
            array_map('cre_normalise_identity', $ordinary['identities']),
        ));
        sort($unique, SORT_STRING);
        $record['unique_identities'] = $unique;
        $record['verdict'] = $unique === [] ? 'corroborating' : 'unique_first_pass_detection';
        $record['reason'] = $unique === []
            ? 'every random-order failing identity also failed in an ordinary shard'
            : 'random-order failing identities absent from every ordinary shard on the same head';
        $records[] = $record;
    }

    usort($records, static fn(array $a, array $b): int => [$a['run_id'], $a['job_id']] <=> [$b['run_id'], $b['job_id']]);
    usort($logObservations, static fn(array $a, array $b): int => $a['job_id'] <=> $b['job_id']);
    usort($artifactObservations, static fn(array $a, array $b): int => $a['artifact_id'] <=> $b['artifact_id']);

    return [
        'statement' => 'Random-order shards upload no JUnit, so their failing-test identity is parsed from the '
            . 'job console log; ordinary shard identities come from the php-test-shard-* JUnit artifacts on the '
            . 'same head. Both sides are derived over an observed fetch record. Caveat: the artifacts API does '
            . 'not say which attempt produced an artifact, so on a rerun run the ordinary set may union both '
            . 'attempts. Every attempt of a run shares one head SHA, and a larger ordinary set can only make a '
            . 'unique-detection verdict harder to reach, so the bias is conservative and never inflates '
            . 'unique_first_pass_detection.',
        'records' => $records,
        'log_observations' => $logObservations,
        'artifact_observations' => $artifactObservations,
    ];
}

/**
 * Failing test identities from every `php-test-shard-*` artifact of one run.
 *
 * @param callable(string, string): array{status:int, body:string} $runner
 * @param list<array<string, mixed>> $artifacts
 * @return array{status:string, identities:list<string>, observations:list<array<string,mixed>>}
 */
function cre_ordinary_shard_identities(
    callable $runner,
    string $repository,
    array $artifacts,
    string $scratch,
    string $now,
    array &$callLog,
): array {
    $shards = array_values(array_filter(
        $artifacts,
        static fn(array $artifact): bool => preg_match('#^php-test-shard-\d+$#', (string) $artifact['name']) === 1,
    ));
    if ($shards === []) {
        return ['status' => 'artifact_expired: no php-test-shard-* artifact on the run', 'identities' => [], 'observations' => []];
    }
    foreach ($shards as $shard) {
        if ($shard['expired']) {
            return ['status' => 'artifact_expired', 'identities' => [], 'observations' => []];
        }
    }

    $identities = [];
    $observations = [];
    foreach ($shards as $shard) {
        $zip = cre_api_raw($runner, "repos/{$repository}/actions/artifacts/{$shard['id']}/zip", $callLog);
        if ($zip === null) {
            return ['status' => 'artifact_expired', 'identities' => [], 'observations' => []];
        }
        $observations[] = [
            'artifact_id' => (int) $shard['id'],
            'name' => (string) $shard['name'],
            'byte_length' => strlen($zip),
            'sha256' => hash('sha256', $zip),
            'fetched_at' => $now,
        ];
        $directory = rtrim(str_replace('\\', '/', $scratch), '/') . '/artifact-' . $shard['id'];
        $extraction = cre_extract_zip($zip, $directory);
        if ($extraction['status'] !== 'ok') {
            return ['status' => 'parser_miss: ' . $extraction['status'], 'identities' => [], 'observations' => $observations];
        }
        $documents = glob($directory . '/junit-*.xml') ?: [];
        if ($documents === []) {
            return [
                'status' => 'parser_miss: the artifact contained no junit-*.xml',
                'identities' => [],
                'observations' => $observations,
            ];
        }
        foreach ($documents as $file) {
            $parsed = cre_parse_junit((string) file_get_contents($file));
            if ($parsed === null) {
                // A present-but-unparsable document must not shrink the
                // ordinary set: that would invent a unique detection.
                return [
                    'status' => 'parser_miss: unparsable junit xml in ' . basename($file),
                    'identities' => [],
                    'observations' => $observations,
                ];
            }
            foreach ($parsed as $identity) {
                $identities[] = $identity;
            }
        }
    }
    $identities = array_values(array_unique($identities));
    sort($identities, SORT_STRING);

    return ['status' => 'ok', 'identities' => $identities, 'observations' => $observations];
}

/**
 * Extracts an artifact zip without ext-zip, which this Windows PHP lacks.
 * bsdtar (`%SystemRoot%\System32\tar.exe` on Windows, `tar` elsewhere) is tried
 * first and `unzip` second; the extractor that worked is reported so the
 * dataset records how the bytes were opened.
 *
 * @return array{status:string, extractor:string|null}
 */
function cre_extract_zip(string $bytes, string $directory): array
{
    if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
        return ['status' => 'unable to create the extraction directory', 'extractor' => null];
    }
    $archive = $directory . '/artifact.zip';
    if (file_put_contents($archive, $bytes) === false) {
        return ['status' => 'unable to stage the artifact zip', 'extractor' => null];
    }

    $systemRoot = getenv('SystemRoot');
    $candidates = [];
    if (is_string($systemRoot) && $systemRoot !== '') {
        $candidates[] = [str_replace('\\', '/', $systemRoot) . '/System32/tar.exe', ['-xf', $archive, '-C', $directory]];
    }
    $candidates[] = ['tar', ['-xf', $archive, '-C', $directory]];
    $candidates[] = ['unzip', ['-o', '-q', $archive, '-d', $directory]];

    foreach ($candidates as [$executable, $arguments]) {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open(array_merge([$executable], $arguments), $descriptors, $pipes);
        if (!is_resource($process)) {
            continue;
        }
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) === 0 && (glob($directory . '/junit-*.xml') ?: []) !== []) {
            return ['status' => 'ok', 'extractor' => $executable];
        }
    }

    return ['status' => 'no available zip extractor produced a junit-*.xml (ext-zip is absent on this host)', 'extractor' => null];
}

/**
 * Parses failing test identities out of a GitHub job log containing PHPUnit
 * output.
 *
 * The log carries a per-line `<ISO-8601>Z ` prefix and ANSI colour escapes;
 * both are stripped before matching. PHPUnit numbers its failure and error
 * blocks independently from 1, so every `N) FQCN::method` line is collected
 * wherever it appears rather than only inside one block.
 *
 * `confidence` is `high` only when the number of parsed identities equals the
 * failures and errors PHPUnit's own summary lines report; a truncated or
 * interleaved log reports `low`, and the raw matched lines travel with the
 * result so the parse can be re-read.
 *
 * Two bounded false negatives are known, and both fail safe. An indented
 * `N) Class::method` line is not matched, because the pattern is anchored to
 * the start of the line to avoid inventing a test identity out of prose; and
 * the summary regex matches `Failures:`/`Errors:` anywhere on a line, so a
 * script that echoes those words would inflate `reported_total`. The first
 * loses an identity, which can only cost a `unique_first_pass_detection` and
 * never manufacture one; the second breaks the count equality and downgrades
 * `confidence` to `low`. Neither can turn an unparsed log into a confident
 * finding.
 *
 * @return array{identities:list<string>, raw_lines:list<string>, confidence:string, reported_total:int|null}
 */
function cre_parse_phpunit_log(string $log): array
{
    $log = str_replace("\r\n", "\n", $log);
    $identities = [];
    $rawLines = [];
    $reported = null;

    foreach (explode("\n", $log) as $line) {
        // `2026-09-17T21:38:20.1147822Z ` — the runner's per-line stamp.
        $line = preg_replace('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+Z\s?/', '', $line) ?? $line;
        $line = preg_replace('/\x1b\[[0-9;]*m/', '', $line) ?? $line;
        $line = rtrim($line);

        if (preg_match('/^(\d+)\)\s+([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff\\\\]*::[A-Za-z0-9_]+.*)$/', $line, $match) === 1) {
            $rawLines[] = $line;
            $identities[] = trim($match[2]);
            continue;
        }
        if (preg_match_all('/\b(Failures|Errors):\s*(\d+)/', $line, $summary, PREG_SET_ORDER) > 0) {
            foreach ($summary as $entry) {
                $reported = (int) ($reported ?? 0) + (int) $entry[2];
            }
        }
    }

    $identities = array_values(array_unique($identities));
    sort($identities, SORT_STRING);
    $rawLines = array_values(array_unique($rawLines));
    sort($rawLines, SORT_STRING);

    $confidence = 'low';
    if ($reported !== null && $reported === count($identities)) {
        $confidence = 'high';
    }

    return [
        'identities' => $identities,
        'raw_lines' => $rawLines,
        'confidence' => $confidence,
        'reported_total' => $reported,
    ];
}

/**
 * Failing test identities from one PHPUnit JUnit XML document.
 *
 * PHPUnit writes the namespaced FQCN in `class` and a dot-separated rendering
 * in `classname`; the former is preferred and the latter is converted, so an
 * identity always reads `Fully\Qualified\ClassName::method`.
 *
 * Fails closed. An unparsable document returns null, NOT an empty list: an
 * empty ordinary set makes every random-order failure look unique, so a
 * truncated or corrupt JUnit file would manufacture exactly the finding this
 * measurement is most likely to be quoted for. The caller turns null into
 * `not_classifiable`, like every other missing-evidence path.
 *
 * @return list<string>|null
 */
function cre_parse_junit(string $xml): ?array
{
    $previous = libxml_use_internal_errors(true);
    $document = simplexml_load_string($xml);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if ($document === false) {
        return null;
    }

    $identities = [];
    foreach ($document->xpath('//testcase[failure or error]') ?: [] as $testcase) {
        $class = (string) ($testcase['class'] ?? '');
        if ($class === '') {
            $class = str_replace('.', '\\', (string) ($testcase['classname'] ?? ''));
        }
        $name = (string) ($testcase['name'] ?? '');
        if ($class === '' || $name === '') {
            continue;
        }
        $identities[] = $class . '::' . $name;
    }
    $identities = array_values(array_unique($identities));
    sort($identities, SORT_STRING);

    return $identities;
}

/**
 * Normalizes a test identity for cross-source comparison: PHPUnit's console
 * output appends a data-set suffix (`with data set #3`) that the JUnit `name`
 * attribute renders differently, so the comparison is made on the bare
 * `FQCN::method`, and the unnormalized identities stay in the record.
 */
function cre_normalise_identity(string $identity): string
{
    $identity = preg_replace('/\s+with data set\b.*$/', '', $identity) ?? $identity;
    $identity = preg_replace('/\s*#\d+\s*$/', '', $identity) ?? $identity;
    $identity = preg_replace('/\s*\(.*\)\s*$/', '', $identity) ?? $identity;

    return trim($identity);
}

// ---------------------------------------------------------------------------
// Input and output
// ---------------------------------------------------------------------------

/**
 * Canonical JSON rendering: pretty-printed, unescaped slashes and unicode, one
 * trailing newline. Two renders of one document are byte-identical.
 *
 * @param array<string, mixed> $document
 */
function cre_render_json(array $document): string
{
    $json = json_encode(
        $document,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );

    return $json . "\n";
}

/**
 * Reads and strictly decodes a JSON document.
 *
 * @return array<string, mixed>
 */
function cre_read_json(string $path): array
{
    $contents = @file_get_contents($path);
    if ($contents === false) {
        throw new CiRunEvidenceFailure("unable to read {$path}.");
    }
    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new CiRunEvidenceFailure("malformed JSON in {$path}: " . $exception->getMessage());
    }
    if (!is_array($decoded)) {
        throw new CiRunEvidenceFailure("{$path} does not contain a JSON object.");
    }

    return $decoded;
}

/**
 * Write-to-temp-then-rename, so a failed or interrupted write never publishes
 * a partial dataset over a good one.
 */
function cre_write_atomic(string $path, string $contents): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
        throw new CiRunEvidenceFailure("cannot write {$path}: directory {$directory} does not exist.");
    }
    $temporary = $path . '.tmp-' . getmypid();
    if (file_put_contents($temporary, $contents) === false) {
        throw new CiRunEvidenceFailure("failed to write {$temporary}.");
    }
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new CiRunEvidenceFailure("failed to move {$temporary} onto {$path}.");
    }
}

/**
 * The observed-only projection of a dataset, for the --refetch reproducibility
 * check. Everything time-dependent (collection timestamps, API call counts,
 * evidence state) is excluded here and compared separately, so a log that has
 * since expired is reported as an evidence-state change rather than as drift
 * in the frozen facts.
 *
 * @param array<string, mixed> $dataset
 * @return array<string, mixed>
 */
function cre_observed_projection(array $dataset): array
{
    $runs = [];
    foreach ((array) $dataset['runs'] as $run) {
        $runs[] = [
            'repository' => $run['repository'], 'workflow' => $run['workflow'], 'event' => $run['event'],
            'run_id' => $run['run_id'], 'run_attempt' => $run['run_attempt'], 'head_sha' => $run['head_sha'],
            'ci_yml_blob_sha' => $run['ci_yml_blob_sha'], 'fingerprint' => $run['fingerprint'],
            'created_at' => $run['created_at'], 'run_started_at' => $run['run_started_at'],
            'updated_at' => $run['updated_at'], 'conclusion' => $run['conclusion'],
            'pr_number' => $run['pr_number'], 'queue_seconds' => $run['queue_seconds'],
            'wall_seconds' => $run['wall_seconds'], 'job_count' => $run['job_count'],
        ];
    }
    $jobs = [];
    foreach ((array) $dataset['jobs'] as $job) {
        $jobs[] = [
            'run_id' => $job['run_id'], 'run_attempt' => $job['run_attempt'], 'job_id' => $job['job_id'],
            'name' => $job['name'], 'runner_labels' => $job['runner_labels'],
            'created_at' => $job['created_at'], 'started_at' => $job['started_at'],
            'completed_at' => $job['completed_at'], 'conclusion' => $job['conclusion'],
            'queue_seconds' => $job['queue_seconds'], 'wall_seconds' => $job['wall_seconds'],
            'steps' => $job['steps'],
        ];
    }

    return ['runs' => $runs, 'jobs' => $jobs];
}

// ---------------------------------------------------------------------------
// Reporting
// ---------------------------------------------------------------------------

/**
 * Renders whole seconds as `1234 s (20m 34s)`. Null stays a visible dash: a
 * missing duration is never rendered as zero.
 */
function cre_format_seconds(int|float|null $seconds): string
{
    if ($seconds === null) {
        return '--';
    }
    $seconds = (int) round($seconds);

    return sprintf('%d s (%dm %02ds)', $seconds, intdiv($seconds, 60), $seconds % 60);
}

/**
 * Nearest-rank median and p95 of one numeric sample, rendered for a table row.
 *
 * @param list<int|float|null> $values
 * @return array{n:int, missing:int, median:int|float|null, p95:int|float|null}
 */
function cre_distribution(array $values): array
{
    $present = array_values(array_filter($values, static fn($value): bool => $value !== null));

    return [
        'n' => count($present),
        'missing' => count($values) - count($present),
        'median' => cre_nearest_rank($present, 0.5),
        'p95' => cre_nearest_rank($present, 0.95),
    ];
}

/**
 * The deterministic markdown baseline report. Reads the frozen dataset only --
 * no network, no clock, no randomness -- so two renders of one dataset are
 * byte-identical.
 *
 * @param array<string, mixed> $dataset
 */
function cre_render_report(array $dataset): string
{
    $lines = [];
    $collector = (array) $dataset['collector'];
    $classifier = (array) ($dataset['classifications']['classifier'] ?? []);
    $resolved = (array) $dataset['cohort']['resolved'];

    $runsByCohort = [];
    $firstAttemptByCohort = [];
    foreach ((array) $dataset['runs'] as $run) {
        $runsByCohort[(string) $run['cohort']][] = $run;
        if ($run['run_attempt'] === 1) {
            $firstAttemptByCohort[(string) $run['cohort']][] = $run;
        }
    }
    $runClassByKey = [];
    foreach ((array) ($dataset['classifications']['runs'] ?? []) as $record) {
        $runClassByKey[$record['run_id'] . ':' . $record['run_attempt']] = $record;
    }

    $lines[] = '# CI measurement baseline -- 2026-09 cohort';
    $lines[] = '';
    $lines[] = 'Change record: `FW-CI-CHECK-ROSTER-AUDIT-01` (Task 4). Measurement owner:';
    $lines[] = 'waaseyaa/framework#2869. GitHub mirror: waaseyaa/framework#3087.';
    $lines[] = '';
    $lines[] = 'Generated by `bin/report-ci-measurement` from the frozen dataset. This document is';
    $lines[] = 'a deterministic rendering: it reads the dataset only, and two renders of the same';
    $lines[] = 'dataset are byte-identical. Do not edit by hand.';
    $lines[] = '';
    $lines[] = '| Provenance | Value |';
    $lines[] = '|---|---|';
    $lines[] = '| Dataset schema | ' . $dataset['schema_version'] . ' |';
    $lines[] = '| Collector | `' . $collector['tool'] . '` v' . $collector['version'] . ' |';
    $lines[] = '| Collected at | ' . $collector['collected_at'] . ' |';
    $lines[] = '| Collection API calls | ' . $collector['api_calls']
        . ' (' . $collector['retried_requests'] . ' were retries of a transient GitHub failure) |';
    $lines[] = '| Classifier | v' . ($classifier['version'] ?? '--')
        . ', log parser v' . ($classifier['log_parser_version'] ?? '--')
        . ', `--with-logs` ' . (($classifier['with_logs'] ?? false) ? 'yes' : 'no') . ' |';
    $lines[] = '| Classification API calls | ' . ($classifier['api_calls'] ?? '--')
        . ' (' . ($classifier['retried_requests'] ?? '--') . ' retries) |';
    $lines[] = '';

    // --- 1. Cohort ----------------------------------------------------------
    $lines[] = '## 1. Sample size, comparability, and date span';
    $lines[] = '';
    $lines[] = 'Comparability is **job structure**, not workflow bytes. Each run\'s';
    $lines[] = '`.github/workflows/<file>` is fetched at its own head SHA, composed by';
    $lines[] = '`bin/generate-ci-workflow-inventory`, and reduced to a SHA-256 over every job\'s';
    $lines[] = 'key, expansion, literal matrix axes, sorted `needs`, aggregate gate token and';
    $lines[] = 'visible contexts. A run joins the cohort when that fingerprint equals the';
    $lines[] = 'fingerprint of the checked-out workflow.';
    $lines[] = '';
    $lines[] = '**Red** counts first-attempt run records whose conclusion is `failure`, computed from';
    $lines[] = 'the collected records rather than from the walk\'s own tally, so the number in this';
    $lines[] = 'table is the same population sections 3 and 5 are computed over. A run GitHub';
    $lines[] = 'listed no jobs for still counts: it concluded, and its conclusion is evidence.';
    $lines[] = '';
    $lines[] = '| Cohort | Workflow | Fingerprint enforced | Runs | Red runs | Scanned | Floor met | Date span (run created) |';
    $lines[] = '|---|---|---|---|---|---|---|---|';
    foreach ($resolved as $cohortId => $summary) {
        $firstAttempts = $firstAttemptByCohort[(string) $cohortId] ?? [];
        $created = array_values(array_filter(array_column($firstAttempts, 'created_at')));
        sort($created);
        $span = $created === [] ? '--' : $created[0] . ' -> ' . $created[count($created) - 1];
        $red = 0;
        foreach ($firstAttempts as $run) {
            if (($run['conclusion'] ?? null) === 'failure') {
                ++$red;
            }
        }
        $lines[] = sprintf(
            '| `%s` | `%s` | %s | %d | %d | %d | %s | %s |',
            $cohortId,
            $summary['workflow'],
            $summary['enforce_fingerprint'] ? 'yes' : 'no (recorded only)',
            count($firstAttempts),
            $red,
            $summary['runs_scanned'],
            $summary['target_met'] ? 'met' : '**NOT MET**',
            $span,
        );
    }
    $lines[] = '';
    $lines[] = 'Reference fingerprints of the checked-out workflows:';
    $lines[] = '';
    foreach ((array) $dataset['cohort']['reference_fingerprints'] as $file => $fingerprint) {
        $lines[] = '- `' . $file . '` -> `' . $fingerprint . '`';
    }
    $lines[] = '';

    // --- 2. Skipped runs ----------------------------------------------------
    $skipped = (array) $dataset['cohort']['skipped_runs'];
    $lines[] = '## 2. Skipped-run accounting';
    $lines[] = '';
    $lines[] = 'Every run the walk passed over is recorded with a reason, and so is every run it';
    $lines[] = 'resolved *into* the cohort but could not fully describe, so the sample is auditable';
    $lines[] = 'rather than merely asserted. A superseded-run cancellation is **not** counted as a';
    $lines[] = 'defect anywhere in this report.';
    $lines[] = '';
    $skippedSource = (array) ($dataset['cohort']['skipped_runs_source'] ?? ['mode' => 'this-walk']);
    $mode = (string) ($dataset['cohort']['collection_mode'] ?? 'walk');
    if ($mode === 'refetch') {
        $lines[] = 'This dataset was collected by **replaying a committed run-id list** (`--refetch`),';
        $lines[] = 'which never walks a run listing. The accounting below is therefore the original';
        $lines[] = 'walk\'s, carried forward unchanged'
            . (($skippedSource['from_collected_at'] ?? null) !== null
                ? ' from the collection of ' . (string) $skippedSource['from_collected_at']
                : '')
            . '. It is not a fresh derivation, and an';
        $lines[] = 'empty table here would mean "not available", never "nothing was skipped".';
        $lines[] = '';
    }
    if ($skipped === []) {
        $lines[] = $skippedSource['mode'] === 'not-available'
            ? '**Not available.** This refetch carried no skip accounting forward, so what the '
                . 'original walk passed over is not recorded in this dataset. That is missing '
                . 'evidence, not an empty set.'
            : 'No runs were skipped.';
    } else {
        $byReason = [];
        foreach ($skipped as $entry) {
            $byReason[$entry['cohort'] . ' / ' . $entry['reason']][] = $entry['run_id'];
        }
        ksort($byReason, SORT_STRING);
        $lines[] = '| Cohort / reason | Count |';
        $lines[] = '|---|---|';
        foreach ($byReason as $reason => $runIds) {
            $lines[] = '| `' . $reason . '` | ' . count($runIds) . ' |';
        }
        $lines[] = '';
        $lines[] = 'Total skipped: **' . count($skipped) . '**.';
    }
    $lines[] = '';

    $incomplete = (array) ($dataset['cohort']['incomplete_runs'] ?? []);
    $lines[] = '### Runs in the cohort with incomplete records';
    $lines[] = '';
    if ($incomplete === []) {
        $lines[] = 'None: every resolved run has a complete job listing for every attempt.';
    } else {
        $lines[] = 'GitHub listed no jobs for these run attempts. They stay in the cohort, with a run';
        $lines[] = 'record built from the run object alone (`jobs_listed: 0`) and null durations, rather';
        $lines[] = 'than vanishing from the evidence while remaining in the resolved id list.';
        $lines[] = '';
        $lines[] = '| Run | Attempt | Attempt conclusion | Latest attempt | Reason |';
        $lines[] = '|---|---|---|---|---|';
        foreach ($incomplete as $entry) {
            $lines[] = sprintf(
                '| `%d` | %d | `%s` | %d | %s |',
                $entry['run_id'],
                $entry['attempt'],
                (string) ($entry['conclusion'] ?? 'unknown'),
                $entry['latest_attempt'],
                (string) $entry['reason'],
            );
        }
        $lines[] = '';
        $lines[] = 'An earlier attempt\'s conclusion is read from the per-attempt endpoint, never copied';
        $lines[] = 'from the run object: the run-level conclusion describes the **latest** attempt, and';
        $lines[] = 'copying it would have labelled a never-executed `action_required` attempt a success.';
    }
    $lines[] = '';

    // --- 3. Timing ----------------------------------------------------------
    $lines[] = '## 3. Queue, wall, critical path, and cost proxy';
    $lines[] = '';
    $lines[] = 'Percentiles are **nearest-rank**: the sample is sorted ascending and the value at';
    $lines[] = '1-based index `ceil(q x n)` is reported, so every figure below is a value that';
    $lines[] = 'actually occurred. No interpolation, no smoothing.';
    $lines[] = '';
    $lines[] = 'Three quantities are kept separate, as the measurement contract requires:';
    $lines[] = '';
    $lines[] = '- **run wall** is pull-request latency -- the wall clock a contributor waits;';
    $lines[] = '- **critical path** is the longest `needs` chain by actual timestamps;';
    $lines[] = '- **cost proxy** is summed job wall x GitHub\'s published runner multiplier.';
    $lines[] = '  It is **not** billed spend. Billed minutes are structurally unavailable on a';
    $lines[] = '  public repository (`/actions/runs/{id}/timing` reports `duration_ms: 0`, and';
    $lines[] = '  organization billing needs an `admin:org` scope this tooling does not hold).';
    $lines[] = '';
    $lines[] = '| Cohort | Attempts | Metric | n | Median | p95 | Missing |';
    $lines[] = '|---|---|---|---|---|---|---|';
    foreach ($resolved as $cohortId => $summary) {
        $cohortRuns = $runsByCohort[(string) $cohortId] ?? [];
        $metrics = [
            'run queue' => array_column($cohortRuns, 'queue_seconds'),
            'run wall (PR latency)' => array_column($cohortRuns, 'wall_seconds'),
            'critical path' => [],
            'cost proxy' => [],
        ];
        foreach ($cohortRuns as $run) {
            $record = $runClassByKey[$run['run_id'] . ':' . $run['run_attempt']] ?? null;
            $metrics['critical path'][] = $record['critical_path']['seconds'] ?? null;
            $metrics['cost proxy'][] = $record['cost_proxy_seconds'] ?? null;
        }
        foreach ($metrics as $label => $values) {
            $distribution = cre_distribution($values);
            $lines[] = sprintf(
                '| `%s` | %d | %s | %d | %s | %s | %d |',
                $cohortId,
                count($cohortRuns),
                $label,
                $distribution['n'],
                cre_format_seconds($distribution['median']),
                cre_format_seconds($distribution['p95']),
                $distribution['missing'],
            );
        }
    }
    $lines[] = '';

    // --- 4. Critical-path composition ---------------------------------------
    $lines[] = '## 4. Critical-path composition';
    $lines[] = '';
    $lines[] = 'How often each job sat on the critical path, and how often it was the terminal';
    $lines[] = 'job that ended the run. The chain is walked backwards from the job that finished';
    $lines[] = 'last, each step taking the `needs` prerequisite that finished last.';
    $lines[] = '';
    $lines[] = '**Read the composition, not the length.** In these workflows every job is created';
    $lines[] = 'at run start, so the critical path measured from the first job\'s creation to the';
    $lines[] = 'terminal job\'s completion necessarily equals the run wall, and section 3 reports';
    $lines[] = 'the same number twice. That is a property of the workflow shape, not a coincidence';
    $lines[] = 'worth reading into: what this section adds is **which** jobs gate the run. A';
    $lines[] = 'shard-width or cadence change can only move the wall by moving something in this';
    $lines[] = 'table.';
    $lines[] = '';
    $onPath = [];
    $terminal = [];
    $pathRuns = 0;
    foreach ((array) ($dataset['classifications']['runs'] ?? []) as $record) {
        $chain = (array) ($record['critical_path']['chain'] ?? []);
        if ($chain === []) {
            continue;
        }
        ++$pathRuns;
        foreach ($chain as $node) {
            $onPath[$node['name']] = ($onPath[$node['name']] ?? 0) + 1;
        }
        $last = $chain[count($chain) - 1];
        $terminal[$last['name']] = ($terminal[$last['name']] ?? 0) + 1;
    }
    // Deterministic order: descending frequency, then job name.
    uksort($onPath, static function (string $a, string $b) use ($onPath): int {
        return [$onPath[$b], $a] <=> [$onPath[$a], $b];
    });
    if ($onPath === []) {
        $lines[] = 'No critical path could be computed.';
    } else {
        $lines[] = '| Job | On critical path | Terminal job | Share of ' . $pathRuns . ' attempts |';
        $lines[] = '|---|---|---|---|';
        foreach ($onPath as $name => $count) {
            $lines[] = sprintf(
                '| `%s` | %d | %d | %.1f%% |',
                $name,
                $count,
                $terminal[$name] ?? 0,
                $pathRuns === 0 ? 0.0 : 100 * $count / $pathRuns,
            );
        }
    }
    $lines[] = '';

    // --- 5. Failure ownership -----------------------------------------------
    $lines[] = '## 5. First-pass failure ownership';
    $lines[] = '';
    $lines[] = 'Counted over **first attempts only**. Derivative red aggregates and';
    $lines[] = 'prerequisite-starved skips are separated from the roots that caused them, so one';
    $lines[] = 'defect is counted once rather than once per red check.';
    $lines[] = '';
    $ownership = [];
    // Seed every term of the vocabulary at zero. A classification that never
    // occurred must read as zero rather than be absent from the table — an
    // absent row is indistinguishable from a term the pass forgot to apply.
    $classCounts = array_fill_keys((array) ($dataset['classifications']['precedence'] ?? []), 0);
    foreach ((array) ($dataset['classifications']['jobs'] ?? []) as $record) {
        if ($record['run_attempt'] !== 1) {
            continue;
        }
        $classCounts[$record['classification']] = ($classCounts[$record['classification']] ?? 0) + 1;
        if (in_array($record['classification'], [
            'root_execution_failure',
            'setup_or_infrastructure_failure',
            'derivative_aggregate_failure',
            'unexpected_skip_or_missing_prerequisite',
        ], true)) {
            $ownership[$record['name']][$record['classification']] =
                ($ownership[$record['name']][$record['classification']] ?? 0) + 1;
        }
    }
    ksort($classCounts, SORT_STRING);
    $lines[] = '| Classification | First-attempt jobs |';
    $lines[] = '|---|---|';
    foreach ($classCounts as $classification => $count) {
        $lines[] = '| `' . $classification . '` | ' . $count . ' |';
    }
    $lines[] = '';
    if ($ownership === []) {
        $lines[] = 'No non-success job on any first attempt.';
    } else {
        uksort($ownership, static function (string $a, string $b) use ($ownership): int {
            return [array_sum($ownership[$b]), $a] <=> [array_sum($ownership[$a]), $b];
        });
        $lines[] = '| Job | Root execution | Setup / infrastructure | Derivative aggregate | Skip / missing prerequisite |';
        $lines[] = '|---|---|---|---|---|';
        foreach ($ownership as $name => $counts) {
            $lines[] = sprintf(
                '| `%s` | %d | %d | %d | %d |',
                $name,
                $counts['root_execution_failure'] ?? 0,
                $counts['setup_or_infrastructure_failure'] ?? 0,
                $counts['derivative_aggregate_failure'] ?? 0,
                $counts['unexpected_skip_or_missing_prerequisite'] ?? 0,
            );
        }
    }
    $lines[] = '';

    // --- 6. Reruns and flakes ------------------------------------------------
    $reruns = (array) ($dataset['classifications']['reruns'] ?? []);
    $lines[] = '## 6. Reruns and flakes';
    $lines[] = '';
    $lines[] = '**Head-SHA rule.** Every attempt of one run id shares one head SHA by';
    $lines[] = 'construction -- a new head produces a *new run id* and is therefore not a rerun';
    $lines[] = 'at all. A job that flips red to green across attempts of the same run id is a';
    $lines[] = 'flake. The property is verified from the collected data (`same_head`), not';
    $lines[] = 'assumed.';
    $lines[] = '';
    if ($reruns === []) {
        $lines[] = 'No run in the cohort has more than one attempt.';
    } else {
        $lines[] = '| Run | Attempts | Same head | Outcome | Flipped red-to-green | Still red |';
        $lines[] = '|---|---|---|---|---|---|';
        foreach ($reruns as $record) {
            $lines[] = sprintf(
                '| `%d` | %s | %s | `%s` | %s | %s |',
                $record['run_id'],
                implode(', ', $record['attempts']),
                $record['same_head'] ? 'yes' : '**no**',
                $record['outcome'],
                $record['flipped_red_to_green'] === [] ? '--' : '`' . implode('`, `', $record['flipped_red_to_green']) . '`',
                $record['still_red'] === [] ? '--' : '`' . implode('`, `', $record['still_red']) . '`',
            );
        }
        $lines[] = '';
        $outcomes = [];
        foreach ($reruns as $record) {
            $outcomes[$record['outcome']] = ($outcomes[$record['outcome']] ?? 0) + 1;
        }
        ksort($outcomes, SORT_STRING);
        $summary = [];
        foreach ($outcomes as $outcome => $count) {
            $summary[] = '`' . $outcome . '` ' . $count;
        }
        $lines[] = 'Rerun outcomes: ' . implode(', ', $summary) . ' over ' . count($reruns) . ' rerun runs.';
    }
    $lines[] = '';

    // --- 7. Random-order uniqueness -----------------------------------------
    $uniqueness = (array) ($dataset['classifications']['random_order_uniqueness']['records'] ?? []);
    $lines[] = '## 7. Random-order uniqueness';
    $lines[] = '';
    $lines[] = 'For every failed `ci/random-order-shard-N` job: did random-order execution detect';
    $lines[] = 'a failing test that the ordinary timing-balanced shards on the same head did not?';
    $lines[] = '';
    $lines[] = 'Random-order shards upload **no JUnit** -- they run PHPUnit with `--no-coverage`';
    $lines[] = 'and no `--log-junit` -- so their failing-test identity exists only in the job';
    $lines[] = 'console log and is `derived` with a parser version and a confidence. The ordinary';
    $lines[] = 'side comes from the `php-test-shard-*` JUnit artifacts on the same head.';
    $lines[] = '';
    $lines[] = 'One bias is recorded rather than corrected: the artifacts API does not say which';
    $lines[] = 'attempt produced an artifact, so on a rerun run the ordinary set may union both';
    $lines[] = 'attempts. Every attempt of a run shares one head SHA, and a larger ordinary set';
    $lines[] = 'can only make a unique-detection verdict harder to reach, so the bias is';
    $lines[] = 'conservative and never inflates `unique_first_pass_detection`.';
    $lines[] = '';
    $lines[] = 'The opposite direction is closed rather than merely biased. A **shrunken** ordinary';
    $lines[] = 'set is what would manufacture a false unique detection, so every way of producing';
    $lines[] = 'one fails closed to `not_classifiable`: an expired or missing artifact, an archive';
    $lines[] = 'with no `junit-*.xml` in it, and a present-but-unparsable JUnit document all stop';
    $lines[] = 'the comparison instead of quietly comparing against fewer tests.';
    $lines[] = '';
    $verdicts = ['not_classifiable' => 0, 'unique_first_pass_detection' => 0, 'corroborating' => 0];
    $notClassifiable = [];
    $confidence = [];
    foreach ($uniqueness as $record) {
        $verdicts[$record['verdict']] = ($verdicts[$record['verdict']] ?? 0) + 1;
        if ($record['verdict'] === 'not_classifiable') {
            $notClassifiable[(string) $record['reason']] = ($notClassifiable[(string) $record['reason']] ?? 0) + 1;
        }
        if ($record['confidence'] !== null) {
            $confidence[(string) $record['confidence']] = ($confidence[(string) $record['confidence']] ?? 0) + 1;
        }
    }
    $lines[] = '**`not_classifiable`: ' . $verdicts['not_classifiable'] . ' of ' . count($uniqueness)
        . ' failed random-order shard jobs.**';
    $lines[] = '';
    if ($notClassifiable === []) {
        $lines[] = 'Every failed random-order shard job was classifiable.';
    } else {
        ksort($notClassifiable, SORT_STRING);
        $lines[] = '| `not_classifiable` reason | Count |';
        $lines[] = '|---|---|';
        foreach ($notClassifiable as $reason => $count) {
            $lines[] = '| ' . $reason . ' | ' . $count . ' |';
        }
    }
    $lines[] = '';
    $lines[] = '| Verdict | Count |';
    $lines[] = '|---|---|';
    foreach (['unique_first_pass_detection', 'corroborating', 'not_classifiable'] as $verdict) {
        $lines[] = '| `' . $verdict . '` | ' . ($verdicts[$verdict] ?? 0) . ' |';
    }
    $lines[] = '';
    if ($confidence !== []) {
        ksort($confidence, SORT_STRING);
        $parts = [];
        foreach ($confidence as $level => $count) {
            $parts[] = '`' . $level . '` ' . $count;
        }
        $lines[] = 'Log-parser confidence over the parsed jobs: ' . implode(', ', $parts)
            . '. `high` means the parsed identity count equals the failures and errors that'
            . ' PHPUnit\'s own summary lines report.';
        $lines[] = '';
    }
    if ($uniqueness !== []) {
        $lines[] = '| Run | Job | Verdict | Random-order failing identities | Unique to random order |';
        $lines[] = '|---|---|---|---|---|';
        foreach ($uniqueness as $record) {
            $lines[] = sprintf(
                '| `%d` | `%s` | `%s` | %d | %s |',
                $record['run_id'],
                $record['name'],
                $record['verdict'],
                count($record['random_order_identities']),
                $record['unique_identities'] === []
                    ? '--'
                    : '`' . implode('`, `', $record['unique_identities']) . '`',
            );
        }
        $lines[] = '';
    }

    // --- 8. Floor -----------------------------------------------------------
    $lines[] = '## 8. Acceptance floor (`docs/specs/ci-test-selection.md` section 9)';
    $lines[] = '';
    $lines[] = 'The spec requires, for a future shard-count change: median and p95 critical-path';
    $lines[] = 'time plus runner minutes from **at least 10 comparable runs**, **one green';
    $lines[] = 'pull-request run**, **one green post-merge `main` run**, and **one green complete';
    $lines[] = 'nightly proof**. This section states whether the *evidence* floor is met. It';
    $lines[] = 'states nothing about whether a shard-count change is warranted.';
    $lines[] = '';
    $lines[] = '| Floor element | Evidence | Status |';
    $lines[] = '|---|---|---|';
    $comparable = 0;
    foreach ($resolved as $summary) {
        if ($summary['enforce_fingerprint']) {
            $comparable += $summary['run_count'];
        }
    }
    $lines[] = sprintf(
        '| >= 10 comparable runs | %d fingerprint-matched runs across the enforced cohorts | %s |',
        $comparable,
        $comparable >= 10 ? 'met' : '**not met**',
    );
    foreach ([
        'pull_request' => 'one green pull-request run',
        'main_push' => 'one green post-merge `main` run',
        'nightly' => 'one green complete nightly proof',
    ] as $cohortId => $label) {
        $green = 0;
        $example = null;
        foreach ($firstAttemptByCohort[$cohortId] ?? [] as $run) {
            if ($run['conclusion'] === 'success') {
                ++$green;
                $example ??= $run['run_id'];
            }
        }
        $lines[] = sprintf(
            '| %s | %d green run(s) in cohort `%s`%s | %s |',
            $label,
            $green,
            $cohortId,
            $example === null ? '' : ', e.g. run `' . $example . '`',
            $green >= 1 ? 'met' : '**not met**',
        );
    }
    $lines[] = '';
    $lines[] = 'Runner minutes are **not** available (see section 3); the cost proxy stands in';
    $lines[] = 'their place and is labelled as a proxy. That element of the floor is therefore';
    $lines[] = 'satisfied only in proxy form, and any future acceptance must say so explicitly.';
    $lines[] = '';

    // --- 9. Missing evidence -------------------------------------------------
    $lines[] = '## 9. Missing evidence';
    $lines[] = '';
    $unmapped = 0;
    $unmappedNames = [];
    $noWall = 0;
    $noQueue = 0;
    foreach ((array) $dataset['jobs'] as $job) {
        if ($job['key'] === null) {
            ++$unmapped;
            $unmappedNames[(string) $job['name']] = ($unmappedNames[(string) $job['name']] ?? 0) + 1;
        }
        if ($job['wall_seconds'] === null) {
            ++$noWall;
        }
        if ($job['queue_seconds'] === null) {
            ++$noQueue;
        }
    }
    $jobTotal = count((array) $dataset['jobs']);
    $lines[] = '- **Billed runner minutes: 0 of ' . $jobTotal . ' jobs.** Structurally unavailable'
        . ' on a public repository; the cost proxy is labelled as a proxy everywhere it appears.';
    $lines[] = '- **Jobs not mapped to an inventory job: ' . $unmapped . ' of ' . $jobTotal . '.** An'
        . ' unmapped job takes no classification that needs inventory lineage.';
    if ($unmappedNames !== []) {
        ksort($unmappedNames, SORT_STRING);
        foreach ($unmappedNames as $name => $count) {
            $lines[] = '  - `' . $name . '` (' . $count . '). A matrix job that is skipped before it'
                . ' expands is reported by GitHub under its *unexpanded* name, which is not a visible'
                . ' context the inventory can bound. The tool records the gap rather than guessing a'
                . ' shard number.';
        }
    }
    $lines[] = '- **Jobs with no wall time: ' . $noWall . '**; **with no queue time: ' . $noQueue . '**.';
    $incompleteRecords = (array) ($dataset['cohort']['incomplete_runs'] ?? []);
    $lines[] = '- **Run attempts in the cohort that GitHub listed no jobs for: '
        . count($incompleteRecords) . '.** Each is kept as a run record with `jobs_listed: 0`'
        . ' and null durations, and named in section 2. Their durations are absent from the'
        . ' section 3 distributions, where they are counted in the `Missing` column.';
    if ($incompleteRecords !== []) {
        $incompleteReasons = [];
        foreach ($incompleteRecords as $entry) {
            $incompleteReasons[(string) $entry['reason']] = ($incompleteReasons[(string) $entry['reason']] ?? 0) + 1;
        }
        ksort($incompleteReasons, SORT_STRING);
        foreach ($incompleteReasons as $reason => $count) {
            $lines[] = '  - `' . $reason . '` (' . $count . ').';
        }
    }
    $lines[] = '- **Random-order shard failures not classifiable: ' . ($verdicts['not_classifiable'] ?? 0)
        . ' of ' . count($uniqueness) . '** (reasons in section 7).';
    $lines[] = '- **First-attempt jobs outside the classification vocabulary: '
        . ($classCounts['unclassified'] ?? 0) . '.**';
    $lines[] = '- **Live check-run names are unobserved.** Job identity here is the hosted job'
        . ' `name` matched against the inventory\'s *derived* visible contexts. The live ruleset'
        . ' and its integration bindings are Task 6, not this baseline.';
    $lines[] = '';
    $lines[] = 'The dataset keeps a per-job step **summary** (step count, the failed steps, and the'
        . ' first of them) rather than the full step list. That summary is not decoration: the';
    $lines[] = 'first failed step is exactly what separates a setup or infrastructure failure from';
    $lines[] = 'an execution failure, so dropping it would leave a classification `derived` from';
    $lines[] = 'evidence the dataset no longer holds.';
    $lines[] = '';

    // --- 10. Boundary -------------------------------------------------------
    $lines[] = '## 10. What this baseline does NOT say';
    $lines[] = '';
    $lines[] = '- It makes **no cadence recommendation**. Whether random-order execution should';
    $lines[] = '  run on every pull request, at what shard width, or at all, is Task 8. Nothing';
    $lines[] = '  here authorizes a workflow, ruleset or policy change.';
    $lines[] = '- A `corroborating` verdict means the random-order failure *also* appeared in an';
    $lines[] = '  ordinary shard **on that head**. It is not evidence that random-order execution';
    $lines[] = '  has no unique detection value in general -- only that it added none on that run.';
    $lines[] = '- The cost proxy is **not** money. No billing figure appears in this document.';
    $lines[] = '- Comparability is structural. Two runs with the same fingerprint may still';
    $lines[] = '  differ in step content, runner image, test count, or dependency versions; the';
    $lines[] = '  fingerprint bounds the *job graph*, nothing else.';
    $lines[] = '- Nothing here re-asserts the offline conformance result (Task 3) or the live';
    $lines[] = '  ruleset (Task 6).';
    $lines[] = '';

    return implode("\n", $lines) . "\n";
}
