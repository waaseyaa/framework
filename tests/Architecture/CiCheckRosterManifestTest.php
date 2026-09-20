<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class CiCheckRosterManifestTest extends TestCase
{
    private const VOCABULARY = [
        'roles' => ['policy', 'setup', 'execution', 'aggregate', 'advisory', 'publication', 'orchestration'],
        'expansions' => ['singleton', 'matrix'],
        'selections' => ['unconditional', 'path', 'actor', 'event', 'label'],
        'selection_compositions' => ['all_of', 'any_of'],
        'dispositions' => ['required', 'diagnostic', 'expected-skip', 'publication-only'],
        'authorities' => ['merge', 'release', 'operational', 'informational'],
        'cadences' => ['pull-request', 'merge-group', 'main', 'scheduled', 'release', 'manual', 'event-driven'],
        'attestation_subjects' => [
            'pr-head-sha',
            'merge-ref-sha',
            'merge-group-combined-sha',
            'main-sha',
            'run-attempt',
            'artifact-source-sha',
        ],
    ];

    private const SUBJECT_FIELDS = [
        'pr-head-sha' => ['pull_request_head_sha', 'git-sha'],
        'merge-ref-sha' => ['merge_ref_sha', 'git-sha'],
        'merge-group-combined-sha' => ['merge_group_combined_sha', 'git-sha'],
        'main-sha' => ['main_sha', 'git-sha'],
        'run-attempt' => ['run_attempt', 'positive-integer'],
        'artifact-source-sha' => ['artifact_source_sha', 'git-sha'],
    ];

    /**
     * The governed producer set, in manifest order. Task 3a replaced the
     * unbindable `ci-environment-setup` with the two plan jobs `ci.yml`
     * actually runs; see the change record's "Task 3a" section.
     */
    private const PRODUCERS = [
        'source-integrity-policy',
        'phpunit-shard-plan',
        'random-order-plan',
        'php-test-shards',
        'php-behavior-aggregate',
        'php-coverage-aggregate',
        'random-order-shards',
        'random-order-aggregate',
        'mutation-pilot',
        'release-publish-evidence',
        'enable-native-auto-merge',
    ];

    private const INVARIANTS = [
        'source-repository-policy',
        'php-behavior-coverage',
        'security-authorization',
        'public-package-contracts',
        'consumer-acceptance',
        'browser-acceptance',
        'platform-runtime-acceptance',
        'release-integrity',
    ];

    private const REQUIRED_PROJECTION = [
        'Frontend build' => 'public-package-contracts',
        'Ingestion defaults' => 'consumer-acceptance',
        'Manifest conformance' => 'source-repository-policy',
        'Release publish shape' => 'release-integrity',
        'Security defaults' => 'security-authorization',
        'check-dead-code' => 'source-repository-policy',
        'ci/core-only-boot' => 'consumer-acceptance',
        'ci/coverage' => 'php-behavior-coverage',
        'ci/lint' => 'source-repository-policy',
        'ci/playwright-smoke' => 'browser-acceptance',
        'ci/random-order' => 'php-behavior-coverage',
        'ci/skeleton-create-project' => 'consumer-acceptance',
        'ci/unit-tests' => 'php-behavior-coverage',
        'ci/verify-gates' => 'source-repository-policy',
        'composer-policy' => 'source-repository-policy',
        'packaged-form' => 'public-package-contracts',
        'ci/package-isolation' => 'public-package-contracts',
        'ci/mutation-pilot' => 'php-behavior-coverage',
        'ci/fresh-install-boot' => 'consumer-acceptance',
        'ci/frankenphp-worker' => 'platform-runtime-acceptance',
        'ci/skeleton-create-project-windows' => 'platform-runtime-acceptance',
        'ci/bimaaji-skill-resources' => 'consumer-acceptance',
    ];

    private const CADENCE_SHA_SUBJECTS = [
        'pull-request' => ['pr-head-sha', 'merge-ref-sha'],
        'merge-group' => ['merge-group-combined-sha'],
        'main' => ['main-sha'],
        'scheduled' => ['main-sha'],
        'release' => ['main-sha'],
        'manual' => ['main-sha', 'pr-head-sha'],
        'event-driven' => ['pr-head-sha'],
    ];

    private const RESIDUAL_TASKS = [
        'Evidence freeze and external benchmark',
        'Governance contract and schema repair',
        'Inventory generator',
        'Offline conformance verifier',
        'Measurement baseline under #2869',
        'Stable aggregate shadowing',
        'Ruleset projection and live audit',
        'Ruleset migration',
        'Cadence optimization',
        'Final reconciliation',
    ];

    /** @var array<string, mixed> */
    private array $manifest;

    /**
     * The generated workflow inventory (Task 2), read so the binding block can
     * be cross-checked offline. The test never reads workflow YAML.
     *
     * @var array<string, mixed>
     */
    private array $inventory;

    protected function setUp(): void
    {
        $this->manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/tools/ci-check-roster.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->inventory = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/tools/ci-workflow-inventory.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    #[Test]
    public function manifest_defines_a_self_consistent_bound_policy_contract(): void
    {
        self::assertSame([], $this->validate($this->manifest));
        self::assertSame(3, $this->manifest['schema_version']);
        self::assertSame(7, $this->manifest['scope']['task']);
        self::assertSame(
            ['status' => 'generated', 'task' => 2, 'path' => 'tools/ci-workflow-inventory.json', 'generator' => 'bin/generate-ci-workflow-inventory'],
            $this->manifest['scope']['generated_workflow_inventory'],
        );
        self::assertSame(
            ['status' => 'implemented', 'task' => 3, 'verifier' => 'bin/check-ci-roster-conformance'],
            $this->manifest['scope']['offline_workflow_conformance'],
        );
        self::assertSame('implemented', $this->manifest['scope']['live_projection_audit']['status']);
        self::assertSame(6, $this->manifest['scope']['live_projection_audit']['task']);
        self::assertSame('ci-roster-live-audit.yml', $this->manifest['scope']['live_projection_audit']['workflow']);
        self::assertSame('bin/audit-ci-roster-live', $this->manifest['scope']['live_projection_audit']['verifier']);
        self::assertFalse($this->manifest['scope']['live_projection_audit']['ordinary_pull_request_dependency']);
        self::assertSame(self::PRODUCERS, array_column($this->manifest['policy']['producers'], 'id'));
        self::assertSame('id', $this->producer($this->manifest, 'php-test-shards')['matrix']['axis']);
        self::assertSame('id', $this->producer($this->manifest, 'random-order-shards')['matrix']['axis']);

        $bindings = $this->manifest['bindings'];
        self::assertSame('tools/ci-workflow-inventory.json', $bindings['source']);
        self::assertSame(['workflow' => 'ci.yml', 'job' => 'verify-gates'], $bindings['producers']['source-integrity-policy']);
        self::assertSame(['workflow' => 'split.yml', 'job' => 'assemble-release-evidence'], $bindings['producers']['release-publish-evidence']);
        self::assertSame('split.yml', $bindings['workflow_policies']['release-publication']);
        self::assertCount(22, $bindings['required_contexts']);

        $projection = $this->manifest['policy']['required_projection'];
        self::assertTrue($projection['strict']);
        self::assertCount(22, $projection['contexts']);
        self::assertCount(21, array_filter(
            $projection['contexts'],
            static fn(array $item): bool => $item['binding']['mode'] === 'github-app'
                && $item['binding']['integration_id'] === 15368,
        ));
        self::assertSame([1, 2], $this->producer($this->manifest, 'random-order-shards')['matrix']['values']);
        self::assertSame(self::INVARIANTS, array_column($this->manifest['policy']['invariants'], 'id'));
    }

    #[Test]
    #[DataProvider('invalidManifestCases')]
    public function validator_rejects_discriminating_contract_violations(string $case, string $expectedError): void
    {
        $mutated = $this->manifest;
        $producerIndex = fn(string $id): int => $this->producerIndex($mutated, $id);

        match ($case) {
            'unknown-role' => $mutated['policy']['producers'][0]['role'] = 'mystery',
            'duplicate-id' => $mutated['policy']['producers'][1]['id'] = $mutated['policy']['producers'][0]['id'],
            'unstable-id' => $mutated['policy']['producers'][0]['id'] = 'Source Integrity',
            'unknown-invariant' => $mutated['policy']['producers'][0]['invariant'] = 'missing',
            'invalid-selector' => $mutated['policy']['producers'][0]['selection']['composition'] = 'one_of',
            'missing-profile' => $mutated['policy']['producers'][$producerIndex('mutation-pilot')]['subject_profiles'] = [],
            'subject-leakage' => $mutated['policy']['producers'][$producerIndex('phpunit-shard-plan')]['subject_profiles'][0]['sha_subject'] = 'main-sha',
            'flat-subjects' => $mutated['policy']['producers'][0]['attestation_subjects'] = ['pr-head-sha'],
            'duplicate-profile-cadence' => $mutated['policy']['producers'][$producerIndex('phpunit-shard-plan')]['subject_profiles'][1]['cadences'] = ['pull-request'],
            'unmapped-context' => $mutated['policy']['required_projection']['contexts'][0]['invariant'] = null,
            'unknown-context-invariant' => $mutated['policy']['required_projection']['contexts'][0]['invariant'] = 'unknown',
            'duplicate-context' => $mutated['policy']['required_projection']['contexts'][1]['context'] = 'Frontend build',
            'wrong-app-binding' => $mutated['policy']['required_projection']['contexts'][0]['binding']['integration_id'] = 999,
            'mutation-app-binding' => $mutated['policy']['required_projection']['contexts'][17]['binding'] = ['mode' => 'github-app', 'integration_id' => 15368],
            'random-width' => $mutated['policy']['producers'][$producerIndex('random-order-shards')]['matrix']['values'] = [1, 2, 3],
            'random-task' => $mutated['policy']['random_order_measurement']['change_allowed_before_task'] = 7,
            'auto-merge-selector' => $mutated['policy']['producers'][$producerIndex('enable-native-auto-merge')]['selection'] = [
                'composition' => 'all_of',
                'selectors' => [['type' => 'actor', 'actor' => 'dependabot']],
            ],
            'auto-merge-disposition' => $mutated['policy']['producers'][$producerIndex('enable-native-auto-merge')]['disposition'] = 'expected-skip',
            'mutation-selection' => $mutated['policy']['producers'][$producerIndex('mutation-pilot')]['selection'] = [
                'composition' => 'all_of',
                'selectors' => [['type' => 'path', 'policy' => 'mutation-scope']],
            ],
            'mutation-cadence' => $mutated['policy']['producers'][$producerIndex('mutation-pilot')]['cadence'] = ['pull-request', 'scheduled'],
            'active-merge-group' => $mutated['policy']['producers'][0]['cadence'][] = 'merge-group',
            'aggregate-cancellation' => $mutated['policy']['aggregate_lineage_contract']['terminal_result_policy']['cancelled'] = 'pass',
            'cross-workflow-lineage' => $mutated['policy']['producers'][$producerIndex('php-test-shards')]['workflow_policy_id'] = 'other-workflow',
            'aggregate-result' => $mutated['policy']['aggregate_lineage_contract']['aggregates'][0]['prerequisites'][0]['required_result'] = 'completed',
            'artifact-pattern' => $mutated['policy']['artifact_contracts'][0]['producer_pattern'] = 'php-test-shard-${{ matrix.shard }}',
            'artifact-subject' => $mutated['policy']['producers'][$producerIndex('php-test-shards')]['subject_profiles'][0]['artifact_subject'] = 'pr-head-sha',
            'unbound-inventory-pointer' => $mutated['scope']['generated_workflow_inventory']['path'] = 'tools/somewhere-else.json',
            'unbound-verifier-pointer' => $mutated['scope']['offline_workflow_conformance']['verifier'] = 'bin/somewhere-else',
            'producer-roster' => $mutated['policy']['producers'][$producerIndex('phpunit-shard-plan')]['id'] = 'ci-environment-setup',
            'matrix-axis' => $mutated['policy']['producers'][$producerIndex('php-test-shards')]['matrix']['axis'] = 'shard',
            'random-order-lineage' => $mutated['policy']['aggregate_lineage_contract']['aggregates'][2]['prerequisites'] = [
                ['producer_id' => 'random-order-shards', 'required_result' => 'success'],
            ],
            'release-selector' => $mutated['policy']['producers'][$producerIndex('release-publish-evidence')]['selection'] = [
                'composition' => 'all_of',
                'selectors' => [['type' => 'event', 'event' => 'release-or-manual-dispatch']],
            ],
            'unbound-producer' => $mutated['bindings']['producers'] = array_diff_key(
                $mutated['bindings']['producers'],
                ['mutation-pilot' => null],
            ),
            'binding-missing-job' => $mutated['bindings']['producers']['php-test-shards']['job'] = 'renamed-shards',
            'binding-missing-context-job' => $mutated['bindings']['required_contexts']['ci/lint']['job'] = 'renamed-lint',
            'binding-wrong-context-job' => $mutated['bindings']['required_contexts']['ci/lint']['job'] = 'frontend-build',
            'binding-wrong-workflow-real-job' => $mutated['bindings']['required_contexts']['ci/lint'] = ['workflow' => 'split.yml', 'job' => 'split'],
            'binding-expansion-mismatch' => $mutated['bindings']['producers']['php-test-shards']['job'] = 'ci-lint',
            'binding-workflow-drift' => $mutated['bindings']['producers']['php-test-shards'] = ['workflow' => 'split.yml', 'job' => 'split'],
            'binding-missing-workflow' => $mutated['bindings']['workflow_policies']['primary-ci'] = 'not-a-workflow.yml',
            'residual-status' => $mutated['residual_tasks'][2]['status'] = 'pending',
            default => self::fail(sprintf('Unknown fixture %s.', $case)),
        };

        self::assertContains($expectedError, $this->validate($mutated));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidManifestCases(): iterable
    {
        yield 'enum role' => ['unknown-role', 'source-integrity-policy has unknown role mystery'];
        yield 'stable IDs' => ['duplicate-id', 'producer IDs must be unique'];
        yield 'stable ID shape' => ['unstable-id', 'producer IDs and workflow policy IDs must be stable kebab-case identifiers'];
        yield 'producer invariant reference' => ['unknown-invariant', 'source-integrity-policy references unknown invariant missing'];
        yield 'selector composition' => ['invalid-selector', 'source-integrity-policy has invalid selector composition one_of'];
        yield 'missing subject profile' => ['missing-profile', 'mutation-pilot subject profiles must cover every active cadence exactly once'];
        yield 'subject leakage' => ['subject-leakage', 'phpunit-shard-plan profile pull-request-head-artifact leaks main-sha into pull-request'];
        yield 'flat conjunctive subjects' => ['flat-subjects', 'source-integrity-policy must use profiles instead of flat attestation subjects'];
        yield 'alternative profiles' => ['duplicate-profile-cadence', 'phpunit-shard-plan subject profiles must cover every active cadence exactly once'];
        yield 'unmapped required context' => ['unmapped-context', 'Frontend build must reference an owned invariant decision'];
        yield 'unknown required context invariant' => ['unknown-context-invariant', 'Frontend build must reference an owned invariant decision'];
        yield 'projection uniqueness' => ['duplicate-context', 'required projection contexts must be unique'];
        yield 'app binding' => ['wrong-app-binding', 'Frontend build must bind to GitHub Actions app 15368'];
        yield 'name-only exception' => ['mutation-app-binding', 'ci/mutation-pilot must remain intentionally name-only'];
        yield 'random-order width' => ['random-width', 'random-order active shard width must remain [1, 2]'];
        yield 'random-order task gate' => ['random-task', 'random-order width and cadence changes belong to task 8'];
        yield 'auto-merge selector' => ['auto-merge-selector', 'native auto-merge selection must be workflow_dispatch OR the exact labeled-PR predicate'];
        yield 'auto-merge execution' => ['auto-merge-disposition', 'native auto-merge must permit successful operational execution'];
        yield 'mutation selection' => ['mutation-selection', 'mutation-pilot must remain an unconditional required pull-request policy'];
        yield 'mutation cadence' => ['mutation-cadence', 'mutation-pilot must remain an unconditional required pull-request policy'];
        yield 'no active merge group' => ['active-merge-group', 'current producers must not claim merge-group execution'];
        yield 'cancellation policy' => ['aggregate-cancellation', 'aggregate cancellation must fail'];
        yield 'workflow-local lineage' => ['cross-workflow-lineage', 'php-behavior-aggregate prerequisite php-test-shards must be workflow-local'];
        yield 'explicit prerequisite result' => ['aggregate-result', 'php-behavior-aggregate prerequisite php-test-shards must explicitly require success'];
        yield 'bounded artifacts' => ['artifact-pattern', 'php shard coverage must use the bounded php-test-shard-* contract'];
        yield 'artifact subject matching' => ['artifact-subject', 'php shard coverage producer and consumer profiles must bind artifact-source-sha'];
        yield 'inventory pointer' => ['unbound-inventory-pointer', 'generated workflow inventory pointer must name the tracked inventory path'];
        yield 'verifier pointer' => ['unbound-verifier-pointer', 'offline workflow conformance pointer must name the task 3 verifier'];
        yield 'producer roster' => ['producer-roster', 'policy must define the governed producer roster in order'];
        yield 'matrix axis' => ['matrix-axis', 'producer php-test-shards declares matrix axis shard with values [1,2,3,4], which ci.yml#ci-test-shards does not declare'];
        yield 'random-order lineage' => ['random-order-lineage', 'random-order-aggregate lineage prerequisites must be exactly [random-order-shards, random-order-plan]'];
        yield 'release selector' => ['release-selector', 'release publication selection must be the tag-push or manual-dispatch predicate'];
        yield 'unbound producer' => ['unbound-producer', 'producer mutation-pilot has no inventory binding'];
        yield 'renamed producer job' => ['binding-missing-job', 'producer php-test-shards binds ci.yml#renamed-shards, which the inventory does not contain'];
        yield 'renamed context job' => ['binding-missing-context-job', 'required context ci/lint binds ci.yml#renamed-lint, which the inventory does not contain'];
        yield 'context bound to the wrong job' => ['binding-wrong-context-job', 'required context ci/lint binds ci.yml#frontend-build, but the inventory shows that context on [ci.yml#ci-lint]'];
        yield 'context bound across workflows' => ['binding-wrong-workflow-real-job', 'required context ci/lint binds split.yml#split, but the inventory shows that context on [ci.yml#ci-lint]'];
        yield 'producer expansion mismatch' => ['binding-expansion-mismatch', 'producer php-test-shards declares expansion matrix but ci.yml#ci-lint is singleton'];
        yield 'binding workflow drift' => ['binding-workflow-drift', 'producer php-test-shards binds split.yml but its workflow policy primary-ci binds ci.yml'];
        yield 'missing bound workflow' => ['binding-missing-workflow', 'workflow policy primary-ci binds not-a-workflow.yml, which the inventory does not contain'];
        yield 'residual status' => ['residual-status', 'residual tasks must record 0-6 complete and 7 current'];
    }

    /** @param array<string, mixed> $manifest
     *  @return list<string>
     */
    private function validate(array $manifest): array
    {
        $errors = [];
        if (($manifest['schema_version'] ?? null) !== 3) {
            $errors[] = 'schema version must be 3';
        }
        foreach (self::VOCABULARY as $field => $values) {
            if (($manifest['vocabulary'][$field] ?? null) !== $values) {
                $errors[] = sprintf('%s vocabulary is invalid', $field);
            }
        }
        $inventoryPointer = $manifest['scope']['generated_workflow_inventory'] ?? [];
        if (($inventoryPointer['status'] ?? null) !== 'generated'
            || ($inventoryPointer['task'] ?? null) !== 2
            || ($inventoryPointer['path'] ?? null) !== 'tools/ci-workflow-inventory.json'
            || ($inventoryPointer['generator'] ?? null) !== 'bin/generate-ci-workflow-inventory') {
            $errors[] = 'generated workflow inventory pointer must name the tracked inventory path';
        }
        $conformance = $manifest['scope']['offline_workflow_conformance'] ?? [];
        if (($conformance['status'] ?? null) !== 'implemented'
            || ($conformance['task'] ?? null) !== 3
            || ($conformance['verifier'] ?? null) !== 'bin/check-ci-roster-conformance') {
            $errors[] = 'offline workflow conformance pointer must name the task 3 verifier';
        }
        if (($manifest['scope']['task'] ?? null) !== 7) {
            $errors[] = 'manifest scope must record task 7';
        }
        $liveAudit = $manifest['scope']['live_projection_audit'] ?? [];
        if (($liveAudit['status'] ?? null) !== 'implemented'
            || ($liveAudit['task'] ?? null) !== 6
            || ($liveAudit['workflow'] ?? null) !== 'ci-roster-live-audit.yml'
            || ($liveAudit['verifier'] ?? null) !== 'bin/audit-ci-roster-live'
            || ($liveAudit['events'] ?? null) !== ['schedule', 'workflow_dispatch']
            || ($liveAudit['ordinary_pull_request_dependency'] ?? null) !== false) {
            $errors[] = 'live projection audit pointer must name the scheduled/manual task 6 verifier';
        }

        $this->validateSubjectContract($manifest, $errors);
        $invariantIds = $this->validateInvariants($manifest, $errors);
        $this->validateProducers($manifest, $invariantIds, $errors);
        $this->validateCurrentPolicyDetails($manifest, $errors);
        $this->validateAggregateContract($manifest, $errors);
        $this->validateArtifactContract($manifest, $errors);
        $this->validateRequiredProjection($manifest, $invariantIds, $errors);
        $this->validateBindings($manifest, $errors);
        $this->validateResidualTasks($manifest, $errors);

        return array_values(array_unique($errors));
    }

    /** @param array<string, mixed> $manifest
     *  @param list<string> $errors
     */
    private function validateSubjectContract(array $manifest, array &$errors): void
    {
        $contract = $manifest['attestation_subject_contract'] ?? [];
        if (($contract['substitution_allowed'] ?? null) !== false) {
            $errors[] = 'attestation subject substitution must be forbidden';
        }
        if (($contract['cadence_sha_subjects'] ?? null) !== self::CADENCE_SHA_SUBJECTS) {
            $errors[] = 'cadence-specific SHA subject rules are invalid';
        }
        $subjects = $contract['subjects'] ?? [];
        if (array_column($subjects, 'id') !== self::VOCABULARY['attestation_subjects']) {
            $errors[] = 'attestation subjects do not match the governed vocabulary';
        }
        foreach ($subjects as $subject) {
            $expected = self::SUBJECT_FIELDS[$subject['id'] ?? ''] ?? null;
            if ($expected === null || [$subject['field'] ?? null, $subject['value_type'] ?? null] !== $expected) {
                $errors[] = sprintf('attestation subject %s has invalid identity semantics', $subject['id'] ?? '?');
            }
        }
    }

    /** @param array<string, mixed> $manifest
     *  @param list<string> $errors
     *  @return list<string>
     */
    private function validateInvariants(array $manifest, array &$errors): array
    {
        $invariants = $manifest['policy']['invariants'] ?? [];
        if (array_column($manifest['policy']['producers'] ?? [], 'id') !== self::PRODUCERS) {
            $errors[] = 'policy must define the governed producer roster in order';
        }
        $ids = array_column($invariants, 'id');
        if ($ids !== self::INVARIANTS) {
            $errors[] = 'policy must define the eight governed invariant decisions';
        }
        foreach ($invariants as $invariant) {
            if (!is_string($invariant['owner'] ?? null) || $invariant['owner'] === '') {
                $errors[] = sprintf('invariant %s must name an owner', $invariant['id'] ?? '?');
            }
        }

        return $ids;
    }

    /** @param array<string, mixed> $manifest
     *  @param list<string> $invariantIds
     *  @param list<string> $errors
     */
    private function validateProducers(array $manifest, array $invariantIds, array &$errors): void
    {
        $producers = $manifest['policy']['producers'] ?? [];
        $ids = array_column($producers, 'id');
        if (count($ids) !== count(array_unique($ids))) {
            $errors[] = 'producer IDs must be unique';
        }
        foreach ($producers as $producer) {
            $id = $producer['id'] ?? '?';
            if (!is_string($id) || preg_match('/^[a-z][a-z0-9-]*$/', $id) !== 1
                || !is_string($producer['workflow_policy_id'] ?? null)
                || preg_match('/^[a-z][a-z0-9-]*$/', $producer['workflow_policy_id']) !== 1) {
                $errors[] = 'producer IDs and workflow policy IDs must be stable kebab-case identifiers';
            }
            foreach (['roles' => 'role', 'expansions' => 'expansion', 'dispositions' => 'disposition', 'authorities' => 'authority'] as $vocabulary => $field) {
                if (!in_array($producer[$field] ?? null, self::VOCABULARY[$vocabulary], true)) {
                    $errors[] = sprintf('%s has unknown %s %s', $id, $field, $producer[$field] ?? '?');
                }
            }
            if (!in_array($producer['invariant'] ?? null, $invariantIds, true)) {
                $errors[] = sprintf('%s references unknown invariant %s', $id, $producer['invariant'] ?? '?');
            }
            if (array_key_exists('attestation_subjects', $producer)) {
                $errors[] = sprintf('%s must use profiles instead of flat attestation subjects', $id);
            }
            $this->validateSelector($producer['selection'] ?? [], $id, $errors);
            $this->validateSubjectProfiles($producer, $errors);
            if (($producer['expansion'] ?? null) === 'matrix' && !$this->validMatrix($producer['matrix'] ?? [])) {
                $errors[] = sprintf('%s must define a bounded matrix expansion', $id);
            }
            if (($producer['expansion'] ?? null) === 'singleton' && isset($producer['matrix'])) {
                $errors[] = sprintf('%s singleton must not define a matrix', $id);
            }
            if (in_array('merge-group', $producer['cadence'] ?? [], true)) {
                $errors[] = 'current producers must not claim merge-group execution';
            }
        }
    }

    /** @param array<string, mixed> $selector
     *  @param list<string> $errors
     */
    private function validateSelector(array $selector, string $producerId, array &$errors): void
    {
        if (isset($selector['type'])) {
            if (!in_array($selector['type'], self::VOCABULARY['selections'], true)) {
                $errors[] = sprintf('%s has unknown selector %s', $producerId, $selector['type']);
            }

            return;
        }
        $composition = $selector['composition'] ?? '?';
        if (!in_array($composition, self::VOCABULARY['selection_compositions'], true)) {
            $errors[] = sprintf('%s has invalid selector composition %s', $producerId, $composition);

            return;
        }
        if (!is_array($selector['selectors'] ?? null) || $selector['selectors'] === []) {
            $errors[] = sprintf('%s selector composition must not be empty', $producerId);

            return;
        }
        foreach ($selector['selectors'] as $child) {
            if (!is_array($child)) {
                $errors[] = sprintf('%s selector nodes must be objects', $producerId);
                continue;
            }
            $this->validateSelector($child, $producerId, $errors);
        }
    }

    /** @param array<string, mixed> $producer
     *  @param list<string> $errors
     */
    private function validateSubjectProfiles(array $producer, array &$errors): void
    {
        $id = $producer['id'] ?? '?';
        $cadences = $producer['cadence'] ?? [];
        $profiles = $producer['subject_profiles'] ?? [];
        $covered = [];
        foreach ($profiles as $profile) {
            $profileId = $profile['id'] ?? '?';
            $profileCadences = $profile['cadences'] ?? [];
            foreach ($profileCadences as $cadence) {
                $covered[] = $cadence;
                $allowed = self::CADENCE_SHA_SUBJECTS[$cadence] ?? [];
                if (!in_array($profile['sha_subject'] ?? null, $allowed, true)) {
                    $errors[] = sprintf('%s profile %s leaks %s into %s', $id, $profileId, $profile['sha_subject'] ?? '?', $cadence);
                }
            }
            if (($profile['run_attempt_subject'] ?? null) !== 'run-attempt') {
                $errors[] = sprintf('%s profile %s must bind run-attempt', $id, $profileId);
            }
            if (isset($profile['artifact_subject']) && $profile['artifact_subject'] !== 'artifact-source-sha') {
                $errors[] = sprintf('%s profile %s has invalid artifact subject', $id, $profileId);
            }
        }
        sort($cadences);
        sort($covered);
        if ($cadences === [] || $cadences !== $covered || count($covered) !== count(array_unique($covered))) {
            $errors[] = sprintf('%s subject profiles must cover every active cadence exactly once', $id);
        }
    }

    /** @param array<string, mixed> $matrix */
    private function validMatrix(array $matrix): bool
    {
        $values = $matrix['values'] ?? null;

        return is_string($matrix['axis'] ?? null)
            && preg_match('/^[a-z][a-z0-9_]*$/', $matrix['axis']) === 1
            && is_array($values)
            && $values !== []
            && count($values) === count(array_unique($values, SORT_REGULAR));
    }

    /** @param array<string, mixed> $manifest
     *  @param list<string> $errors
     */
    private function validateCurrentPolicyDetails(array $manifest, array &$errors): void
    {
        $random = $this->producer($manifest, 'random-order-shards');
        $measurement = $manifest['policy']['random_order_measurement'] ?? [];
        if (($random['matrix']['values'] ?? null) !== [1, 2] || ($measurement['active_shards'] ?? null) !== [1, 2]) {
            $errors[] = 'random-order active shard width must remain [1, 2]';
        }
        if (($measurement['cadence'] ?? null) !== 'every-pull-request' || ($measurement['change_allowed_before_task'] ?? null) !== 8) {
            $errors[] = 'random-order width and cadence changes belong to task 8';
        }

        $mutation = $this->producer($manifest, 'mutation-pilot');
        $unconditional = ['composition' => 'all_of', 'selectors' => [['type' => 'unconditional']]];
        if (($mutation['selection'] ?? null) !== $unconditional
            || ($mutation['disposition'] ?? null) !== 'required'
            || ($mutation['authority'] ?? null) !== 'merge'
            || ($mutation['cadence'] ?? null) !== ['pull-request']) {
            $errors[] = 'mutation-pilot must remain an unconditional required pull-request policy';
        }

        $autoMerge = $this->producer($manifest, 'enable-native-auto-merge');
        $expectedSelection = [
            'composition' => 'any_of',
            'selectors' => [
                ['type' => 'event', 'event' => 'workflow_dispatch'],
                [
                    'composition' => 'all_of',
                    'selectors' => [
                        ['type' => 'event', 'event' => 'pull_request:labeled'],
                        ['type' => 'label', 'label' => 'auto-merge-when-green'],
                    ],
                ],
            ],
        ];
        if (($autoMerge['selection'] ?? null) !== $expectedSelection) {
            $errors[] = 'native auto-merge selection must be workflow_dispatch OR the exact labeled-PR predicate';
        }
        if (($autoMerge['role'] ?? null) !== 'orchestration'
            || ($autoMerge['authority'] ?? null) !== 'operational'
            || ($autoMerge['disposition'] ?? null) === 'expected-skip') {
            $errors[] = 'native auto-merge must permit successful operational execution';
        }

        // Task 3a: both shard matrices carry the axis key the bound job
        // declares, which validateProducerExpansionBinding() checks against the
        // inventory rather than against a literal in this file.

        // Task 3a: `release` cadence is a v* tag push (split.yml); the manual
        // cadence is served by the recorded recovery producer.
        $release = $this->producer($manifest, 'release-publish-evidence');
        $expectedRelease = [
            'composition' => 'any_of',
            'selectors' => [
                ['type' => 'event', 'event' => 'push:tags'],
                ['type' => 'event', 'event' => 'workflow_dispatch'],
            ],
        ];
        if (($release['selection'] ?? null) !== $expectedRelease) {
            $errors[] = 'release publication selection must be the tag-push or manual-dispatch predicate';
        }
        if (($release['recovery_producer']['workflow'] ?? null) !== 'github-release.yml'
            || ($release['recovery_producer']['job'] ?? null) !== 'release'
            || ($release['recovery_producer']['cadence'] ?? null) !== 'manual') {
            $errors[] = 'release publication must name github-release.yml#release as its manual recovery producer';
        }
    }

    /** @param array<string, mixed> $manifest
     *  @param list<string> $errors
     */
    private function validateAggregateContract(array $manifest, array &$errors): void
    {
        $contract = $manifest['policy']['aggregate_lineage_contract'] ?? [];
        if (($contract['implementation_status'] ?? null) !== 'contract-only') {
            $errors[] = 'aggregate implementation must remain outside task 1';
        }
        if (($contract['ownership_scope'] ?? null) !== 'workflow-local') {
            $errors[] = 'aggregate ownership must be workflow-local';
        }
        if (($contract['condition_semantics'] ?? null) !== 'always()') {
            $errors[] = 'aggregate condition must use always() semantics';
        }
        $terminal = $contract['terminal_result_policy'] ?? [];
        foreach (['failure' => 'fail', 'cancelled' => 'fail', 'skipped' => 'fail-unless-sha-bound-not-applicable', 'missing' => 'fail'] as $state => $outcome) {
            if (($terminal[$state] ?? null) !== $outcome) {
                $errors[] = $state === 'cancelled' ? 'aggregate cancellation must fail' : sprintf('aggregate %s policy is invalid', $state);
            }
        }
        $notApplicable = $contract['not_applicable_decision'] ?? [];
        if (($notApplicable['required_fields'] ?? null) !== ['subject_type', 'subject_sha', 'run_attempt', 'producer_id', 'reason']
            || ($notApplicable['reusable_across_subjects'] ?? null) !== false) {
            $errors[] = 'not-applicable decisions must be SHA and run-attempt bound';
        }
        // Task 3a: ci.yml#ci-random-order result-checks both ci-random-order-shard
        // and prepare-random-order-plan, so the policy lineage carries both.
        $expectedPrerequisites = [
            'php-behavior-aggregate' => ['php-test-shards'],
            'php-coverage-aggregate' => ['php-test-shards'],
            'random-order-aggregate' => ['random-order-shards', 'random-order-plan'],
        ];
        foreach ($contract['aggregates'] ?? [] as $aggregate) {
            $ownerId = $aggregate['producer_id'] ?? '?';
            $expected = $expectedPrerequisites[$ownerId] ?? null;
            if ($expected !== null && array_column($aggregate['prerequisites'] ?? [], 'producer_id') !== $expected) {
                $errors[] = sprintf('%s lineage prerequisites must be exactly [%s]', $ownerId, implode(', ', $expected));
            }
            $owner = $this->producer($manifest, $aggregate['producer_id'] ?? '');
            foreach ($aggregate['prerequisites'] ?? [] as $prerequisitePolicy) {
                $prerequisiteId = $prerequisitePolicy['producer_id'] ?? '?';
                $prerequisite = $this->producer($manifest, $prerequisiteId);
                if (($prerequisitePolicy['required_result'] ?? null) !== 'success') {
                    $errors[] = sprintf('%s prerequisite %s must explicitly require success', $aggregate['producer_id'], $prerequisiteId);
                }
                if (($owner['workflow_policy_id'] ?? null) !== ($prerequisite['workflow_policy_id'] ?? null)) {
                    $errors[] = sprintf('%s prerequisite %s must be workflow-local', $aggregate['producer_id'], $prerequisiteId);
                }
            }
        }
    }

    /** @param array<string, mixed> $manifest
     *  @param list<string> $errors
     */
    private function validateArtifactContract(array $manifest, array &$errors): void
    {
        $artifact = $manifest['policy']['artifact_contracts'][0] ?? [];
        if (($artifact['producer_pattern'] ?? null) !== 'php-test-shard-*'
            || ($artifact['bounded_expansion'] ?? null) !== ['php-test-shard-1', 'php-test-shard-2', 'php-test-shard-3', 'php-test-shard-4']
            || ($artifact['subject'] ?? null) !== 'artifact-source-sha'
            || ($artifact['require_exact_subject_match'] ?? null) !== true) {
            $errors[] = 'php shard coverage must use the bounded php-test-shard-* contract';
        }
        foreach ([$artifact['producer_id'] ?? '', $artifact['consumer_id'] ?? ''] as $producerId) {
            foreach ($this->producer($manifest, $producerId)['subject_profiles'] ?? [] as $profile) {
                if (($profile['artifact_subject'] ?? null) !== 'artifact-source-sha') {
                    $errors[] = 'php shard coverage producer and consumer profiles must bind artifact-source-sha';
                }
            }
        }
    }

    /** @param array<string, mixed> $manifest
     *  @param list<string> $invariantIds
     *  @param list<string> $errors
     */
    private function validateRequiredProjection(array $manifest, array $invariantIds, array &$errors): void
    {
        $projection = $manifest['policy']['required_projection'] ?? [];
        $contexts = $projection['contexts'] ?? [];
        $names = array_column($contexts, 'context');
        if (($projection['source_ruleset_id'] ?? null) !== 15181711
            || ($projection['strict'] ?? null) !== true
            || ($projection['required_context_count'] ?? null) !== 22
            || count($contexts) !== 22) {
            $errors[] = 'required projection must preserve the live 22-context strict ruleset';
        }
        if (count($names) !== count(array_unique($names))) {
            $errors[] = 'required projection contexts must be unique';
        }
        $actualProjection = [];
        foreach ($contexts as $item) {
            $name = $item['context'] ?? '?';
            $invariant = $item['invariant'] ?? null;
            if (!in_array($invariant, $invariantIds, true)) {
                $errors[] = sprintf('%s must reference an owned invariant decision', $name);
            }
            $actualProjection[$name] = $invariant;
            $binding = $item['binding'] ?? [];
            if ($name === 'ci/mutation-pilot') {
                if (($binding['mode'] ?? null) !== 'name-only'
                    || (array_key_exists('integration_id', $binding) && $binding['integration_id'] !== null)) {
                    $errors[] = 'ci/mutation-pilot must remain intentionally name-only';
                }
            } elseif (($binding['mode'] ?? null) !== 'github-app' || ($binding['integration_id'] ?? null) !== 15368) {
                $errors[] = sprintf('%s must bind to GitHub Actions app 15368', $name);
            }
        }
        if ($actualProjection !== self::REQUIRED_PROJECTION) {
            $errors[] = 'required projection names and invariant decisions must match frozen policy';
        }
        $used = array_values(array_unique(array_values($actualProjection)));
        sort($used);
        $expected = self::INVARIANTS;
        sort($expected);
        if ($used !== $expected) {
            $errors[] = 'all eight invariant decisions must own at least one required context';
        }

        $migration = $manifest['policy']['ruleset_migration'] ?? [];
        if (($migration['status'] ?? null) !== 'union-required'
            || ($migration['ruleset_id'] ?? null) !== 15181711
            || ($migration['active_live_projection'] ?? null) !== 'union'
            || ($migration['baseline'] ?? null) !== 'tools/ci-ruleset-main-protection-baseline.json'
            || ($migration['projector'] ?? null) !== 'bin/project-ci-ruleset'
            || ($migration['default_mode'] ?? null) !== 'dry-run'
            || ($migration['allowed_live_projections_during_migration'] ?? null) !== ['legacy', 'union', 'final']) {
            $errors[] = 'Task 7 ruleset migration must remain an explicit dry-run-first three-projection contract';
        }
        $unionEvidence = $migration['union_evidence'] ?? [];
        if (($unionEvidence['applied_from_main_sha'] ?? null) !== '840033e3b35a81aa3beb81d4391772a5ee810e24'
            || ($unionEvidence['before_hash'] ?? null) !== '671d65c3259e35c583de6ce71799bf091ffd08b1fd783d7323c4c8d12c388748'
            || ($unionEvidence['after_hash'] ?? null) !== '5a8a3e17013dc138a73d32739fb5139def5d675301a4a3761c28e70f0f97ca27'
            || ($unionEvidence['verified_check_count'] ?? null) !== 31
            || ($unionEvidence['post_write_live_audit_run'] ?? null) !== 35495615254
            || ($unionEvidence['rollback_dry_run_verified'] ?? null) !== true) {
            $errors[] = 'Task 7 union projection must retain its exact write, audit, and rollback evidence';
        }
        $phaseCounts = array_map(
            static fn(array $phase): mixed => $phase['required_context_count'] ?? null,
            $migration['projections'] ?? [],
        );
        if ($phaseCounts !== ['legacy' => 22, 'union' => 31, 'final' => 9]) {
            $errors[] = 'Task 7 ruleset migration projection counts must remain 22, 31, and 9';
        }
        if (($migration['rollback_transitions'] ?? null) !== ['union-to-legacy', 'final-to-legacy']
            || count($migration['apply_guards'] ?? []) !== 9) {
            $errors[] = 'Task 7 ruleset migration must retain both rollback paths and all nine apply guards';
        }
    }

    /**
     * Task 3a: the policy is now bound to the generated workflow inventory.
     * This is the cheap offline cross-check, and it goes past mere existence:
     * a required context must be owned by exactly the job it binds, and a
     * producer's declared expansion (and, for a matrix, its axis and values)
     * must be what the bound job declares. It reads the inventory, never
     * workflow YAML.
     *
     * @param array<string, mixed> $manifest
     * @param list<string> $errors
     */
    private function validateBindings(array $manifest, array &$errors): void
    {
        $bindings = $manifest['bindings'] ?? [];
        if (($bindings['source'] ?? null) !== 'tools/ci-workflow-inventory.json') {
            $errors[] = 'bindings must name the generated workflow inventory as their source';
        }

        /** @var array<string, array<string, array<string, mixed>>> $inventoryJobs */
        $inventoryJobs = [];
        /** @var array<string, list<string>> $contextOwners */
        $contextOwners = [];
        foreach ($this->inventory['workflows'] ?? [] as $workflow) {
            foreach ($workflow['jobs'] as $job) {
                $inventoryJobs[$workflow['file']][$job['key']] = $job;
                foreach ($job['contexts'] ?? [] as $context) {
                    if (($context['context'] ?? null) !== null) {
                        $contextOwners[$context['context']][] = $workflow['file'] . '#' . $job['key'];
                    }
                }
            }
        }
        foreach ($contextOwners as $name => $owners) {
            $contextOwners[$name] = array_values(array_unique($owners));
        }

        $policies = $bindings['workflow_policies'] ?? [];
        foreach ($policies as $policyId => $file) {
            if (!array_key_exists($file, $inventoryJobs)) {
                $errors[] = sprintf('workflow policy %s binds %s, which the inventory does not contain', $policyId, $file);
            }
        }

        /**
         * Resolves a binding to its inventory job record, recording an error
         * and returning null when the binding does not name an existing job.
         *
         * @return array<string, mixed>|null
         */
        $checkJob = function (string $kind, string $key, mixed $binding) use ($inventoryJobs, &$errors): ?array {
            $file = is_array($binding) ? ($binding['workflow'] ?? null) : null;
            $job = is_array($binding) ? ($binding['job'] ?? null) : null;
            if (!is_string($file) || !is_string($job)) {
                $errors[] = sprintf('%s %s binding must name a workflow and a job', $kind, $key);

                return null;
            }
            if (!isset($inventoryJobs[$file][$job])) {
                $errors[] = sprintf('%s %s binds %s#%s, which the inventory does not contain', $kind, $key, $file, $job);

                return null;
            }

            return $inventoryJobs[$file][$job];
        };

        $producerBindings = $bindings['producers'] ?? [];
        foreach ($manifest['policy']['producers'] ?? [] as $producer) {
            $id = $producer['id'] ?? '?';
            if (!array_key_exists($id, $producerBindings)) {
                $errors[] = sprintf('producer %s has no inventory binding', $id);
                continue;
            }
            $boundJob = $checkJob('producer', $id, $producerBindings[$id]);
            $policyFile = $policies[$producer['workflow_policy_id'] ?? ''] ?? null;
            $boundFile = $producerBindings[$id]['workflow'] ?? null;
            if (is_string($policyFile) && $boundFile !== $policyFile) {
                $errors[] = sprintf(
                    'producer %s binds %s but its workflow policy %s binds %s',
                    $id,
                    is_string($boundFile) ? $boundFile : '?',
                    $producer['workflow_policy_id'],
                    $policyFile,
                );
            }
            if ($boundJob !== null) {
                $this->validateProducerExpansionBinding($producer, $boundJob, (string) $boundFile, $errors);
            }
        }
        foreach (array_keys($producerBindings) as $boundId) {
            if (!in_array($boundId, array_column($manifest['policy']['producers'] ?? [], 'id'), true)) {
                $errors[] = sprintf('binding %s does not name a policy producer', $boundId);
            }
        }

        // The release publication producer names a second job — the manual
        // recovery path — so that reference is inventory-checked too.
        $recovery = $this->producer($manifest, 'release-publish-evidence')['recovery_producer'] ?? null;
        if (is_array($recovery)) {
            $checkJob('recovery producer', 'release-publish-evidence', $recovery);
        }

        $contextBindings = $bindings['required_contexts'] ?? [];
        foreach ($manifest['policy']['required_projection']['contexts'] ?? [] as $item) {
            $name = $item['context'] ?? '?';
            if (!array_key_exists($name, $contextBindings)) {
                $errors[] = sprintf('required context %s has no inventory binding', $name);
                continue;
            }
            if ($checkJob('required context', $name, $contextBindings[$name]) === null) {
                continue;
            }
            $bound = $contextBindings[$name]['workflow'] . '#' . $contextBindings[$name]['job'];
            $owners = $contextOwners[$name] ?? [];
            if ($owners !== [$bound]) {
                $errors[] = sprintf(
                    'required context %s binds %s, but the inventory shows that context on [%s]',
                    $name,
                    $bound,
                    implode(', ', $owners),
                );
            }
        }
        if (count($contextBindings) !== 22) {
            $errors[] = 'every required projection context must carry exactly one binding';
        }
    }

    /**
     * A producer's declared expansion must be the bound job's expansion, and a
     * matrix producer's declared axis and values must be an axis the bound job
     * literally declares. This is what makes the axis assertion inventory-
     * derived rather than a hardcoded literal.
     *
     * @param array<string, mixed> $producer
     * @param array<string, mixed> $boundJob
     * @param list<string> $errors
     */
    private function validateProducerExpansionBinding(array $producer, array $boundJob, string $boundFile, array &$errors): void
    {
        $id = $producer['id'] ?? '?';
        $declared = $producer['expansion'] ?? null;
        $actual = $boundJob['expansion'] ?? null;
        if ($declared !== $actual) {
            $errors[] = sprintf(
                'producer %s declares expansion %s but %s#%s is %s',
                $id,
                is_string($declared) ? $declared : '?',
                $boundFile,
                $boundJob['key'],
                is_string($actual) ? $actual : '?',
            );

            return;
        }
        if ($declared !== 'matrix') {
            return;
        }
        $axis = $producer['matrix']['axis'] ?? null;
        $values = $producer['matrix']['values'] ?? null;
        $axes = $boundJob['matrix']['axes'] ?? null;
        if (!is_array($axes) || !is_string($axis) || ($axes[$axis] ?? null) !== $values) {
            $errors[] = sprintf(
                'producer %s declares matrix axis %s with values %s, which %s#%s does not declare',
                $id,
                is_string($axis) ? $axis : '?',
                json_encode($values),
                $boundFile,
                $boundJob['key'],
            );
        }
    }

    /** @param array<string, mixed> $manifest
     *  @param list<string> $errors
     */
    private function validateResidualTasks(array $manifest, array &$errors): void
    {
        $tasks = $manifest['residual_tasks'] ?? [];
        if (array_column($tasks, 'order') !== range(0, 9) || array_column($tasks, 'name') !== self::RESIDUAL_TASKS) {
            $errors[] = 'residual tasks must preserve the governed order';
        }
        $expected = ['complete', 'complete', 'complete', 'complete', 'complete', 'complete', 'complete', 'current'];
        if (array_slice(array_column($tasks, 'status'), 0, 8) !== $expected) {
            $errors[] = 'residual tasks must record 0-6 complete and 7 current';
        }
    }

    /** @param array<string, mixed> $manifest
     *  @return array<string, mixed>
     */
    private function producer(array $manifest, string $id): array
    {
        foreach ($manifest['policy']['producers'] ?? [] as $producer) {
            if (($producer['id'] ?? null) === $id) {
                return $producer;
            }
        }

        return [];
    }

    /** @param array<string, mixed> $manifest */
    private function producerIndex(array $manifest, string $id): int
    {
        foreach ($manifest['policy']['producers'] ?? [] as $index => $producer) {
            if (($producer['id'] ?? null) === $id) {
                return $index;
            }
        }

        self::fail(sprintf('Missing producer %s.', $id));
    }
}
