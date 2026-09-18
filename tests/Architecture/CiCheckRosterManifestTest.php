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

    protected function setUp(): void
    {
        $this->manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/tools/ci-check-roster.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    #[Test]
    public function manifest_defines_a_self_consistent_task_one_policy_contract(): void
    {
        self::assertSame([], $this->validate($this->manifest));
        self::assertSame(3, $this->manifest['schema_version']);
        self::assertSame('deferred', $this->manifest['scope']['generated_workflow_inventory']['status']);
        self::assertSame('deferred', $this->manifest['scope']['offline_workflow_conformance']['status']);

        $projection = $this->manifest['policy']['required_projection'];
        self::assertTrue($projection['strict']);
        self::assertCount(22, $projection['contexts']);
        self::assertCount(21, array_filter(
            $projection['contexts'],
            static fn (array $item): bool => $item['binding']['mode'] === 'github-app'
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
        $producerIndex = fn (string $id): int => $this->producerIndex($mutated, $id);

        match ($case) {
            'unknown-role' => $mutated['policy']['producers'][0]['role'] = 'mystery',
            'duplicate-id' => $mutated['policy']['producers'][1]['id'] = $mutated['policy']['producers'][0]['id'],
            'unstable-id' => $mutated['policy']['producers'][0]['id'] = 'Source Integrity',
            'unknown-invariant' => $mutated['policy']['producers'][0]['invariant'] = 'missing',
            'invalid-selector' => $mutated['policy']['producers'][0]['selection']['composition'] = 'one_of',
            'missing-profile' => $mutated['policy']['producers'][$producerIndex('mutation-pilot')]['subject_profiles'] = [],
            'subject-leakage' => $mutated['policy']['producers'][1]['subject_profiles'][0]['sha_subject'] = 'main-sha',
            'flat-subjects' => $mutated['policy']['producers'][0]['attestation_subjects'] = ['pr-head-sha'],
            'duplicate-profile-cadence' => $mutated['policy']['producers'][1]['subject_profiles'][1]['cadences'] = ['pull-request'],
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
            'cross-workflow-lineage' => $mutated['policy']['producers'][2]['workflow_policy_id'] = 'other-workflow',
            'aggregate-result' => $mutated['policy']['aggregate_lineage_contract']['aggregates'][0]['prerequisites'][0]['required_result'] = 'completed',
            'artifact-pattern' => $mutated['policy']['artifact_contracts'][0]['producer_pattern'] = 'php-test-shard-${{ matrix.shard }}',
            'artifact-subject' => $mutated['policy']['producers'][2]['subject_profiles'][0]['artifact_subject'] = 'pr-head-sha',
            'premature-inventory' => $mutated['scope']['generated_workflow_inventory']['status'] = 'complete',
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
        yield 'subject leakage' => ['subject-leakage', 'ci-environment-setup profile pull-request-head leaks main-sha into pull-request'];
        yield 'flat conjunctive subjects' => ['flat-subjects', 'source-integrity-policy must use profiles instead of flat attestation subjects'];
        yield 'alternative profiles' => ['duplicate-profile-cadence', 'ci-environment-setup subject profiles must cover every active cadence exactly once'];
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
        yield 'task boundary' => ['premature-inventory', 'generated workflow inventory must remain deferred to task 2'];
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
        if (($manifest['scope']['generated_workflow_inventory']['status'] ?? null) !== 'deferred'
            || ($manifest['scope']['generated_workflow_inventory']['task'] ?? null) !== 2) {
            $errors[] = 'generated workflow inventory must remain deferred to task 2';
        }
        if (($manifest['scope']['offline_workflow_conformance']['status'] ?? null) !== 'deferred'
            || ($manifest['scope']['offline_workflow_conformance']['task'] ?? null) !== 3) {
            $errors[] = 'offline workflow conformance must remain deferred to task 3';
        }

        $this->validateSubjectContract($manifest, $errors);
        $invariantIds = $this->validateInvariants($manifest, $errors);
        $this->validateProducers($manifest, $invariantIds, $errors);
        $this->validateCurrentPolicyDetails($manifest, $errors);
        $this->validateAggregateContract($manifest, $errors);
        $this->validateArtifactContract($manifest, $errors);
        $this->validateRequiredProjection($manifest, $invariantIds, $errors);
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
        foreach ($contract['aggregates'] ?? [] as $aggregate) {
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
