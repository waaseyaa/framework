<?php

declare(strict_types=1);

/**
 * Live GitHub projection audit for FW-CI-CHECK-ROSTER-AUDIT-01 Task 6.
 *
 * The functions in this file are transport-free. The CLI owns GitHub access;
 * tests inject the same REST response shapes from fixtures.
 */

const CLA_ERROR = 'error';
const CLA_NOTICE = 'notice';

/** @return array{id: int, strict: bool, contexts: list<array{context: string, integration_id: int|null}>} */
function cla_ruleset_snapshot(array $ruleset): array
{
    $required = null;
    foreach (($ruleset['rules'] ?? []) as $rule) {
        if (is_array($rule) && ($rule['type'] ?? null) === 'required_status_checks') {
            $required = $rule;
            break;
        }
    }
    if (!is_array($required)) {
        throw new RuntimeException('The ruleset has no required_status_checks rule.');
    }

    $parameters = $required['parameters'] ?? null;
    if (!is_array($parameters) || !is_array($parameters['required_status_checks'] ?? null)) {
        throw new RuntimeException('The ruleset required_status_checks rule has no context list.');
    }

    $contexts = [];
    foreach ($parameters['required_status_checks'] as $entry) {
        if (!is_array($entry) || !is_string($entry['context'] ?? null) || $entry['context'] === '') {
            throw new RuntimeException('The ruleset contains an invalid required status-check entry.');
        }
        $integrationId = $entry['integration_id'] ?? null;
        if ($integrationId !== null && !is_int($integrationId)) {
            throw new RuntimeException('The ruleset contains a non-integer integration_id.');
        }
        $contexts[] = ['context' => $entry['context'], 'integration_id' => $integrationId];
    }
    usort($contexts, static fn(array $a, array $b): int => $a['context'] <=> $b['context']);

    return [
        'id' => (int) ($ruleset['id'] ?? 0),
        'strict' => ($parameters['strict_required_status_checks_policy'] ?? false) === true,
        'contexts' => $contexts,
    ];
}

/** @return list<array<string, mixed>> */
function cla_normalize_check_runs(array $payload): array
{
    if (array_is_list($payload)) {
        $runs = [];
        foreach ($payload as $page) {
            if (is_array($page) && is_array($page['check_runs'] ?? null)) {
                foreach ($page['check_runs'] as $run) {
                    if (is_array($run)) {
                        $runs[] = $run;
                    }
                }
                continue;
            }
            if (is_array($page) && is_string($page['name'] ?? null)) {
                $runs[] = $page;
            }
        }

        return $runs;
    }

    $runs = [];
    foreach (($payload['check_runs'] ?? []) as $run) {
        if (is_array($run)) {
            $runs[] = $run;
        }
    }

    return $runs;
}

/** @param list<array<string, mixed>> $runs @return array<string, array<string, mixed>> */
function cla_latest_check_runs(array $runs): array
{
    $latest = [];
    foreach ($runs as $run) {
        $name = $run['name'] ?? null;
        if (!is_string($name) || $name === '') {
            continue;
        }
        $stamp = (string) ($run['completed_at'] ?? $run['started_at'] ?? '');
        $existing = $latest[$name] ?? null;
        $existingStamp = is_array($existing)
            ? (string) ($existing['completed_at'] ?? $existing['started_at'] ?? '')
            : '';
        if ($existing === null || strcmp($stamp, $existingStamp) >= 0) {
            $latest[$name] = $run;
        }
    }

    return $latest;
}

/** @return array<string, array<string, mixed>> */
function cla_inventory_jobs_by_context(array $inventory): array
{
    $index = [];
    foreach (($inventory['workflows'] ?? []) as $workflow) {
        if (!is_array($workflow)) {
            continue;
        }
        foreach (($workflow['jobs'] ?? []) as $job) {
            if (!is_array($job)) {
                continue;
            }
            foreach (($job['contexts'] ?? []) as $context) {
                $name = is_array($context) ? ($context['context'] ?? null) : null;
                if (!is_string($name) || $name === '') {
                    continue;
                }
                $index[$name] = [
                    'workflow' => $workflow['file'] ?? null,
                    'job' => $job['key'] ?? null,
                    'structural_role' => $job['structural_role'] ?? null,
                    'expected_skip' => $job['expected_skip'] ?? null,
                    'aggregate' => $job['aggregate'] ?? null,
                ];
            }
        }
    }

    return $index;
}

/** @return array{class: string, owner: string|null} */
function cla_classify_run(
    array $run,
    ?array $job,
    array $latest,
    array $shadowByContext,
): array {
    $name = (string) ($run['name'] ?? '');
    $conclusion = (string) ($run['conclusion'] ?? '');
    $status = (string) ($run['status'] ?? '');
    $owner = isset($shadowByContext[$name]['failure_owner'])
        ? (string) $shadowByContext[$name]['failure_owner']
        : null;

    if ($status !== 'completed') {
        return ['class' => 'setup-or-infrastructure-failure', 'owner' => $owner];
    }
    if ($conclusion === 'success') {
        return ['class' => 'success', 'owner' => $owner];
    }
    if ($conclusion === 'cancelled') {
        return ['class' => 'cancellation', 'owner' => $owner];
    }
    if ($conclusion === 'skipped') {
        $expectedSkip = is_array($job['expected_skip'] ?? null) ? $job['expected_skip'] : [];
        $inherited = $expectedSkip['inherited_from'] ?? [];
        $expected = ($expectedSkip['own_condition'] ?? false) === true
            || (is_array($inherited) && $inherited !== [])
            || (is_string($inherited) && $inherited !== '');

        return [
            'class' => $expected ? 'expected-conditional-skip' : 'unexpected-skip-or-missing-prerequisite',
            'owner' => $owner,
        ];
    }
    if (isset($shadowByContext[$name])) {
        foreach (($shadowByContext[$name]['prerequisite_contexts'] ?? []) as $prerequisite) {
            $upstream = $latest[$prerequisite] ?? null;
            if (!is_array($upstream)
                || ($upstream['status'] ?? null) !== 'completed'
                || ($upstream['conclusion'] ?? null) !== 'success') {
                return ['class' => 'derivative-aggregate-failure', 'owner' => $owner];
            }
        }
    }
    if (($job['structural_role'] ?? null) === 'publication') {
        return ['class' => 'publication-only-result', 'owner' => $owner];
    }
    if (($job['structural_role'] ?? null) === 'setup') {
        return ['class' => 'setup-or-infrastructure-failure', 'owner' => $owner];
    }

    return ['class' => 'root-execution-failure', 'owner' => $owner];
}

/** @return int|null */
function cla_duration_seconds(array $run): ?int
{
    $started = $run['started_at'] ?? null;
    $completed = $run['completed_at'] ?? null;
    if (!is_string($started) || !is_string($completed)) {
        return null;
    }
    try {
        $seconds = (new DateTimeImmutable($completed))->getTimestamp()
            - (new DateTimeImmutable($started))->getTimestamp();
    } catch (Exception) {
        return null;
    }

    return max(0, $seconds);
}

/**
 * @param list<array<string, mixed>> $checkRuns
 * @return array<string, mixed>
 */
function cla_audit(
    array $policy,
    array $inventory,
    array $ruleset,
    array $checkRuns,
    string $repository,
    string $sha,
): array {
    $findings = [];
    $add = static function (string $code, string $severity, string $message, array $evidence = []) use (&$findings): void {
        $findings[] = [
            'code' => $code,
            'severity' => $severity,
            'message' => $message,
            'evidence' => $evidence,
        ];
    };

    $projection = $policy['policy']['required_projection'] ?? null;
    $shadow = $policy['policy']['stable_aggregate_shadow'] ?? null;
    if (!is_array($projection) || !is_array($shadow) || !is_array($shadow['contexts'] ?? null)) {
        throw new RuntimeException('The policy lacks the required projection or stable aggregate shadow contract.');
    }

    $snapshot = cla_ruleset_snapshot($ruleset);
    $expectedRuleset = [];
    foreach (($projection['contexts'] ?? []) as $entry) {
        if (!is_array($entry) || !is_string($entry['context'] ?? null)) {
            continue;
        }
        $expectedRuleset[$entry['context']] = $entry['binding']['integration_id'] ?? null;
    }
    $actualRuleset = [];
    foreach ($snapshot['contexts'] as $entry) {
        $actualRuleset[$entry['context']] = $entry['integration_id'];
    }
    ksort($expectedRuleset);
    ksort($actualRuleset);
    $stableRuleset = [];
    foreach ($shadow['contexts'] as $entry) {
        if (is_array($entry) && is_string($entry['context'] ?? null)) {
            $stableRuleset[$entry['context']] = (int) ($shadow['integration_id'] ?? 15368);
        }
    }
    ksort($stableRuleset);
    $unionRuleset = $expectedRuleset + $stableRuleset;
    ksort($unionRuleset);
    $projectionMaps = [
        'legacy' => $expectedRuleset,
        'union' => $unionRuleset,
        'final' => $stableRuleset,
    ];
    $migration = $policy['policy']['ruleset_migration'] ?? null;
    $allowedProjectionNames = is_array($migration)
        ? ($migration['allowed_live_projections_during_migration'] ?? [])
        : ['legacy'];
    $allowedProjectionMaps = [];
    foreach ($allowedProjectionNames as $name) {
        if (is_string($name) && isset($projectionMaps[$name])) {
            $allowedProjectionMaps[$name] = $projectionMaps[$name];
        }
    }
    $matchedProjection = array_search($actualRuleset, $allowedProjectionMaps, true);
    if ($snapshot['id'] !== ($projection['source_ruleset_id'] ?? null)) {
        $add('CLA001', CLA_ERROR, 'The live ruleset id differs from the manifest.', ['actual' => $snapshot['id'], 'expected' => $projection['source_ruleset_id'] ?? null]);
    }
    if ($snapshot['strict'] !== ($projection['strict'] ?? null)) {
        $add('CLA002', CLA_ERROR, 'The live strict required-check policy differs from the manifest.', ['actual' => $snapshot['strict'], 'expected' => $projection['strict'] ?? null]);
    }
    if ($matchedProjection === false) {
        $add('CLA003', CLA_ERROR, 'The live required contexts or integration bindings do not match an allowed migration projection.', ['actual' => $actualRuleset, 'allowed' => $allowedProjectionMaps]);
    }

    $latest = cla_latest_check_runs($checkRuns);
    $jobsByContext = cla_inventory_jobs_by_context($inventory);
    $shadowByContext = [];
    foreach ($shadow['contexts'] as $id => $entry) {
        if (is_array($entry) && is_string($entry['context'] ?? null)) {
            $shadowByContext[$entry['context']] = ['id' => $id] + $entry;
        }
    }

    $stableEvidence = [];
    $aggregateSeconds = 0;
    $aggregateDurations = [];
    $expectedAppId = (int) ($shadow['integration_id'] ?? 15368);
    foreach ($shadowByContext as $context => $entry) {
        $run = $latest[$context] ?? null;
        $evidence = [
            'id' => $entry['id'],
            'context' => $context,
            'failure_owner' => $entry['failure_owner'] ?? null,
            'status' => is_array($run) ? ($run['status'] ?? null) : null,
            'conclusion' => is_array($run) ? ($run['conclusion'] ?? null) : null,
            'app_id' => is_array($run) ? ($run['app']['id'] ?? null) : null,
            'details_url' => is_array($run) ? ($run['details_url'] ?? null) : null,
            'duration_seconds' => is_array($run) ? cla_duration_seconds($run) : null,
            'prerequisites' => [],
        ];
        if (!is_array($run)) {
            $add('CLA004', CLA_ERROR, "Stable aggregate {$context} is missing on the audited SHA.");
        } elseif (($run['status'] ?? null) !== 'completed' || ($run['conclusion'] ?? null) !== 'success') {
            $add('CLA005', CLA_ERROR, "Stable aggregate {$context} is not a completed success.", ['status' => $run['status'] ?? null, 'conclusion' => $run['conclusion'] ?? null]);
        }
        if (is_array($run) && ($run['app']['id'] ?? null) !== $expectedAppId) {
            $add('CLA006', CLA_ERROR, "Stable aggregate {$context} is not produced by the expected GitHub App.", ['actual' => $run['app']['id'] ?? null, 'expected' => $expectedAppId]);
        }
        foreach (($entry['prerequisite_contexts'] ?? []) as $prerequisite) {
            $upstream = $latest[$prerequisite] ?? null;
            $prerequisiteEvidence = [
                'context' => $prerequisite,
                'status' => is_array($upstream) ? ($upstream['status'] ?? null) : null,
                'conclusion' => is_array($upstream) ? ($upstream['conclusion'] ?? null) : null,
                'app_id' => is_array($upstream) ? ($upstream['app']['id'] ?? null) : null,
                'details_url' => is_array($upstream) ? ($upstream['details_url'] ?? null) : null,
            ];
            $evidence['prerequisites'][] = $prerequisiteEvidence;
            if (!is_array($upstream)) {
                $add('CLA007', CLA_ERROR, "Prerequisite {$prerequisite} for {$context} is missing on the audited SHA.");
            } elseif (($upstream['status'] ?? null) !== 'completed' || ($upstream['conclusion'] ?? null) !== 'success') {
                $add('CLA008', CLA_ERROR, "Prerequisite {$prerequisite} for {$context} is not a completed success.", ['status' => $upstream['status'] ?? null, 'conclusion' => $upstream['conclusion'] ?? null]);
            }
            $expectedPrerequisiteApp = $expectedRuleset[$prerequisite] ?? null;
            if (is_array($upstream)
                && is_int($expectedPrerequisiteApp)
                && ($upstream['app']['id'] ?? null) !== $expectedPrerequisiteApp) {
                $add('CLA010', CLA_ERROR, "Prerequisite {$prerequisite} for {$context} is not produced by its required GitHub App.", ['actual' => $upstream['app']['id'] ?? null, 'expected' => $expectedPrerequisiteApp]);
            }
        }
        if (!isset($jobsByContext[$context])) {
            $add('CLA009', CLA_ERROR, "The generated inventory does not derive stable context {$context}.");
        }
        if (is_int($evidence['duration_seconds'])) {
            $aggregateSeconds += $evidence['duration_seconds'];
            $aggregateDurations[] = $evidence['duration_seconds'];
        }
        $stableEvidence[] = $evidence;
    }

    $classifications = [];
    foreach ($latest as $name => $run) {
        $classification = cla_classify_run($run, $jobsByContext[$name] ?? null, $latest, $shadowByContext);
        $classifications[] = [
            'context' => $name,
            'status' => $run['status'] ?? null,
            'conclusion' => $run['conclusion'] ?? null,
            'class' => $classification['class'],
            'failure_owner' => $classification['owner'],
            'details_url' => $run['details_url'] ?? null,
        ];
    }
    usort($classifications, static fn(array $a, array $b): int => $a['context'] <=> $b['context']);

    $counts = [CLA_ERROR => 0, CLA_NOTICE => 0];
    foreach ($findings as $finding) {
        ++$counts[$finding['severity']];
    }

    return [
        'kind' => 'ci-roster-live-audit',
        'schema_version' => 1,
        'repository' => $repository,
        'sha' => $sha,
        'ruleset_snapshot' => $snapshot,
        'ruleset_projection' => [
            'matched' => $matchedProjection === false ? null : $matchedProjection,
            'allowed' => array_keys($allowedProjectionMaps),
        ],
        'check_run_count' => count($latest),
        'stable_aggregate_count' => count($stableEvidence),
        'stable_aggregates' => $stableEvidence,
        'measurement' => [
            'sample_size' => 1,
            'aggregate_job_wall_seconds' => $aggregateSeconds,
            'aggregate_cost_proxy_seconds' => $aggregateSeconds,
            'aggregate_max_job_wall_seconds' => $aggregateDurations === [] ? null : max($aggregateDurations),
            'cost_proxy_basis' => 'Observed aggregate job wall time weighted by the Ubuntu multiplier 1.0.',
            'billed_runner_minutes' => null,
            'billed_runner_minutes_status' => 'unavailable',
        ],
        'classifications' => $classifications,
        'missing_evidence' => [
            [
                'id' => 'post-naming-release-matrix-contexts',
                'status' => 'not-observed',
                'statement' => 'No release was dispatched for this audit, so explicit split matrix names await the next normal release run.',
            ],
            [
                'id' => 'billed-runner-minutes',
                'status' => 'unavailable',
                'statement' => 'The public-repository timing API does not provide billed runner minutes; this report uses a labelled cost proxy.',
            ],
        ],
        'counts' => $counts,
        'findings' => $findings,
        'ok' => $counts[CLA_ERROR] === 0,
    ];
}

function cla_render_lines(array $report): string
{
    $lines = [
        sprintf('CI roster live audit for %s@%s', $report['repository'], $report['sha']),
        sprintf(
            'Ruleset %d: %d required contexts (%s projection); stable aggregates: %d; observed check runs: %d.',
            $report['ruleset_snapshot']['id'],
            count($report['ruleset_snapshot']['contexts']),
            $report['ruleset_projection']['matched'] ?? 'unmatched',
            $report['stable_aggregate_count'],
            $report['check_run_count'],
        ),
        sprintf(
            'Aggregate cost proxy: %d job-wall seconds across sample size %d; billed minutes unavailable.',
            $report['measurement']['aggregate_cost_proxy_seconds'],
            $report['measurement']['sample_size'],
        ),
    ];
    foreach ($report['findings'] as $finding) {
        $lines[] = strtoupper((string) $finding['severity']) . " [{$finding['code']}] {$finding['message']}";
    }
    $lines[] = $report['ok']
        ? 'OK: live ruleset bindings and exact-SHA stable aggregate evidence conform.'
        : sprintf('FAIL: %d live audit error(s).', $report['counts'][CLA_ERROR]);

    return implode("\n", $lines) . "\n";
}
