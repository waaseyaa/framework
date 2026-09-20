<?php

declare(strict_types=1);

/**
 * Transport-free ruleset projection for FW-CI-CHECK-ROSTER-AUDIT-01 Task 7.
 *
 * The projector accepts GitHub's ruleset and check-run response shapes. The
 * CLI owns all network access and is dry-run-only unless every apply guard is
 * supplied explicitly.
 */

/** @return array<string, mixed> */
function crp_write_payload(array $ruleset): array
{
    $payload = [];
    foreach (['name', 'target', 'enforcement', 'bypass_actors', 'conditions', 'rules'] as $key) {
        if (!array_key_exists($key, $ruleset)) {
            throw new RuntimeException("The ruleset is missing {$key}.");
        }
        $payload[$key] = $ruleset[$key];
    }

    return $payload;
}

/** @return mixed */
function crp_canonicalize(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map(crp_canonicalize(...), $value);
    }
    ksort($value);
    foreach ($value as $key => $item) {
        $value[$key] = crp_canonicalize($item);
    }

    return $value;
}

function crp_hash(array $value): string
{
    return hash('sha256', json_encode(crp_canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/** @return list<array{context: string, integration_id?: int}> */
function crp_required_contexts(array $payload): array
{
    foreach (($payload['rules'] ?? []) as $rule) {
        if (is_array($rule) && ($rule['type'] ?? null) === 'required_status_checks') {
            $contexts = $rule['parameters']['required_status_checks'] ?? null;
            if (!is_array($contexts) || !array_is_list($contexts)) {
                break;
            }
            foreach ($contexts as $entry) {
                if (!is_array($entry) || !is_string($entry['context'] ?? null) || $entry['context'] === '') {
                    throw new RuntimeException('The ruleset contains an invalid required context.');
                }
                if (isset($entry['integration_id']) && !is_int($entry['integration_id'])) {
                    throw new RuntimeException("Required context {$entry['context']} has an invalid integration_id.");
                }
            }

            return $contexts;
        }
    }
    throw new RuntimeException('The ruleset has no required_status_checks rule.');
}

/** @param list<array{context: string, integration_id?: int}> $contexts @return array<string, mixed> */
function crp_with_required_contexts(array $payload, array $contexts): array
{
    $found = false;
    foreach ($payload['rules'] as &$rule) {
        if (is_array($rule) && ($rule['type'] ?? null) === 'required_status_checks') {
            $rule['parameters']['required_status_checks'] = $contexts;
            $found = true;
            break;
        }
    }
    unset($rule);
    if (!$found) {
        throw new RuntimeException('The ruleset has no required_status_checks rule.');
    }

    return $payload;
}

/** @param list<array{context: string, integration_id?: int}> $contexts @return array<string, int|null> */
function crp_context_map(array $contexts): array
{
    $map = [];
    foreach ($contexts as $entry) {
        $name = $entry['context'];
        if (array_key_exists($name, $map)) {
            throw new RuntimeException("Required context {$name} occurs more than once.");
        }
        $map[$name] = $entry['integration_id'] ?? null;
    }
    ksort($map);

    return $map;
}

/** @return list<array{context: string, integration_id: int}> */
function crp_stable_contexts(array $policy): array
{
    $interface = $policy['policy']['stable_aggregate_interface'] ?? null;
    if (!is_array($interface) || !is_array($interface['contexts'] ?? null)) {
        throw new RuntimeException('The policy has no stable aggregate contract.');
    }
    $integrationId = $interface['integration_id'] ?? null;
    if (!is_int($integrationId)) {
        throw new RuntimeException('The stable aggregate contract has no integer integration_id.');
    }
    $contexts = [];
    foreach ($interface['contexts'] as $entry) {
        $name = is_array($entry) ? ($entry['context'] ?? null) : null;
        if (!is_string($name) || $name === '') {
            throw new RuntimeException('The stable aggregate contract contains an invalid context.');
        }
        $contexts[] = ['context' => $name, 'integration_id' => $integrationId];
    }

    return $contexts;
}

/** @return array<string, mixed> */
function crp_non_context_shape(array $payload): array
{
    return crp_with_required_contexts($payload, []);
}

/**
 * @return array{
 *   predecessor: list<list<array{context: string, integration_id?: int}>>,
 *   target: list<array{context: string, integration_id?: int}>
 * }
 */
function crp_phase_projection(string $phase, array $baseline, array $policy): array
{
    $legacy = crp_required_contexts($baseline);
    $stable = crp_stable_contexts($policy);
    $union = array_merge($legacy, $stable);

    return match ($phase) {
        'union' => ['predecessor' => [$legacy], 'target' => $union],
        'final' => ['predecessor' => [$union], 'target' => $stable],
        'rollback' => ['predecessor' => [$union, $stable], 'target' => $legacy],
        default => throw new InvalidArgumentException('Phase must be union, final, or rollback.'),
    };
}

/** @param list<array<string, mixed>> $runs @return array<string, array<string, mixed>> */
function crp_latest_runs(array $runs): array
{
    $latest = [];
    foreach ($runs as $run) {
        $name = $run['name'] ?? null;
        if (!is_string($name) || $name === '') {
            continue;
        }
        $stamp = (string) ($run['completed_at'] ?? $run['started_at'] ?? '');
        $previous = $latest[$name] ?? null;
        $previousStamp = is_array($previous)
            ? (string) ($previous['completed_at'] ?? $previous['started_at'] ?? '')
            : '';
        if ($previous === null || strcmp($stamp, $previousStamp) >= 0) {
            $latest[$name] = $run;
        }
    }

    return $latest;
}

/** @return list<array<string, mixed>> */
function crp_normalize_check_runs(array $payload): array
{
    if (!array_is_list($payload)) {
        return array_values(array_filter($payload['check_runs'] ?? [], 'is_array'));
    }
    $runs = [];
    foreach ($payload as $item) {
        if (is_array($item) && is_array($item['check_runs'] ?? null)) {
            foreach ($item['check_runs'] as $run) {
                if (is_array($run)) {
                    $runs[] = $run;
                }
            }
        } elseif (is_array($item) && is_string($item['name'] ?? null)) {
            $runs[] = $item;
        }
    }

    return $runs;
}

/** @return list<array{context: string, app_id: int|null}> */
function crp_verify_evidence(array $policy, array $legacyContexts, array $checkRuns): array
{
    $interface = $policy['policy']['stable_aggregate_interface'] ?? null;
    if (!is_array($interface) || !is_array($interface['contexts'] ?? null)) {
        throw new RuntimeException('The policy lacks ruleset projection evidence contracts.');
    }
    $expected = [];
    foreach ($legacyContexts as $entry) {
        if (is_array($entry) && is_string($entry['context'] ?? null)) {
            $expected[$entry['context']] = $entry['integration_id'] ?? null;
        }
    }
    $stableApp = $interface['integration_id'] ?? null;
    foreach ($interface['contexts'] as $entry) {
        if (!is_array($entry) || !is_string($entry['context'] ?? null)) {
            throw new RuntimeException('The policy has an invalid stable aggregate context.');
        }
        $expected[$entry['context']] = $stableApp;
        foreach (($entry['prerequisite_contexts'] ?? []) as $prerequisite) {
            if (!array_key_exists($prerequisite, $expected)) {
                throw new RuntimeException("Stable aggregate prerequisite {$prerequisite} is absent from the legacy projection.");
            }
        }
    }

    $latest = crp_latest_runs($checkRuns);
    $evidence = [];
    foreach ($expected as $context => $appId) {
        $run = $latest[$context] ?? null;
        if (!is_array($run)) {
            throw new RuntimeException("Exact-SHA evidence is missing {$context}.");
        }
        if (($run['status'] ?? null) !== 'completed' || ($run['conclusion'] ?? null) !== 'success') {
            throw new RuntimeException("Exact-SHA evidence for {$context} is not a completed success.");
        }
        $actualApp = $run['app']['id'] ?? null;
        if (is_int($appId) && $actualApp !== $appId) {
            throw new RuntimeException("Exact-SHA evidence for {$context} has app id " . json_encode($actualApp) . ", expected {$appId}.");
        }
        $evidence[] = ['context' => $context, 'app_id' => is_int($actualApp) ? $actualApp : null];
    }

    return $evidence;
}

/** @return array<string, mixed> */
function crp_plan(
    string $phase,
    array $baseline,
    array $policy,
    array $liveRuleset,
    array $checkRuns,
    string $repository,
    string $evidenceSha,
): array {
    $rulesetId = $policy['policy']['required_projection']['source_ruleset_id'] ?? null;
    if (!is_int($rulesetId) || ($liveRuleset['id'] ?? null) !== $rulesetId) {
        throw new RuntimeException('The live ruleset id differs from the governed policy.');
    }
    $baselinePayload = crp_write_payload($baseline);
    $livePayload = crp_write_payload($liveRuleset);
    if (crp_hash(crp_non_context_shape($livePayload)) !== crp_hash(crp_non_context_shape($baselinePayload))) {
        throw new RuntimeException('A non-status ruleset field differs from the tracked baseline.');
    }

    $projection = crp_phase_projection($phase, $baselinePayload, $policy);
    $actualMap = crp_context_map(crp_required_contexts($livePayload));
    $allowed = array_map(static fn(array $contexts): array => crp_context_map($contexts), $projection['predecessor']);
    if (!in_array($actualMap, $allowed, true)) {
        throw new RuntimeException("The live required-check projection is not an allowed predecessor for {$phase}.");
    }
    $evidence = $phase === 'rollback'
        ? []
        : crp_verify_evidence($policy, crp_required_contexts($baselinePayload), $checkRuns);
    $targetPayload = crp_with_required_contexts($baselinePayload, $projection['target']);

    return [
        'schema_version' => 1,
        'phase' => $phase,
        'repository' => $repository,
        'ruleset_id' => $rulesetId,
        'evidence_sha' => $evidenceSha,
        'before' => [
            'hash' => crp_hash($livePayload),
            'required_context_count' => count($actualMap),
            'required_contexts' => $actualMap,
        ],
        'after' => [
            'hash' => crp_hash($targetPayload),
            'required_context_count' => count($projection['target']),
            'required_contexts' => crp_context_map($projection['target']),
        ],
        'verified_check_count' => count($evidence),
        'verified_checks' => $evidence,
        'payload' => $targetPayload,
    ];
}
