<?php

declare(strict_types=1);

/**
 * Offline conformance verifier for the governed CI policy
 * (FW-CI-CHECK-ROSTER-AUDIT-01, Task 3; GitHub mirror #3087).
 *
 * `bin/check-ci-roster-conformance` compares the hand-authored policy
 * (`tools/ci-check-roster.json`) with the generated workflow inventory
 * (`tools/ci-workflow-inventory.json`) and reports every mismatch. It never
 * reads `.github/workflows/*.yml`: the inventory is the only structural
 * evidence, so the verifier can say exactly what it checked and what it could
 * not check offline.
 *
 * Plain functions with a `crc_` prefix. No Composer dependency.
 *
 * Three severities, and silence never means a pass:
 *
 *   error                — the inventory positively contradicts a policy
 *                          claim, or a claim that must have inventory
 *                          evidence has none. Exit code 1.
 *   notice               — the inventory carries structure the policy does not
 *                          model. Never the reverse, never an exit code.
 *   not-verified-offline — the check needs live GitHub state (the ruleset).
 *                          Emitted explicitly so nobody reads its absence as
 *                          a pass. Never an exit code.
 *
 * Deliberately out of offline scope, and why:
 *
 *   - Live ruleset state (id, strict flag, bound contexts, GitHub App
 *     integration 15368). Task 6. Checked here only against a frozen
 *     `--ruleset` snapshot; otherwise CRC026 reports not-verified-offline.
 *     The snapshot schema CRC026 expects is:
 *
 *       {
 *         "id": <int>,
 *         "strict": <bool>,
 *         "contexts": [
 *           {"context": <string>, "integration_id": <int|null>}
 *         ]
 *       }
 *
 *     Task 6 must emit exactly that shape from the live ruleset:
 *     `GET /repos/{owner}/{repo}/rulesets/{id}`, whose
 *     `required_status_checks` rule carries one entry per required check with
 *     its `context` and `integration_id` (null for an intentionally name-only
 *     binding, as `ci/mutation-pilot` is).
 *   - Check-run names as GitHub actually renders them, in particular the
 *     object-matrix default naming that carries 77 of the 160 visible
 *     contexts (`split.yml#split`). The inventory records that extension as
 *     unverified and so does this verifier.
 *   - Attestation-subject profiles. No offline artefact records which SHA a
 *     hosted run attested; the policy self-validates their shape in Task 1
 *     and this verifier does not re-assert it.
 *   - Expression evaluation. Only the inventory's `if` CLASSIFICATION is
 *     compared; no condition is ever evaluated.
 *   - Policy `role` versus the inventory's `structural_role`. The inventory's
 *     own derivation rules describe that label as a structural heuristic that
 *     "is not the policy role vocabulary" and that "deliberately collides"
 *     (`ci-test-shards` is structurally `setup` and `execution` in policy).
 */

const CRC_ERROR = 'error';
const CRC_NOTICE = 'notice';
const CRC_UNVERIFIED = 'not-verified-offline';

/**
 * Cadence to trigger evidence. Each entry names the trigger event a cadence
 * needs and, where the event alone is not enough, the selector key that must
 * be present (and the value that key must contain).
 */
const CRC_CADENCE_TRIGGERS = [
    'pull-request' => ['event' => 'pull_request'],
    'main' => ['event' => 'push', 'selector' => 'branches', 'value' => 'main'],
    'scheduled' => ['event' => 'schedule'],
    'release' => ['event' => 'push', 'selector' => 'tags'],
    'manual' => ['event' => 'workflow_dispatch'],
    'merge-group' => ['event' => 'merge_group'],
];

/**
 * Every verifier finding, in rule order.
 *
 * @param array<string, mixed> $policy
 * @param array<string, mixed> $inventory
 * @param array<string, mixed>|null $ruleset frozen ruleset snapshot, or null
 * @return list<array<string, string>>
 */
function crc_verify(array $policy, array $inventory, ?array $ruleset = null): array
{
    $findings = [];
    $index = crc_index($inventory);
    $bindings = $policy['bindings'] ?? [];

    crc_check_bindings($policy, $index, $bindings, $findings);
    crc_check_required_contexts($policy, $index, $bindings, $findings);
    crc_check_producers($policy, $index, $bindings, $findings);
    crc_check_trigger_coverage($policy, $index, $bindings, $findings);
    crc_check_aggregates($policy, $index, $bindings, $findings);
    crc_check_artifacts($policy, $index, $bindings, $findings);
    crc_check_cadences($policy, $index, $bindings, $findings);
    crc_check_integration_bindings($policy, $ruleset, $findings);
    crc_check_coverage($policy, $index, $bindings, $findings);
    crc_check_verifiable_shape($policy, $bindings, $findings);

    return $findings;
}

/**
 * CRC030: the policy fields this verifier iterates must not be empty or
 * unbound, or a rule would report nothing and read as a pass.
 *
 * This is a narrow backstop, not a schema validator. The policy's own schema —
 * vocabularies, stable ids, complete cadence-specific subject profiles, the
 * eight owned invariants, the unique 22-context projection, the aggregate
 * terminal-result and not-applicable rules, the bounded artifact contract —
 * is proved by `tests/Architecture/CiCheckRosterManifestTest.php`, the Task 1
 * self-validator, and this verifier assumes all of it. What it cannot assume
 * is that an iteration has anything to iterate: an aggregate with no
 * prerequisites, a measurement naming a producer that does not exist, or an
 * empty required projection would each silence a rule instead of failing it.
 *
 * @param array<string, mixed> $policy
 * @param array<string, mixed> $bindings
 * @param list<array<string, string>> $findings
 */
function crc_check_verifiable_shape(array $policy, array $bindings, array &$findings): void
{
    foreach ($policy['policy']['aggregate_lineage_contract']['aggregates'] ?? [] as $aggregate) {
        $id = (string) ($aggregate['producer_id'] ?? '?');
        if (($aggregate['prerequisites'] ?? []) === []) {
            crc_add($findings, 'CRC030', CRC_ERROR, sprintf('aggregate_lineage_contract.aggregates[%s].prerequisites', $id), '(not verifiable)', 'the aggregate declares no prerequisites', 'at least one prerequisite, or no aggregate entry — an empty list silences the lineage rules instead of proving them');
        }
    }

    $measurementProducer = $policy['policy']['random_order_measurement']['producer_id'] ?? null;
    if (!is_string($measurementProducer) || crc_producer($policy, $measurementProducer) === [] || !is_array($bindings['producers'][$measurementProducer] ?? null)) {
        crc_add($findings, 'CRC030', CRC_ERROR, 'random_order_measurement.producer_id', '(not verifiable)', sprintf('producer_id is %s, which is not a bound policy producer', crc_json($measurementProducer)), 'a producer id that exists in policy.producers and carries a binding, so the measured width can be compared');
    }

    if (($policy['policy']['required_projection']['contexts'] ?? []) === []) {
        crc_add($findings, 'CRC030', CRC_ERROR, 'required_projection.contexts', '(not verifiable)', 'the required projection is empty', 'at least one required context — an empty projection silences every context rule instead of proving them');
    }
}

/**
 * Inventory lookups the rules need: jobs by workflow and key, visible-context
 * owners across the whole repository, and each workflow's trigger events.
 *
 * @param array<string, mixed> $inventory
 * @return array{workflows: array<string, array<string, mixed>>, jobs: array<string, array<string, array<string, mixed>>>, context_owners: array<string, list<string>>}
 */
function crc_index(array $inventory): array
{
    $workflows = [];
    $jobs = [];
    $owners = [];
    foreach ($inventory['workflows'] ?? [] as $workflow) {
        $file = $workflow['file'];
        $workflows[$file] = $workflow;
        foreach ($workflow['jobs'] as $job) {
            $jobs[$file][$job['key']] = $job;
            foreach ($job['contexts'] ?? [] as $context) {
                if (($context['context'] ?? null) !== null) {
                    $owners[$context['context']][] = $file . '#' . $job['key'];
                }
            }
        }
    }
    foreach ($owners as $name => $list) {
        $owners[$name] = array_values(array_unique($list));
    }

    return ['workflows' => $workflows, 'jobs' => $jobs, 'context_owners' => $owners];
}

/**
 * One finding.
 *
 * @param list<array<string, string>> $findings
 */
function crc_add(array &$findings, string $rule, string $severity, string $policyLocator, string $inventoryLocator, string $current, string $expected): void
{
    $findings[] = [
        'rule' => $rule,
        'severity' => $severity,
        'policy' => $policyLocator,
        'inventory' => $inventoryLocator,
        'current' => $current,
        'expected' => $expected,
    ];
}

/**
 * CRC001-CRC003: every binding names something the inventory contains.
 *
 * @param array<string, mixed> $policy
 * @param array{workflows: array<string, array<string, mixed>>, jobs: array<string, array<string, array<string, mixed>>>, context_owners: array<string, list<string>>} $index
 * @param array<string, mixed> $bindings
 * @param list<array<string, string>> $findings
 */
function crc_check_bindings(array $policy, array $index, array $bindings, array &$findings): void
{
    foreach ($bindings['workflow_policies'] ?? [] as $policyId => $file) {
        if (!isset($index['workflows'][$file])) {
            crc_add($findings, 'CRC001', CRC_ERROR, sprintf('bindings.workflow_policies[%s]', $policyId), (string) $file, (string) $file, 'a workflow file present in the inventory');
        }
    }
    foreach ($bindings['producers'] ?? [] as $id => $binding) {
        if (crc_job($index, $binding) === null) {
            crc_add($findings, 'CRC002', CRC_ERROR, sprintf('bindings.producers[%s]', $id), crc_locator($binding), crc_locator($binding), 'an existing job key in that workflow');
        }
    }
    foreach ($bindings['required_contexts'] ?? [] as $name => $binding) {
        if (crc_job($index, $binding) === null) {
            crc_add($findings, 'CRC003', CRC_ERROR, sprintf('required_contexts[%s]', $name), crc_locator($binding), crc_locator($binding), 'an existing job key in that workflow');
        }
    }
    $recovery = crc_producer($policy, 'release-publish-evidence')['recovery_producer'] ?? null;
    if (is_array($recovery) && crc_job($index, $recovery) === null) {
        crc_add($findings, 'CRC002', CRC_ERROR, 'producers[release-publish-evidence].recovery_producer', crc_locator($recovery), crc_locator($recovery), 'an existing job key in that workflow');
    }
}

/**
 * CRC004-CRC007: a required context is produced by exactly the job it binds,
 * in a workflow that can run on a pull request, with no duplicate collision.
 *
 * @param array<string, mixed> $policy
 * @param array{workflows: array<string, array<string, mixed>>, jobs: array<string, array<string, array<string, mixed>>>, context_owners: array<string, list<string>>} $index
 * @param array<string, mixed> $bindings
 * @param list<array<string, string>> $findings
 */
function crc_check_required_contexts(array $policy, array $index, array $bindings, array &$findings): void
{
    foreach ($policy['policy']['required_projection']['contexts'] ?? [] as $item) {
        $name = $item['context'] ?? '?';
        $locator = sprintf('required_projection.contexts[%s]', $name);
        $binding = $bindings['required_contexts'][$name] ?? null;
        if (!is_array($binding)) {
            crc_add($findings, 'CRC003', CRC_ERROR, $locator, '(none)', 'unbound', 'a binding naming the job that produces this context');
            continue;
        }
        $job = crc_job($index, $binding);
        if ($job === null) {
            continue;
        }
        $bound = crc_locator($binding);

        $derivations = [];
        foreach ($job['contexts'] ?? [] as $context) {
            if (($context['context'] ?? null) === $name) {
                $derivations[] = $context['derivation'] ?? 'null';
            }
        }
        if ($derivations === []) {
            crc_add($findings, 'CRC004', CRC_ERROR, $locator, $bound, sprintf('job contexts are [%s]', implode(', ', crc_context_names($job))), sprintf('a visible context named %s', $name));
        }

        $owners = $index['context_owners'][$name] ?? [];
        if ($owners !== [$bound]) {
            crc_add($findings, 'CRC005', CRC_ERROR, $locator, $bound, sprintf('inventory owners are [%s]', implode(', ', $owners)), sprintf('exactly one owner, [%s]', $bound));
        }

        $workflow = $index['workflows'][$binding['workflow']] ?? [];
        if (!in_array('pull_request', $workflow['triggers']['events'] ?? [], true)) {
            crc_add($findings, 'CRC006', CRC_ERROR, $locator, $bound, sprintf('%s triggers are [%s]', $binding['workflow'], implode(', ', $workflow['triggers']['events'] ?? [])), 'a pull_request trigger, so a required merge check is producible on a pull request');
        }

        foreach ($workflow['duplicate_contexts'] ?? [] as $duplicate) {
            $duplicateName = is_array($duplicate) ? ($duplicate['context'] ?? null) : $duplicate;
            if ($duplicateName === $name) {
                crc_add($findings, 'CRC007', CRC_ERROR, $locator, $bound, sprintf('%s reports %s as a duplicate visible context', $binding['workflow'], $name), 'a required context produced by exactly one job in its workflow');
            }
        }
    }
}

/**
 * CRC008-CRC016: expansion, matrix width, random-order width, selection and
 * disposition, each against the bound job.
 *
 * @param array<string, mixed> $policy
 * @param array{workflows: array<string, array<string, mixed>>, jobs: array<string, array<string, array<string, mixed>>>, context_owners: array<string, list<string>>} $index
 * @param array<string, mixed> $bindings
 * @param list<array<string, string>> $findings
 */
function crc_check_producers(array $policy, array $index, array $bindings, array &$findings): void
{
    foreach ($policy['policy']['producers'] ?? [] as $producer) {
        $id = $producer['id'] ?? '?';
        $locator = sprintf('producers[%s]', $id);
        $binding = $bindings['producers'][$id] ?? null;
        if (!is_array($binding)) {
            crc_add($findings, 'CRC002', CRC_ERROR, $locator, '(none)', 'unbound', 'a binding naming the job that implements this producer');
            continue;
        }
        $job = crc_job($index, $binding);
        if ($job === null) {
            continue;
        }
        $bound = crc_locator($binding);

        // CRC008 / CRC009 / CRC010 - expansion and bounded matrix width.
        if (($producer['expansion'] ?? null) !== ($job['expansion'] ?? null)) {
            crc_add($findings, 'CRC008', CRC_ERROR, $locator, $bound, sprintf('job expansion is %s', (string) ($job['expansion'] ?? 'null')), sprintf('expansion %s', (string) ($producer['expansion'] ?? 'null')));
        } elseif (($producer['expansion'] ?? null) === 'matrix') {
            $resolution = $job['matrix']['resolution'] ?? null;
            $axes = $job['matrix']['axes'] ?? null;
            if ($resolution !== 'literal' || !is_array($axes)) {
                crc_add($findings, 'CRC009', CRC_ERROR, $locator, $bound, sprintf('job matrix resolution is %s', (string) ($resolution ?? 'null')), 'a literal matrix, because the policy claims a bounded width');
            } else {
                $axis = $producer['matrix']['axis'] ?? null;
                $values = $producer['matrix']['values'] ?? null;
                if (!is_string($axis) || !array_key_exists($axis, $axes)) {
                    crc_add($findings, 'CRC010', CRC_ERROR, $locator, $bound, sprintf('job matrix axes are [%s]', implode(', ', array_keys($axes))), sprintf('an axis named %s', is_string($axis) ? $axis : 'null'));
                } elseif ($axes[$axis] !== $values) {
                    crc_add($findings, 'CRC009', CRC_ERROR, $locator, $bound, sprintf('job axis %s is %s', $axis, crc_json($axes[$axis])), sprintf('axis %s = %s', $axis, crc_json($values)));
                }
            }
        }

        // CRC012 / CRC013 / CRC014 - selection evidence.
        crc_check_selection($producer, $index, $bindings, $job, $bound, $findings);

        // CRC015 / CRC016 - disposition against the job's expected-skip record.
        $conditional = ($job['expected_skip']['own_condition'] ?? false) === true
            || ($job['expected_skip']['inherited_from'] ?? []) !== [];
        $disposition = $producer['disposition'] ?? null;
        if ($disposition === 'expected-skip' && !$conditional) {
            crc_add($findings, 'CRC015', CRC_ERROR, $locator, $bound, 'job has no own condition and inherits none', 'a conditional job, because the policy disposition is expected-skip');
        }
        if ($disposition === 'required' && $conditional) {
            crc_add($findings, 'CRC016', CRC_ERROR, $locator, $bound, sprintf('job own_condition=%s inherited_from=[%s]', ($job['expected_skip']['own_condition'] ?? false) ? 'true' : 'false', implode(', ', $job['expected_skip']['inherited_from'] ?? [])), 'an unconditional job, because a required check must not be skippable');
        }
    }

    // CRC011 - the measurement width is the bound job's literal width.
    $measurement = $policy['policy']['random_order_measurement'] ?? [];
    $producerId = $measurement['producer_id'] ?? '';
    $binding = $bindings['producers'][$producerId] ?? null;
    $job = is_array($binding) ? crc_job($index, $binding) : null;
    if ($job !== null) {
        $axis = crc_producer($policy, $producerId)['matrix']['axis'] ?? null;
        $actual = is_string($axis) ? ($job['matrix']['axes'][$axis] ?? null) : null;
        if ($actual !== ($measurement['active_shards'] ?? null)) {
            crc_add($findings, 'CRC011', CRC_ERROR, 'random_order_measurement.active_shards', crc_locator($binding), sprintf('job axis %s is %s', (string) $axis, crc_json($actual)), sprintf('the declared active shards %s', crc_json($measurement['active_shards'] ?? null)));
        }
    }
}

/**
 * CRC012-CRC014. Selector evidence comes from the bound job's `if`
 * classification and from its workflow's trigger selectors. A producer that
 * declares a `recovery_producer` may satisfy a selector from either workflow,
 * and the finding names which one carried it.
 *
 * @param array<string, mixed> $producer
 * @param array{workflows: array<string, array<string, mixed>>, jobs: array<string, array<string, array<string, mixed>>>, context_owners: array<string, list<string>>} $index
 * @param array<string, mixed> $bindings
 * @param array<string, mixed> $job
 * @param list<array<string, string>> $findings
 */
function crc_check_selection(array $producer, array $index, array $bindings, array $job, string $bound, array &$findings): void
{
    $id = $producer['id'] ?? '?';
    $locator = sprintf('producers[%s].selection', $id);
    $leaves = crc_selector_leaves($producer['selection'] ?? []);

    // A producer is unconditional when EVERY leaf is, however the selection is
    // composed and whatever extra keys a leaf carries. An exact-array identity
    // here would let `all_of[unconditional, unconditional]`, or a leaf with a
    // stray key, skip the rule entirely, because the leaf loop below treats
    // `unconditional` as nothing to check.
    $types = array_column($leaves, 'type');
    if ($types !== [] && array_values(array_unique($types)) === ['unconditional']) {
        $condition = $job['if'] ?? null;
        if ($condition !== null && ($condition['classification'] ?? []) !== ['always']) {
            // An EMPTY classification is a failure, not a pass: the inventory
            // classifies what an expression references, and it has no
            // classifier for `vars.*` or `env.*`, so `if: ${{ vars.RUN_EXTRA
            // == 'yes' }}` classifies as [] while still being able to skip the
            // job. Only `always()` cannot skip a job.
            $classification = $condition['classification'] ?? [];
            crc_add($findings, 'CRC012', CRC_ERROR, $locator, $bound, sprintf('job if is %s classified [%s]', (string) ($condition['normalized'] ?? $condition['raw'] ?? '?'), implode(', ', $classification)), 'no job-level if, or a condition classified exactly as always(), which is the only condition that cannot skip the job');
        }

        return;
    }

    $sources = [$bound => [$job, $index['workflows'][crc_file($bound)] ?? []]];
    $recovery = $producer['recovery_producer'] ?? null;
    if (is_array($recovery)) {
        $recoveryJob = crc_job($index, $recovery);
        if ($recoveryJob !== null) {
            $sources[crc_locator($recovery)] = [$recoveryJob, $index['workflows'][$recovery['workflow']] ?? []];
        }
    }

    foreach ($leaves as $leaf) {
        $type = $leaf['type'] ?? '?';
        if ($type === 'unconditional') {
            continue;
        }
        $carrier = null;
        foreach ($sources as $sourceLocator => [$sourceJob, $sourceWorkflow]) {
            if (crc_selector_is_evidenced($leaf, $sourceJob, $sourceWorkflow)) {
                $carrier = $sourceLocator;
                break;
            }
        }
        if ($carrier === null) {
            crc_add($findings, 'CRC013', CRC_ERROR, $locator, implode(' or ', array_keys($sources)), sprintf('no trigger selector or if classification evidences %s', crc_selector_label($leaf)), sprintf('inventory evidence for the %s selector %s', $type, crc_selector_label($leaf)));
        }
    }
}

/**
 * CRC014: the reverse direction of CRC013 — a trigger the inventory declares
 * that no producer bound to that workflow models, by selector or by cadence.
 * Extra evidence is never an error; the policy is a deliberate subset.
 *
 * Computed once per bound workflow rather than per producer, so a workflow
 * with nine bound producers reports an unmodelled trigger once.
 *
 * @param array<string, mixed> $policy
 * @param array{workflows: array<string, array<string, mixed>>, jobs: array<string, array<string, array<string, mixed>>>, context_owners: array<string, list<string>>} $index
 * @param array<string, mixed> $bindings
 * @param list<array<string, string>> $findings
 */
function crc_check_trigger_coverage(array $policy, array $index, array $bindings, array &$findings): void
{
    /** @var array<string, list<string>> $modelled */
    $modelled = [];
    foreach ($policy['policy']['producers'] ?? [] as $producer) {
        $binding = $bindings['producers'][$producer['id'] ?? ''] ?? null;
        if (!is_array($binding)) {
            continue;
        }
        $files = [$binding['workflow']];
        $recovery = $producer['recovery_producer'] ?? null;
        if (is_array($recovery) && isset($recovery['workflow'])) {
            $files[] = $recovery['workflow'];
        }
        $events = crc_cadence_events($producer);
        foreach (crc_selector_leaves($producer['selection'] ?? []) as $leaf) {
            if (is_string($leaf['event'] ?? null)) {
                $events[] = crc_event_name($leaf['event']);
            }
        }
        foreach ($files as $file) {
            $modelled[$file] = array_merge($modelled[$file] ?? [], $events);
        }
    }
    foreach ($modelled as $file => $events) {
        $workflow = $index['workflows'][$file] ?? [];
        foreach ($workflow['triggers']['events'] ?? [] as $event) {
            if (!in_array($event, $events, true)) {
                crc_add($findings, 'CRC014', CRC_NOTICE, 'producers', $file, sprintf('%s declares a %s trigger', $file, $event), sprintf('a policy selector or cadence modelling %s, or no such trigger', $event));
            }
        }
    }
}

/**
 * CRC017-CRC021: aggregate lineage, fail-closed prerequisite proof, and the
 * dependency edges between bound jobs that the policy does not model.
 *
 * @param array<string, mixed> $policy
 * @param array{workflows: array<string, array<string, mixed>>, jobs: array<string, array<string, array<string, mixed>>>, context_owners: array<string, list<string>>} $index
 * @param array<string, mixed> $bindings
 * @param list<array<string, string>> $findings
 */
function crc_check_aggregates(array $policy, array $index, array $bindings, array &$findings): void
{
    $contract = $policy['policy']['aggregate_lineage_contract'] ?? [];
    $semantics = rtrim((string) ($contract['condition_semantics'] ?? 'always()'), '()');
    $modelled = [];

    foreach ($contract['aggregates'] ?? [] as $aggregate) {
        $ownerId = $aggregate['producer_id'] ?? '?';
        $locator = sprintf('aggregate_lineage_contract.aggregates[%s]', $ownerId);
        $ownerBinding = $bindings['producers'][$ownerId] ?? null;
        $ownerJob = is_array($ownerBinding) ? crc_job($index, $ownerBinding) : null;
        if ($ownerJob === null) {
            continue;
        }
        $ownerLocator = crc_locator($ownerBinding);

        if (($ownerJob['aggregate'] ?? null) === null) {
            crc_add($findings, 'CRC017', CRC_ERROR, $locator, $ownerLocator, 'job is not an inventory aggregate', 'a job with needs whose if carries an aggregate-qualifying token');
            continue;
        }
        if (($ownerJob['aggregate']['gate'] ?? null) !== $semantics) {
            crc_add($findings, 'CRC018', CRC_ERROR, $locator, $ownerLocator, sprintf('job aggregate gate is %s', (string) ($ownerJob['aggregate']['gate'] ?? 'null')), sprintf('gate %s, the policy condition semantics', $semantics));
        }

        foreach ($aggregate['prerequisites'] ?? [] as $prerequisite) {
            $prerequisiteId = $prerequisite['producer_id'] ?? '?';
            $prerequisiteBinding = $bindings['producers'][$prerequisiteId] ?? null;
            $prerequisiteJob = is_array($prerequisiteBinding) ? crc_job($index, $prerequisiteBinding) : null;
            if ($prerequisiteJob === null) {
                continue;
            }
            $key = $prerequisiteBinding['job'];
            $modelled[] = $ownerLocator . '<-' . crc_locator($prerequisiteBinding);

            if ($prerequisiteBinding['workflow'] !== $ownerBinding['workflow']) {
                crc_add($findings, 'CRC020', CRC_ERROR, $locator, $ownerLocator, sprintf('prerequisite %s lives in %s', $prerequisiteId, $prerequisiteBinding['workflow']), sprintf('the same workflow as the aggregate (%s), because ownership is workflow-local', $ownerBinding['workflow']));
                continue;
            }
            if (!in_array($key, $ownerJob['needs'] ?? [], true)) {
                crc_add($findings, 'CRC019', CRC_ERROR, $locator, $ownerLocator, sprintf('job needs are [%s]', implode(', ', $ownerJob['needs'] ?? [])), sprintf('needs to contain %s, the bound job of prerequisite %s', $key, $prerequisiteId));
                continue;
            }
            if (!in_array($key, $ownerJob['aggregate']['result_checked_prerequisites'] ?? [], true)) {
                crc_add($findings, 'CRC019', CRC_ERROR, $locator, $ownerLocator, sprintf('job result-checks [%s]', implode(', ', $ownerJob['aggregate']['result_checked_prerequisites'] ?? [])), sprintf('a needs.%s.result reference, because an always() aggregate fails closed only on an explicit result check', $key));
            }
        }
    }

    // CRC021 - dependency edges between bound jobs the policy does not model.
    $boundJobs = [];
    foreach ($bindings['producers'] ?? [] as $id => $binding) {
        if (is_array($binding)) {
            $boundJobs[crc_locator($binding)] = $id;
        }
    }
    foreach ($bindings['producers'] ?? [] as $id => $binding) {
        $job = is_array($binding) ? crc_job($index, $binding) : null;
        if ($job === null) {
            continue;
        }
        $locator = crc_locator($binding);
        foreach ($job['needs'] ?? [] as $need) {
            $needLocator = $binding['workflow'] . '#' . $need;
            if (!isset($boundJobs[$needLocator]) || in_array($locator . '<-' . $needLocator, $modelled, true)) {
                continue;
            }
            crc_add($findings, 'CRC021', CRC_NOTICE, sprintf('producers[%s]', $id), $locator, sprintf('job needs %s, bound to producer %s', $needLocator, $boundJobs[$needLocator]), 'a policy lineage entry, or no modelled relationship (dependency edges outside the aggregate contract are not policy)');
        }
    }
}

/**
 * CRC022-CRC024: the bounded artifact family, the pattern consumer, and the
 * glob that ties them together.
 *
 * @param array<string, mixed> $policy
 * @param array{workflows: array<string, array<string, mixed>>, jobs: array<string, array<string, array<string, mixed>>>, context_owners: array<string, list<string>>} $index
 * @param array<string, mixed> $bindings
 * @param list<array<string, string>> $findings
 */
function crc_check_artifacts(array $policy, array $index, array $bindings, array &$findings): void
{
    foreach ($policy['policy']['artifact_contracts'] ?? [] as $contract) {
        $id = $contract['id'] ?? '?';
        $locator = sprintf('artifact_contracts[%s]', $id);
        $expansion = $contract['bounded_expansion'] ?? [];
        $pattern = $contract['producer_pattern'] ?? '';

        // CRC024 first: the pattern is policy-internal and needs no binding.
        foreach ($expansion as $name) {
            if (!crc_glob_matches((string) $pattern, (string) $name)) {
                crc_add($findings, 'CRC024', CRC_ERROR, $locator, '(policy-internal)', sprintf('pattern %s does not match %s', (string) $pattern, (string) $name), sprintf('a producer_pattern matching every bounded expansion name, including %s', (string) $name));
            }
        }

        $producerBinding = $bindings['producers'][$contract['producer_id'] ?? ''] ?? null;
        $producerJob = is_array($producerBinding) ? crc_job($index, $producerBinding) : null;
        if ($producerJob !== null) {
            $uploaded = [];
            $match = false;
            foreach ($producerJob['artifacts']['produces'] ?? [] as $artifact) {
                $uploaded[] = $artifact['name'];
                if (($artifact['expansion'] ?? null) === $expansion) {
                    $match = true;
                }
            }
            if (!$match) {
                crc_add($findings, 'CRC022', CRC_ERROR, $locator, crc_locator($producerBinding), sprintf('job uploads [%s]', implode(', ', $uploaded)), sprintf('an upload whose bounded expansion is %s', crc_json($expansion)));
            }
        }

        $consumerBinding = $bindings['producers'][$contract['consumer_id'] ?? ''] ?? null;
        $consumerJob = is_array($consumerBinding) ? crc_job($index, $consumerBinding) : null;
        if ($consumerJob === null || $producerJob === null) {
            continue;
        }
        $consumed = [];
        foreach ($consumerJob['artifacts']['consumes'] ?? [] as $artifact) {
            $consumed[] = $artifact['pattern'] ?? $artifact['name'];
        }
        if (!in_array($pattern, $consumed, true)) {
            crc_add($findings, 'CRC023', CRC_ERROR, $locator, crc_locator($consumerBinding), sprintf('job consumes [%s]', implode(', ', array_map('strval', $consumed))), sprintf('a download selecting %s', (string) $pattern));
            continue;
        }
        $workflow = $index['workflows'][$consumerBinding['workflow']] ?? [];
        $matched = [];
        foreach ($workflow['artifact_flows'] ?? [] as $flow) {
            if ($flow['id'] !== $pattern) {
                continue;
            }
            foreach ($flow['consumers'] as $consumer) {
                if ($consumer['job'] === $consumerBinding['job']) {
                    $matched = $consumer['matched_producers'];
                }
            }
        }
        if (!in_array($producerBinding['job'], $matched, true)) {
            crc_add($findings, 'CRC023', CRC_ERROR, $locator, crc_locator($consumerBinding), sprintf('inventory matched_producers are [%s]', implode(', ', $matched)), sprintf('matched_producers to contain %s, the bound producer job', $producerBinding['job']));
        }
    }
}

/**
 * CRC025: every declared cadence is producible by the triggers of the
 * workflow that serves it. A producer with a `recovery_producer` splits its
 * cadences: the recovery cadence is served by the recovery workflow, the rest
 * by the primary workflow. The split is reported explicitly as a notice so it
 * is never invisible.
 *
 * @param array<string, mixed> $policy
 * @param array{workflows: array<string, array<string, mixed>>, jobs: array<string, array<string, array<string, mixed>>>, context_owners: array<string, list<string>>} $index
 * @param array<string, mixed> $bindings
 * @param list<array<string, string>> $findings
 */
function crc_check_cadences(array $policy, array $index, array $bindings, array &$findings): void
{
    foreach ($policy['policy']['producers'] ?? [] as $producer) {
        $id = $producer['id'] ?? '?';
        $locator = sprintf('producers[%s].cadence', $id);
        $binding = $bindings['producers'][$id] ?? null;
        if (!is_array($binding) || !isset($index['workflows'][$binding['workflow']])) {
            continue;
        }

        $assignments = [];
        foreach ($producer['cadence'] ?? [] as $cadence) {
            $assignments[$cadence] = $binding['workflow'];
        }
        $recovery = $producer['recovery_producer'] ?? null;
        if (is_array($recovery) && isset($index['workflows'][$recovery['workflow']])) {
            $recoveryCadence = $recovery['cadence'] ?? null;
            if (is_string($recoveryCadence) && array_key_exists($recoveryCadence, $assignments)) {
                $assignments[$recoveryCadence] = $recovery['workflow'];
                crc_add($findings, 'CRC025', CRC_NOTICE, $locator, crc_locator($recovery), sprintf('cadence %s is served by the recovery producer in %s, not by the primary %s', $recoveryCadence, $recovery['workflow'], $binding['workflow']), 'the recorded cadence split; the primary workflow serves the remaining cadences');
            }
        }

        foreach ($assignments as $cadence => $file) {
            $workflow = $index['workflows'][$file];
            if ($cadence === 'event-driven') {
                crc_check_event_driven_cadence($producer, $workflow, $file, $locator, $findings);
                continue;
            }
            $rule = CRC_CADENCE_TRIGGERS[$cadence] ?? null;
            if ($rule === null) {
                continue;
            }
            $selector = crc_trigger_selector($workflow, $rule['event']);
            if ($selector === null) {
                crc_add($findings, 'CRC025', CRC_ERROR, $locator, $file, sprintf('%s triggers are [%s]', $file, implode(', ', $workflow['triggers']['events'] ?? [])), sprintf('a %s trigger, which cadence %s requires', $rule['event'], $cadence));
                continue;
            }
            if (!isset($rule['selector'])) {
                continue;
            }
            $values = $selector[$rule['selector']] ?? [];
            if (isset($rule['value']) && !in_array($rule['value'], $values, true)) {
                crc_add($findings, 'CRC025', CRC_ERROR, $locator, $file, sprintf('%s trigger %s is %s', $rule['event'], $rule['selector'], crc_json($values)), sprintf('%s containing %s, which cadence %s requires', $rule['selector'], (string) $rule['value'], $cadence));
            }
            if (!isset($rule['value']) && $values === []) {
                crc_add($findings, 'CRC025', CRC_ERROR, $locator, $file, sprintf('%s trigger declares no %s filter', $rule['event'], $rule['selector']), sprintf('a non-empty %s filter, which cadence %s requires', $rule['selector'], $cadence));
            }
        }
    }
}

/**
 * An `event-driven` cadence is served by whichever event the producer's own
 * selectors name, so there is no single trigger to look for.
 *
 * @param array<string, mixed> $producer
 * @param array<string, mixed> $workflow
 * @param list<array<string, string>> $findings
 */
function crc_check_event_driven_cadence(array $producer, array $workflow, string $file, string $locator, array &$findings): void
{
    $named = [];
    foreach (crc_selector_leaves($producer['selection'] ?? []) as $leaf) {
        $event = $leaf['event'] ?? null;
        if (is_string($event)) {
            $named[] = crc_event_name($event);
        }
    }
    $named = array_values(array_unique(array_diff($named, ['workflow_dispatch'])));
    if ($named === []) {
        crc_add($findings, 'CRC025', CRC_ERROR, $locator, $file, 'no selector names a repository event', 'an event selector, which cadence event-driven requires');

        return;
    }
    foreach ($named as $event) {
        if (!in_array($event, $workflow['triggers']['events'] ?? [], true)) {
            crc_add($findings, 'CRC025', CRC_ERROR, $locator, $file, sprintf('%s triggers are [%s]', $file, implode(', ', $workflow['triggers']['events'] ?? [])), sprintf('a %s trigger, which the event-driven selector names', $event));
        }
    }
}

/**
 * CRC026: the live ruleset is not offline evidence. With a frozen `--ruleset`
 * snapshot each required context's binding is compared against it; without
 * one, a single explicit not-verified-offline line is emitted so its absence
 * is never mistaken for a pass.
 *
 * Expected snapshot schema:
 *
 *   {
 *     "id": <int>,
 *     "strict": <bool>,
 *     "contexts": [{"context": <string>, "integration_id": <int|null>}]
 *   }
 *
 * Task 6 produces it from `GET /repos/{owner}/{repo}/rulesets/{id}`, taking
 * the `required_status_checks` rule's `context` and `integration_id` per
 * entry. A null `integration_id` is a name-only binding and is compared as
 * null, not skipped.
 *
 * @param array<string, mixed> $policy
 * @param array<string, mixed>|null $ruleset
 * @param list<array<string, string>> $findings
 */
function crc_check_integration_bindings(array $policy, ?array $ruleset, array &$findings): void
{
    $projection = $policy['policy']['required_projection'] ?? [];
    $contexts = $projection['contexts'] ?? [];
    if ($ruleset === null) {
        crc_add($findings, 'CRC026', CRC_UNVERIFIED, 'required_projection.contexts', '(live ruleset)', sprintf('%d context bindings were not compared with any ruleset', count($contexts)), 'a frozen snapshot via --ruleset=<json>, or the live audit in task 6');

        return;
    }

    if (($ruleset['id'] ?? null) !== ($projection['source_ruleset_id'] ?? null)) {
        crc_add($findings, 'CRC026', CRC_ERROR, 'required_projection.source_ruleset_id', '(ruleset snapshot)', sprintf('snapshot id is %s', crc_json($ruleset['id'] ?? null)), sprintf('ruleset %s', crc_json($projection['source_ruleset_id'] ?? null)));
    }
    if (($ruleset['strict'] ?? null) !== ($projection['strict'] ?? null)) {
        crc_add($findings, 'CRC026', CRC_ERROR, 'required_projection.strict', '(ruleset snapshot)', sprintf('snapshot strict is %s', crc_json($ruleset['strict'] ?? null)), sprintf('strict %s', crc_json($projection['strict'] ?? null)));
    }
    $snapshot = [];
    foreach ($ruleset['contexts'] ?? [] as $item) {
        $snapshot[$item['context'] ?? '?'] = $item;
    }
    if (count($snapshot) !== ($projection['required_context_count'] ?? null)) {
        crc_add($findings, 'CRC026', CRC_ERROR, 'required_projection.required_context_count', '(ruleset snapshot)', sprintf('snapshot carries %d contexts', count($snapshot)), sprintf('%s contexts', crc_json($projection['required_context_count'] ?? null)));
    }
    foreach ($contexts as $item) {
        $name = $item['context'] ?? '?';
        $locator = sprintf('required_projection.contexts[%s].binding', $name);
        $live = $snapshot[$name] ?? null;
        if ($live === null) {
            crc_add($findings, 'CRC026', CRC_ERROR, $locator, '(ruleset snapshot)', 'the snapshot does not require this context', 'a required context in the live ruleset');
            continue;
        }
        $expected = $item['binding'] ?? [];
        if (($live['integration_id'] ?? null) !== ($expected['integration_id'] ?? null)) {
            crc_add($findings, 'CRC026', CRC_ERROR, $locator, '(ruleset snapshot)', sprintf('snapshot integration_id is %s', crc_json($live['integration_id'] ?? null)), sprintf('integration_id %s', crc_json($expected['integration_id'] ?? null)));
        }
    }
}

/**
 * CRC027-CRC029: coverage notices. The policy is a deliberate subset of the
 * workflow graph, so these never fail the run; they say what the policy does
 * not yet model.
 *
 * @param array<string, mixed> $policy
 * @param array{workflows: array<string, array<string, mixed>>, jobs: array<string, array<string, array<string, mixed>>>, context_owners: array<string, list<string>>} $index
 * @param array<string, mixed> $bindings
 * @param list<array<string, string>> $findings
 */
function crc_check_coverage(array $policy, array $index, array $bindings, array &$findings): void
{
    $required = array_column($policy['policy']['required_projection']['contexts'] ?? [], 'context');
    $producerJobs = [];
    foreach ($bindings['producers'] ?? [] as $binding) {
        if (is_array($binding)) {
            $producerJobs[] = crc_locator($binding);
        }
    }

    // CRC027 - gate-shaped contexts the policy does not model at all.
    foreach ($index['jobs'] as $file => $jobs) {
        foreach ($jobs as $key => $job) {
            $locator = $file . '#' . $key;
            if (in_array($locator, $producerJobs, true)) {
                continue;
            }
            foreach (crc_context_names($job) as $name) {
                if (str_starts_with($name, 'ci/') && !in_array($name, $required, true)) {
                    crc_add($findings, 'CRC027', CRC_NOTICE, '(unmodelled)', $locator, sprintf('%s looks like a gate but is in neither the required projection nor a producer binding', $name), 'a required projection entry, a producer, or a name that does not read as a gate');
                }
            }
        }
    }

    // CRC028 - invariants no producer owns.
    $owned = array_column($policy['policy']['producers'] ?? [], 'invariant');
    foreach ($policy['policy']['invariants'] ?? [] as $invariant) {
        if (!in_array($invariant['id'] ?? null, $owned, true)) {
            crc_add($findings, 'CRC028', CRC_NOTICE, sprintf('invariants[%s]', (string) ($invariant['id'] ?? '?')), '(no producer)', 'no producer declares this invariant', 'a producer owning the invariant, or a projection-only invariant by design');
        }
    }

    // CRC029 - required contexts whose bound job has no recognised local command.
    foreach ($policy['policy']['required_projection']['contexts'] ?? [] as $item) {
        $name = $item['context'] ?? '?';
        $binding = $bindings['required_contexts'][$name] ?? null;
        $job = is_array($binding) ? crc_job($index, $binding) : null;
        if ($job !== null && ($job['local_equivalent']['status'] ?? null) === 'no-recognised-command') {
            crc_add($findings, 'CRC029', CRC_NOTICE, sprintf('required_projection.contexts[%s]', $name), crc_locator($binding), 'the inventory recognised no repository-local command and no hosted signal', 'a recognised local entry point, or an accepted hosted-only gate');
        }
    }
}

// ---------------------------------------------------------------------------
// Inventory and selector helpers
// ---------------------------------------------------------------------------

/**
 * The inventory job a binding names, or null.
 *
 * @param array{workflows: array<string, array<string, mixed>>, jobs: array<string, array<string, array<string, mixed>>>, context_owners: array<string, list<string>>} $index
 * @return array<string, mixed>|null
 */
function crc_job(array $index, mixed $binding): ?array
{
    if (!is_array($binding)) {
        return null;
    }
    $file = $binding['workflow'] ?? null;
    $key = $binding['job'] ?? null;

    return is_string($file) && is_string($key) ? ($index['jobs'][$file][$key] ?? null) : null;
}

function crc_locator(mixed $binding): string
{
    if (!is_array($binding)) {
        return '(malformed binding)';
    }

    return sprintf('%s#%s', (string) ($binding['workflow'] ?? '?'), (string) ($binding['job'] ?? '?'));
}

function crc_file(string $locator): string
{
    return explode('#', $locator, 2)[0];
}

/**
 * @param array<string, mixed> $policy
 * @return array<string, mixed>
 */
function crc_producer(array $policy, string $id): array
{
    foreach ($policy['policy']['producers'] ?? [] as $producer) {
        if (($producer['id'] ?? null) === $id) {
            return $producer;
        }
    }

    return [];
}

/**
 * @param array<string, mixed> $job
 * @return list<string>
 */
function crc_context_names(array $job): array
{
    $names = [];
    foreach ($job['contexts'] ?? [] as $context) {
        if (($context['context'] ?? null) !== null) {
            $names[] = $context['context'];
        }
    }

    return $names;
}

/**
 * Flattens a composed selection into its leaf predicates. Composition is not
 * evaluated: every leaf must have inventory evidence somewhere, which is the
 * strongest claim an offline reader can make about an `any_of`.
 *
 * @param array<string, mixed> $selection
 * @return list<array<string, mixed>>
 */
function crc_selector_leaves(array $selection): array
{
    if (isset($selection['type'])) {
        return [$selection];
    }
    $leaves = [];
    foreach ($selection['selectors'] ?? [] as $child) {
        if (is_array($child)) {
            $leaves = array_merge($leaves, crc_selector_leaves($child));
        }
    }

    return $leaves;
}

/**
 * The GitHub event name inside a policy selector value, which may carry a
 * qualifier (`pull_request:labeled`, `push:tags`).
 */
function crc_event_name(string $selectorEvent): string
{
    return explode(':', $selectorEvent, 2)[0];
}

/**
 * Cadences imply trigger events too, so CRC014 does not report a trigger the
 * cadence table already models.
 *
 * @param array<string, mixed> $producer
 * @return list<string>
 */
function crc_cadence_events(array $producer): array
{
    $events = [];
    foreach ($producer['cadence'] ?? [] as $cadence) {
        $rule = CRC_CADENCE_TRIGGERS[$cadence] ?? null;
        if ($rule !== null) {
            $events[] = $rule['event'];
        }
    }

    return $events;
}

/**
 * Does the inventory evidence one selector leaf? Trigger selectors and the
 * job's `if` CLASSIFICATION are the only evidence; no expression is evaluated.
 *
 * @param array<string, mixed> $leaf
 * @param array<string, mixed> $job
 * @param array<string, mixed> $workflow
 */
function crc_selector_is_evidenced(array $leaf, array $job, array $workflow): bool
{
    $classification = $job['if']['classification'] ?? [];
    $literals = $job['if']['literals'] ?? [];

    return match ($leaf['type'] ?? '?') {
        'event' => crc_event_is_evidenced((string) ($leaf['event'] ?? ''), $workflow),
        'label' => in_array('label', $classification, true) && in_array($leaf['label'] ?? null, $literals, true),
        'actor' => in_array('actor', $classification, true),
        'path' => crc_trigger_has_filter($workflow, 'paths') || crc_trigger_has_filter($workflow, 'paths-ignore'),
        default => false,
    };
}

/**
 * `<event>` needs that trigger. `<event>:<qualifier>` additionally needs the
 * qualifier: `pull_request:labeled` a `types` list containing `labeled`,
 * `push:tags` a non-empty `tags` filter.
 *
 * The trigger filter is the ONLY evidence for a qualifier, deliberately. A
 * job-level `if` naming `labeled` proves nothing when the workflow does not
 * subscribe to that activity type — GitHub's default `pull_request` types are
 * opened, synchronize and reopened, so the job would simply never run on a
 * label. Consulting the `if` classification here would fail open, so this
 * helper does not look at it.
 *
 * @param array<string, mixed> $workflow
 */
function crc_event_is_evidenced(string $selectorEvent, array $workflow): bool
{
    [$event, $qualifier] = array_pad(explode(':', $selectorEvent, 2), 2, null);
    $selector = crc_trigger_selector($workflow, $event);
    if ($selector === null) {
        return false;
    }
    if ($qualifier === null) {
        return true;
    }
    if ($event === 'push' && $qualifier === 'tags') {
        return ($selector['tags'] ?? []) !== [];
    }

    return in_array($qualifier, $selector['types'] ?? [], true);
}

/**
 * A workflow's trigger selector record for one event, or null.
 *
 * @param array<string, mixed> $workflow
 * @return array<string, mixed>|null
 */
function crc_trigger_selector(array $workflow, string $event): ?array
{
    foreach ($workflow['triggers']['selectors'] ?? [] as $selector) {
        if (($selector['event'] ?? null) === $event) {
            return $selector;
        }
    }

    return null;
}

/** @param array<string, mixed> $workflow */
function crc_trigger_has_filter(array $workflow, string $key): bool
{
    foreach ($workflow['triggers']['selectors'] ?? [] as $selector) {
        if (($selector[$key] ?? []) !== []) {
            return true;
        }
    }

    return false;
}

/**
 * Glob match with `*` only, exactly as `actions/download-artifact` patterns
 * behave and as the inventory generator matches them.
 */
function crc_glob_matches(string $pattern, string $name): bool
{
    if (!str_contains($pattern, '*')) {
        return $pattern === $name;
    }

    return preg_match('/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/', $name) === 1;
}

function crc_json(mixed $value): string
{
    return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
}

/**
 * A policy selector leaf, rendered for a finding line.
 *
 * @param array<string, mixed> $leaf
 */
function crc_selector_label(array $leaf): string
{
    $type = (string) ($leaf['type'] ?? '?');
    $value = $leaf['event'] ?? $leaf['label'] ?? $leaf['actor'] ?? $leaf['policy'] ?? null;

    return is_string($value) ? sprintf('%s:%s', $type, $value) : $type;
}

// ---------------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------------

/**
 * Findings per severity.
 *
 * @param list<array<string, string>> $findings
 * @return array{error: int, notice: int, 'not-verified-offline': int}
 */
function crc_counts(array $findings): array
{
    $counts = [CRC_ERROR => 0, CRC_NOTICE => 0, CRC_UNVERIFIED => 0];
    foreach ($findings as $finding) {
        $counts[$finding['severity']]++;
    }

    return $counts;
}

/**
 * One line per finding, in the repository's failure format: severity, rule id,
 * policy locator, inventory locator, current value, expected value.
 *
 * @param list<array<string, string>> $findings
 */
function crc_render_lines(array $findings): string
{
    $lines = [];
    foreach ($findings as $finding) {
        $lines[] = sprintf(
            '%s [%s] %s | %s | current: %s | expected: %s',
            strtoupper($finding['severity']),
            $finding['rule'],
            $finding['policy'],
            $finding['inventory'],
            $finding['current'],
            $finding['expected'],
        );
    }

    return $lines === [] ? '' : implode("\n", $lines) . "\n";
}

/**
 * @param list<array<string, string>> $findings
 */
function crc_render_json(array $findings, string $policyPath, string $inventoryPath, bool $rulesetProvided): string
{
    $counts = crc_counts($findings);

    return (string) json_encode([
        'kind' => 'ci-roster-conformance',
        'change_record' => 'FW-CI-CHECK-ROSTER-AUDIT-01',
        'policy' => $policyPath,
        'inventory' => $inventoryPath,
        'ruleset_snapshot' => $rulesetProvided,
        'counts' => [
            'error' => $counts[CRC_ERROR],
            'notice' => $counts[CRC_NOTICE],
            'not_verified_offline' => $counts[CRC_UNVERIFIED],
        ],
        'findings' => $findings,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}
