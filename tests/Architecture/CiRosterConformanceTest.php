<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Focused proof for the offline conformance verifier
 * (FW-CI-CHECK-ROSTER-AUDIT-01, Task 3; GitHub mirror #3087).
 *
 * Two kinds of case. A compact fixture policy-and-inventory pair conforms with
 * zero errors, and one discriminating mutation per rule proves each rule fires
 * with its own id and locator. Then the repository case: the verifier run
 * against the tracked `tools/ci-check-roster.json` and
 * `tools/ci-workflow-inventory.json` must exit 0 with zero errors. That
 * repository case is the drift gate — an edit to either document that breaks
 * the binding fails here.
 *
 * The verifier reads no workflow YAML, and neither does this test.
 */
#[CoversNothing]
final class CiRosterConformanceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/bin/lib/ci-roster-conformance.php';
    }

    #[Test]
    public function the_tracked_policy_conforms_to_the_generated_inventory(): void
    {
        $result = self::runCli(['--json']);

        self::assertSame(0, $result['exit'], 'Verifier stderr: ' . $result['error'] . "\n" . $result['output']);

        $report = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $report['counts']['error'], 'Conformance errors: ' . $result['output']);
        self::assertSame('ci-roster-conformance', $report['kind']);
        self::assertFalse($report['ruleset_snapshot']);
        self::assertGreaterThan(
            0,
            $report['counts']['not_verified_offline'],
            'The live ruleset is not offline evidence and must be reported as such, never silently.',
        );
        foreach ($report['findings'] as $finding) {
            self::assertContains($finding['severity'], ['notice', 'not-verified-offline']);
            self::assertMatchesRegularExpression('/^CRC0\d\d$/', $finding['rule']);
        }
    }

    #[Test]
    public function the_human_run_reports_its_counts_and_exits_zero(): void
    {
        $result = self::runCli([]);

        self::assertSame(0, $result['exit'], $result['error']);
        self::assertStringContainsString('0 error(s)', $result['output']);
        self::assertStringContainsString('not-verified-offline', $result['output']);
        self::assertStringContainsString('Live ruleset conformance is task 6.', $result['output']);
    }

    #[Test]
    public function a_conforming_fixture_pair_reports_no_errors(): void
    {
        $findings = \crc_verify(self::fixturePolicy(), self::fixtureInventory());

        self::assertSame([], self::errors($findings));
        self::assertSame(1, \crc_counts($findings)['not-verified-offline']);
    }

    #[Test]
    #[DataProvider('driftCases')]
    public function each_rule_fires_on_its_own_drift(string $case, string $rule, string $policyLocator): void
    {
        $policy = self::fixturePolicy();
        $inventory = self::fixtureInventory();
        $ruleset = null;
        self::mutate($case, $policy, $inventory, $ruleset);

        $errors = self::errors(\crc_verify($policy, $inventory, $ruleset));
        $matched = array_values(array_filter(
            $errors,
            static fn(array $finding): bool => $finding['rule'] === $rule && $finding['policy'] === $policyLocator,
        ));

        self::assertNotSame([], $matched, sprintf(
            'Expected %s at %s. Reported: %s',
            $rule,
            $policyLocator,
            json_encode(array_map(static fn(array $f): string => $f['rule'] . ' ' . $f['policy'], $errors)),
        ));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function driftCases(): iterable
    {
        yield 'workflow policy bound to a missing workflow'
            => ['missing-workflow', 'CRC001', 'bindings.workflow_policies[main-ci]'];
        yield 'job rename'
            => ['job-rename', 'CRC002', 'bindings.producers[shard-producer]'];
        yield 'required context bound to a missing job'
            => ['context-job-rename', 'CRC003', 'required_contexts[ci/gate]'];
        yield 'context drift'
            => ['context-drift', 'CRC004', 'required_projection.contexts[ci/gate]'];
        yield 'context produced by two jobs'
            => ['context-two-owners', 'CRC005', 'required_projection.contexts[ci/gate]'];
        yield 'trigger drift'
            => ['trigger-drift', 'CRC006', 'required_projection.contexts[ci/gate]'];
        yield 'duplicate visible context'
            => ['duplicate-context', 'CRC007', 'required_projection.contexts[ci/gate]'];
        yield 'expansion drift'
            => ['expansion-drift', 'CRC008', 'producers[shard-producer]'];
        yield 'matrix drift'
            => ['matrix-drift', 'CRC009', 'producers[shard-producer]'];
        yield 'unresolved matrix'
            => ['unresolved-matrix', 'CRC009', 'producers[shard-producer]'];
        yield 'matrix axis drift'
            => ['axis-drift', 'CRC010', 'producers[shard-producer]'];
        yield 'random-order width drift'
            => ['random-width', 'CRC011', 'random_order_measurement.active_shards'];
        yield 'unconditional producer with a condition'
            => ['conditional-unconditional', 'CRC012', 'producers[plan-producer].selection'];
        yield 'unconditional producer gated on the actor'
            => ['unconditional-actor-if', 'CRC012', 'producers[plan-producer].selection'];
        yield 'unconditional leaf carrying an extra key'
            => ['unconditional-extra-key', 'CRC012', 'producers[plan-producer].selection'];
        yield 'two unconditional leaves'
            => ['unconditional-two-leaves', 'CRC012', 'producers[plan-producer].selection'];
        yield 'condition the inventory cannot classify'
            => ['unconditional-unclassified-if', 'CRC012', 'producers[plan-producer].selection'];
        yield 'selector with no evidence'
            => ['selector-drift', 'CRC013', 'producers[publish-producer].selection'];
        yield 'expected-skip on an unconditional job'
            => ['expected-skip-drift', 'CRC015', 'producers[plan-producer]'];
        yield 'required producer on a conditional job'
            => ['required-conditional', 'CRC016', 'producers[gate-aggregate]'];
        yield 'aggregate that is not an aggregate'
            => ['not-an-aggregate', 'CRC017', 'aggregate_lineage_contract.aggregates[gate-aggregate]'];
        yield 'cancellation propagation'
            => ['gate-drift', 'CRC018', 'aggregate_lineage_contract.aggregates[gate-aggregate]'];
        yield 'missing dependency'
            => ['missing-need', 'CRC019', 'aggregate_lineage_contract.aggregates[gate-aggregate]'];
        yield 'prerequisite result not checked'
            => ['unchecked-result', 'CRC019', 'aggregate_lineage_contract.aggregates[gate-aggregate]'];
        yield 'cross-workflow lineage'
            => ['cross-workflow', 'CRC020', 'aggregate_lineage_contract.aggregates[gate-aggregate]'];
        yield 'missing artifact upload'
            => ['artifact-upload-drift', 'CRC022', 'artifact_contracts[pack]'];
        yield 'missing artifact'
            => ['artifact-consumer-drift', 'CRC023', 'artifact_contracts[pack]'];
        yield 'artifact producer unmatched'
            => ['artifact-match-drift', 'CRC023', 'artifact_contracts[pack]'];
        yield 'unbounded artifact pattern'
            => ['artifact-pattern-drift', 'CRC024', 'artifact_contracts[pack]'];
        yield 'cadence drift'
            => ['cadence-drift', 'CRC025', 'producers[publish-producer].cadence'];
        yield 'integration binding drift'
            => ['integration-drift', 'CRC026', 'required_projection.contexts[ci/gate].binding'];
        yield 'aggregate with no prerequisites'
            => ['empty-prerequisites', 'CRC030', 'aggregate_lineage_contract.aggregates[gate-aggregate].prerequisites'];
        yield 'measurement naming no producer'
            => ['unbound-measurement', 'CRC030', 'random_order_measurement.producer_id'];
        yield 'empty required projection'
            => ['empty-projection', 'CRC030', 'required_projection.contexts'];
    }

    #[Test]
    public function the_integration_binding_rule_is_not_verified_without_a_snapshot(): void
    {
        $findings = \crc_verify(self::fixturePolicy(), self::fixtureInventory());
        $unverified = array_values(array_filter(
            $findings,
            static fn(array $finding): bool => $finding['severity'] === 'not-verified-offline',
        ));

        self::assertCount(1, $unverified);
        self::assertSame('CRC026', $unverified[0]['rule']);
        self::assertStringContainsString('not compared with any ruleset', $unverified[0]['current']);
    }

    #[Test]
    public function a_matching_ruleset_snapshot_verifies_the_integration_bindings(): void
    {
        $findings = \crc_verify(self::fixturePolicy(), self::fixtureInventory(), self::fixtureRuleset());

        self::assertSame([], self::errors($findings));
        self::assertSame(0, \crc_counts($findings)['not-verified-offline']);
    }

    #[Test]
    public function coverage_notices_never_fail_the_run(): void
    {
        $inventory = self::fixtureInventory();
        $inventory['workflows'][0]['jobs'][] = self::job('stray', 'ci/stray', ['contexts' => [self::context('ci/stray')]]);
        // A trigger no bound producer models (CRC014). The `shards` job already
        // needs the bound `plan` job outside the lineage contract (CRC021).
        $inventory['workflows'][0]['triggers']['events'][] = 'workflow_dispatch';
        $inventory['workflows'][0]['triggers']['selectors'][] = ['event' => 'workflow_dispatch'];
        $policy = self::fixturePolicy();
        $policy['policy']['invariants'][] = ['id' => 'unowned', 'owner' => 'nobody'];

        $findings = \crc_verify($policy, $inventory);
        $rules = array_column($findings, 'rule');

        self::assertSame([], self::errors($findings));
        self::assertContains('CRC014', $rules);
        self::assertContains('CRC021', $rules);
        self::assertContains('CRC027', $rules);
        self::assertContains('CRC028', $rules);
        self::assertContains('CRC029', $rules);
        foreach ($findings as $finding) {
            self::assertContains($finding['severity'], ['notice', 'not-verified-offline']);
        }
    }

    #[Test]
    public function an_unknown_option_is_a_usage_error(): void
    {
        $result = self::runCli(['--nope']);

        self::assertSame(2, $result['exit']);
        self::assertStringContainsString('unknown argument --nope', $result['error']);
    }

    #[Test]
    public function an_unreadable_input_is_a_usage_error(): void
    {
        $missing = sys_get_temp_dir() . '/waaseyaa-crc-missing-' . bin2hex(random_bytes(6)) . '.json';
        $result = self::runCli(['--policy=' . $missing]);

        self::assertSame(2, $result['exit']);
        self::assertStringContainsString('cannot read the policy', $result['error']);
    }

    #[Test]
    public function a_malformed_input_is_a_usage_error(): void
    {
        $broken = sys_get_temp_dir() . '/waaseyaa-crc-broken-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($broken, '{ not json');
        $result = self::runCli(['--policy=' . $broken]);
        unlink($broken);

        self::assertSame(2, $result['exit']);
        self::assertStringContainsString('is not valid JSON', $result['error']);
    }

    #[Test]
    public function a_drifted_policy_exits_one_through_the_cli(): void
    {
        $root = sys_get_temp_dir() . '/waaseyaa-crc-' . bin2hex(random_bytes(6));
        mkdir($root . '/tools', 0o777, true);
        $policy = self::fixturePolicy();
        $inventory = self::fixtureInventory();
        $ruleset = null;
        self::mutate('matrix-drift', $policy, $inventory, $ruleset);
        file_put_contents($root . '/tools/ci-check-roster.json', json_encode($policy, JSON_THROW_ON_ERROR));
        file_put_contents($root . '/tools/ci-workflow-inventory.json', json_encode($inventory, JSON_THROW_ON_ERROR));

        $result = self::runCli(['--root=' . $root]);
        unlink($root . '/tools/ci-check-roster.json');
        unlink($root . '/tools/ci-workflow-inventory.json');
        rmdir($root . '/tools');
        rmdir($root);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('ERROR [CRC009]', $result['output']);
        self::assertStringContainsString('The policy and the generated workflow inventory disagree', $result['output']);
    }

    // -----------------------------------------------------------------------
    // Fixture mutations
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $policy
     * @param array<string, mixed> $inventory
     * @param array<string, mixed>|null $ruleset
     */
    private static function mutate(string $case, array &$policy, array &$inventory, ?array &$ruleset): void
    {
        match ($case) {
            'missing-workflow' => $policy['bindings']['workflow_policies']['main-ci'] = 'gone.yml',
            'job-rename' => $policy['bindings']['producers']['shard-producer']['job'] = 'renamed-shards',
            'context-job-rename' => $policy['bindings']['required_contexts']['ci/gate']['job'] = 'renamed-gate',
            'context-drift' => $inventory['workflows'][0]['jobs'][2]['contexts'] = [self::context('ci/renamed-gate')],
            'context-two-owners' => $inventory['workflows'][0]['jobs'][0]['contexts'][] = self::context('ci/gate'),
            'trigger-drift' => $inventory['workflows'][0]['triggers'] = [
                'events' => ['push'],
                'selectors' => [['event' => 'push', 'branches' => ['main']]],
            ],
            'duplicate-context' => $inventory['workflows'][0]['duplicate_contexts'] = ['ci/gate'],
            'expansion-drift' => $policy['policy']['producers'][1]['expansion'] = 'singleton',
            'matrix-drift' => $policy['policy']['producers'][1]['matrix']['values'] = [1, 2, 3],
            'unresolved-matrix' => $inventory['workflows'][0]['jobs'][1]['matrix'] = [
                'resolution' => 'unresolved-expression',
                'axes' => null,
                'combinations' => null,
            ],
            'axis-drift' => $policy['policy']['producers'][1]['matrix']['axis'] = 'shard',
            'random-width' => $policy['policy']['random_order_measurement']['active_shards'] = [1, 2, 3],
            'conditional-unconditional' => $inventory['workflows'][0]['jobs'][0]['if'] = self::condition(
                "github.event_name == 'push'",
                ['event'],
                ['push'],
            ),
            'unconditional-actor-if' => $inventory['workflows'][0]['jobs'][0]['if'] = self::condition(
                "github.actor != 'dependabot[bot]'",
                ['actor'],
                ['dependabot[bot]'],
            ),
            // An extra key on the leaf must not make the producer stop looking
            // unconditional, which an exact-array identity guard would.
            'unconditional-extra-key' => self::conditionalPlan(
                $policy,
                $inventory,
                ['composition' => 'all_of', 'selectors' => [['type' => 'unconditional', 'note' => 'always runs']]],
            ),
            'unconditional-two-leaves' => self::conditionalPlan(
                $policy,
                $inventory,
                ['composition' => 'all_of', 'selectors' => [['type' => 'unconditional'], ['type' => 'unconditional']]],
            ),
            // The inventory has no classifier for vars.* or env.*, so this
            // classifies as [] while still being able to skip the job. An
            // empty classification must fail, not pass.
            'unconditional-unclassified-if' => $inventory['workflows'][0]['jobs'][0]['if'] = self::condition(
                "vars.RUN_EXTRA == 'yes'",
                [],
                [],
            ),
            'selector-drift' => $policy['policy']['producers'][3]['selection']['selectors'][0]['event'] = 'issue_comment',
            'expected-skip-drift' => $policy['policy']['producers'][0]['disposition'] = 'expected-skip',
            'required-conditional' => $inventory['workflows'][0]['jobs'][2]['expected_skip']['own_condition'] = true,
            'not-an-aggregate' => $inventory['workflows'][0]['jobs'][2]['aggregate'] = null,
            'gate-drift' => $inventory['workflows'][0]['jobs'][2]['aggregate']['gate'] = 'not-cancelled',
            'missing-need' => $inventory['workflows'][0]['jobs'][2]['needs'] = [],
            'unchecked-result' => $inventory['workflows'][0]['jobs'][2]['aggregate']['result_checked_prerequisites'] = [],
            'cross-workflow' => $policy['bindings']['producers']['shard-producer'] = ['workflow' => 'release.yml', 'job' => 'publish'],
            'artifact-upload-drift' => $policy['policy']['artifact_contracts'][0]['bounded_expansion'] = ['pack-1', 'pack-2', 'pack-3'],
            'artifact-consumer-drift' => $inventory['workflows'][0]['jobs'][2]['artifacts']['consumes'] = [],
            'artifact-match-drift' => $inventory['workflows'][0]['artifact_flows'][1]['consumers'][0]['matched_producers'] = [],
            'artifact-pattern-drift' => $policy['policy']['artifact_contracts'][0]['producer_pattern'] = 'other-*',
            'cadence-drift' => $policy['policy']['producers'][3]['cadence'] = ['scheduled'],
            'integration-drift' => $ruleset = self::fixtureRuleset(9999),
            'empty-prerequisites' => $policy['policy']['aggregate_lineage_contract']['aggregates'][0]['prerequisites'] = [],
            'unbound-measurement' => $policy['policy']['random_order_measurement']['producer_id'] = 'no-such-producer',
            'empty-projection' => $policy['policy']['required_projection']['contexts'] = [],
            default => self::fail(sprintf('Unknown fixture %s.', $case)),
        };
    }

    /**
     * Replaces the plan producer's selection and gives its job a condition, so
     * a CRC012 case can vary the SELECTION shape rather than the condition.
     *
     * @param array<string, mixed> $policy
     * @param array<string, mixed> $inventory
     * @param array<string, mixed> $selection
     */
    private static function conditionalPlan(array &$policy, array &$inventory, array $selection): void
    {
        $policy['policy']['producers'][0]['selection'] = $selection;
        $inventory['workflows'][0]['jobs'][0]['if'] = self::condition(
            "github.event_name == 'push'",
            ['event'],
            ['push'],
        );
    }

    // -----------------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function fixturePolicy(): array
    {
        $profiles = [
            ['id' => 'pr', 'cadences' => ['pull-request'], 'sha_subject' => 'pr-head-sha', 'run_attempt_subject' => 'run-attempt'],
            ['id' => 'main', 'cadences' => ['main'], 'sha_subject' => 'main-sha', 'run_attempt_subject' => 'run-attempt'],
        ];
        $unconditional = ['composition' => 'all_of', 'selectors' => [['type' => 'unconditional']]];

        return [
            'policy' => [
                'invariants' => [['id' => 'behavior', 'owner' => 'maintainers']],
                'producers' => [
                    [
                        'id' => 'plan-producer', 'workflow_policy_id' => 'main-ci', 'role' => 'setup', 'expansion' => 'singleton',
                        'selection' => $unconditional, 'disposition' => 'diagnostic', 'authority' => 'informational',
                        'cadence' => ['pull-request', 'main'], 'invariant' => 'behavior', 'subject_profiles' => $profiles,
                    ],
                    [
                        'id' => 'shard-producer', 'workflow_policy_id' => 'main-ci', 'role' => 'execution', 'expansion' => 'matrix',
                        'matrix' => ['axis' => 'id', 'values' => [1, 2]],
                        'selection' => $unconditional, 'disposition' => 'diagnostic', 'authority' => 'informational',
                        'cadence' => ['pull-request', 'main'], 'invariant' => 'behavior', 'subject_profiles' => $profiles,
                    ],
                    [
                        'id' => 'gate-aggregate', 'workflow_policy_id' => 'main-ci', 'role' => 'aggregate', 'expansion' => 'singleton',
                        'selection' => $unconditional, 'disposition' => 'required', 'authority' => 'merge',
                        'cadence' => ['pull-request', 'main'], 'invariant' => 'behavior', 'subject_profiles' => $profiles,
                    ],
                    [
                        'id' => 'publish-producer', 'workflow_policy_id' => 'publication', 'role' => 'publication', 'expansion' => 'singleton',
                        'selection' => ['composition' => 'any_of', 'selectors' => [['type' => 'event', 'event' => 'push:tags']]],
                        'disposition' => 'publication-only', 'authority' => 'release',
                        'cadence' => ['release'], 'invariant' => 'behavior',
                        'subject_profiles' => [['id' => 'release', 'cadences' => ['release'], 'sha_subject' => 'main-sha', 'run_attempt_subject' => 'run-attempt']],
                    ],
                ],
                'aggregate_lineage_contract' => [
                    'ownership_scope' => 'workflow-local',
                    'condition_semantics' => 'always()',
                    'aggregates' => [
                        ['producer_id' => 'gate-aggregate', 'prerequisites' => [['producer_id' => 'shard-producer', 'required_result' => 'success']]],
                    ],
                ],
                'artifact_contracts' => [
                    [
                        'id' => 'pack', 'producer_id' => 'shard-producer', 'consumer_id' => 'gate-aggregate',
                        'producer_pattern' => 'pack-*', 'bounded_expansion' => ['pack-1', 'pack-2'],
                        'subject' => 'artifact-source-sha', 'require_exact_subject_match' => true,
                    ],
                ],
                'random_order_measurement' => ['producer_id' => 'shard-producer', 'active_shards' => [1, 2]],
                'required_projection' => [
                    'source_ruleset_id' => 4242,
                    'strict' => true,
                    'required_context_count' => 1,
                    'contexts' => [
                        ['context' => 'ci/gate', 'invariant' => 'behavior', 'binding' => ['mode' => 'github-app', 'integration_id' => 15368]],
                    ],
                ],
            ],
            'bindings' => [
                'source' => 'tools/ci-workflow-inventory.json',
                'workflow_policies' => ['main-ci' => 'ci.yml', 'publication' => 'release.yml'],
                'producers' => [
                    'plan-producer' => ['workflow' => 'ci.yml', 'job' => 'plan'],
                    'shard-producer' => ['workflow' => 'ci.yml', 'job' => 'shards'],
                    'gate-aggregate' => ['workflow' => 'ci.yml', 'job' => 'gate'],
                    'publish-producer' => ['workflow' => 'release.yml', 'job' => 'publish'],
                ],
                'required_contexts' => ['ci/gate' => ['workflow' => 'ci.yml', 'job' => 'gate']],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function fixtureInventory(): array
    {
        return [
            'workflows' => [
                [
                    'file' => 'ci.yml',
                    'triggers' => [
                        'events' => ['pull_request', 'push'],
                        'selectors' => [['event' => 'pull_request'], ['event' => 'push', 'branches' => ['main']]],
                    ],
                    'duplicate_contexts' => [],
                    'jobs' => [
                        self::job('plan', 'Plan', [
                            'needed_by' => ['shards'],
                            'artifacts' => ['produces' => [self::artifact('plan', ['plan'])], 'consumes' => []],
                        ]),
                        self::job('shards', null, [
                            'contexts' => [self::context('ci/shard-1', ['id' => 1]), self::context('ci/shard-2', ['id' => 2])],
                            'expansion' => 'matrix',
                            'matrix' => ['resolution' => 'literal', 'axes' => ['id' => [1, 2]], 'combinations' => [['id' => 1], ['id' => 2]]],
                            'needs' => ['plan'],
                            'needed_by' => ['gate'],
                            'artifacts' => [
                                'produces' => [self::artifact('pack-${{ matrix.id }}', ['pack-1', 'pack-2'], 'bounded-expansion')],
                                'consumes' => [['step_index' => 1, 'name' => 'plan', 'pattern' => null, 'kind' => 'literal', 'expansion' => ['plan']]],
                            ],
                        ]),
                        self::job('gate', 'ci/gate', [
                            'needs' => ['shards'],
                            'if' => self::condition('always()', ['always'], []),
                            'aggregate' => [
                                'gate' => 'always',
                                'prerequisites' => ['shards'],
                                'result_checked_prerequisites' => ['shards'],
                                'result_unchecked_prerequisites' => [],
                            ],
                            'artifacts' => [
                                'produces' => [],
                                'consumes' => [['step_index' => 1, 'name' => null, 'pattern' => 'pack-*', 'kind' => 'literal', 'expansion' => ['pack-*']]],
                            ],
                            'local_equivalent' => ['status' => 'no-recognised-command', 'commands' => [], 'hosted_signals' => []],
                        ]),
                    ],
                    'artifact_flows' => [
                        [
                            'id' => 'pack-${{ matrix.id }}',
                            'producers' => [['job' => 'shards', 'kind' => 'bounded-expansion', 'expansion' => ['pack-1', 'pack-2'], 'if' => null]],
                            'consumers' => [],
                            'cross_run_consumers' => [],
                            'cross_run_producer_candidates' => [],
                        ],
                        [
                            'id' => 'pack-*',
                            'producers' => [],
                            'consumers' => [['job' => 'gate', 'via' => 'pattern', 'kind' => 'literal', 'matched_producers' => ['shards'], 'if' => null]],
                            'cross_run_consumers' => [],
                            'cross_run_producer_candidates' => [],
                        ],
                    ],
                ],
                [
                    'file' => 'release.yml',
                    'triggers' => ['events' => ['push'], 'selectors' => [['event' => 'push', 'tags' => ['v*']]]],
                    'duplicate_contexts' => [],
                    'jobs' => [self::job('publish', 'Publish')],
                    'artifact_flows' => [],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function fixtureRuleset(int $integrationId = 15368): array
    {
        return [
            'id' => 4242,
            'strict' => true,
            'contexts' => [['context' => 'ci/gate', 'integration_id' => $integrationId]],
        ];
    }

    /**
     * One inventory job record with the fields the verifier reads.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function job(string $key, ?string $context, array $overrides = []): array
    {
        return array_merge([
            'key' => $key,
            'contexts' => $context === null ? [] : [self::context($context)],
            'expansion' => 'singleton',
            'matrix' => null,
            'needs' => [],
            'needed_by' => [],
            'if' => null,
            'expected_skip' => ['own_condition' => false, 'inherited_from' => [], 'propagates_prerequisite_failure' => false],
            'aggregate' => null,
            'artifacts' => ['produces' => [], 'consumes' => []],
            'local_equivalent' => ['status' => 'discoverable', 'commands' => ['bin/example'], 'hosted_signals' => []],
        ], $overrides);
    }

    /**
     * @param array<string, int|string>|null $matrix
     * @return array<string, mixed>
     */
    private static function context(string $name, ?array $matrix = null): array
    {
        return [
            'context' => $name,
            'derivation' => $matrix === null ? 'literal' : 'template-expanded',
            'matrix' => $matrix,
        ];
    }

    /**
     * @param list<string> $expansion
     * @return array<string, mixed>
     */
    private static function artifact(string $name, array $expansion, string $kind = 'literal'): array
    {
        return ['step_index' => 1, 'name' => $name, 'kind' => $kind, 'expansion' => $expansion, 'if' => null];
    }

    /**
     * @param list<string> $classification
     * @param list<string> $literals
     * @return array<string, mixed>
     */
    private static function condition(string $normalized, array $classification, array $literals): array
    {
        return [
            'kind' => 'unresolved-expression',
            'raw' => $normalized,
            'normalized' => $normalized,
            'classification' => $classification,
            'references' => [],
            'literals' => $literals,
        ];
    }

    /**
     * @param list<array<string, string>> $findings
     * @return list<array<string, string>>
     */
    private static function errors(array $findings): array
    {
        return array_values(array_filter(
            $findings,
            static fn(array $finding): bool => $finding['severity'] === 'error',
        ));
    }

    /**
     * @param list<string> $arguments
     * @return array{exit: int, output: string, error: string}
     */
    private static function runCli(array $arguments): array
    {
        $process = new Process(
            array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/bin/check-ci-roster-conformance'], $arguments),
            null,
            null,
            null,
            null,
        );

        return [
            'exit' => $process->run(),
            'output' => $process->getOutput(),
            'error' => $process->getErrorOutput(),
        ];
    }
}
