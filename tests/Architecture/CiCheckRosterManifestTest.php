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
        'selections' => ['unconditional', 'path', 'actor', 'event'],
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

    private const REQUIRED_CONTEXTS = [
        'Frontend build',
        'Ingestion defaults',
        'Manifest conformance',
        'Release publish shape',
        'Security defaults',
        'check-dead-code',
        'ci/core-only-boot',
        'ci/coverage',
        'ci/lint',
        'ci/playwright-smoke',
        'ci/random-order',
        'ci/skeleton-create-project',
        'ci/unit-tests',
        'ci/verify-gates',
        'composer-policy',
        'packaged-form',
        'ci/package-isolation',
        'ci/mutation-pilot',
        'ci/fresh-install-boot',
        'ci/frankenphp-worker',
        'ci/skeleton-create-project-windows',
        'ci/bimaaji-skill-resources',
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
        self::assertSame(2, $this->manifest['schema_version']);
        self::assertSame('governed-ci-policy', $this->manifest['kind']);
        self::assertSame('deferred', $this->manifest['scope']['generated_workflow_inventory']['status']);
        self::assertSame(2, $this->manifest['scope']['generated_workflow_inventory']['task']);
        self::assertSame('deferred', $this->manifest['scope']['offline_workflow_conformance']['status']);
        self::assertSame(3, $this->manifest['scope']['offline_workflow_conformance']['task']);

        $projection = $this->manifest['policy']['required_projection'];
        self::assertTrue($projection['strict']);
        self::assertCount(22, $projection['contexts']);
        self::assertCount(21, array_filter(
            $projection['contexts'],
            static fn (array $item): bool => $item['binding']['mode'] === 'github-app'
                && $item['binding']['integration_id'] === 15368,
        ));

        $mutation = $this->context($this->manifest, 'ci/mutation-pilot');
        self::assertSame('name-only', $mutation['binding']['mode']);
        self::assertNull($mutation['binding']['integration_id']);
    }

    #[Test]
    #[DataProvider('invalidManifestCases')]
    public function validator_rejects_discriminating_contract_violations(string $case, string $expectedError): void
    {
        $mutated = $this->manifest;

        match ($case) {
            'unknown-role' => $mutated['policy']['producers'][0]['role'] = 'mystery',
            'duplicate-id' => $mutated['policy']['producers'][1]['id'] = $mutated['policy']['producers'][0]['id'],
            'unstable-id' => $mutated['policy']['producers'][0]['id'] = 'Source Integrity',
            'unknown-invariant' => $mutated['policy']['producers'][0]['invariant'] = 'missing',
            'unknown-selection' => $mutated['policy']['producers'][0]['selection'][0]['type'] = 'branch',
            'empty-selection' => $mutated['policy']['producers'][0]['selection'] = [],
            'subject-substitution' => $mutated['attestation_subject_contract']['substitution_allowed'] = true,
            'missing-subject' => array_pop($mutated['attestation_subject_contract']['subjects']),
            'duplicate-context' => $mutated['policy']['required_projection']['contexts'][1]['context'] = 'Frontend build',
            'wrong-app-binding' => $mutated['policy']['required_projection']['contexts'][0]['binding']['integration_id'] = 999,
            'mutation-app-binding' => $mutated['policy']['required_projection']['contexts'][17]['binding'] = [
                'mode' => 'github-app',
                'integration_id' => 15368,
            ],
            'aggregate-cancellation' => $mutated['policy']['aggregate_lineage_contract']['terminal_result_policy']['cancelled'] = 'pass',
            'cross-workflow-lineage' => $mutated['policy']['producers'][2]['workflow_policy_id'] = 'other-workflow',
            'aggregate-result' => $mutated['policy']['aggregate_lineage_contract']['aggregates'][0]['prerequisites'][0]['required_result'] = 'completed',
            'artifact-pattern' => $mutated['policy']['artifact_contracts'][0]['producer_pattern'] = 'php-test-shard-${{ matrix.shard }}',
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
        yield 'references' => ['unknown-invariant', 'source-integrity-policy references unknown invariant missing'];
        yield 'selection enum' => ['unknown-selection', 'source-integrity-policy has unknown selection branch'];
        yield 'schema shape' => ['empty-selection', 'source-integrity-policy selection must be a non-empty list'];
        yield 'subject isolation' => ['subject-substitution', 'attestation subject substitution must be forbidden'];
        yield 'subject completeness' => ['missing-subject', 'attestation subjects do not match the governed vocabulary'];
        yield 'projection uniqueness' => ['duplicate-context', 'required projection contexts must be unique'];
        yield 'app binding' => ['wrong-app-binding', 'Frontend build must bind to GitHub Actions app 15368'];
        yield 'name-only exception' => ['mutation-app-binding', 'ci/mutation-pilot must remain intentionally name-only'];
        yield 'cancellation policy' => ['aggregate-cancellation', 'aggregate cancellation must fail'];
        yield 'workflow-local lineage' => ['cross-workflow-lineage', 'php-behavior-aggregate prerequisite php-test-shards must be workflow-local'];
        yield 'explicit prerequisite result' => ['aggregate-result', 'php-behavior-aggregate prerequisite php-test-shards must explicitly require success'];
        yield 'bounded artifacts' => ['artifact-pattern', 'php shard coverage must use the bounded php-test-shard-* contract'];
        yield 'task boundary' => ['premature-inventory', 'generated workflow inventory must remain deferred to task 2'];
    }

    /** @param array<string, mixed> $manifest
     *  @return list<string>
     */
    private function validate(array $manifest): array
    {
        $errors = [];

        if (($manifest['schema_version'] ?? null) !== 2) {
            $errors[] = 'schema version must be 2';
        }
        if (($manifest['change_record'] ?? null) !== 'FW-CI-CHECK-ROSTER-AUDIT-01') {
            $errors[] = 'change record ID is invalid';
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

        $subjectContract = $manifest['attestation_subject_contract'] ?? [];
        if (($subjectContract['substitution_allowed'] ?? null) !== false) {
            $errors[] = 'attestation subject substitution must be forbidden';
        }
        $subjects = $subjectContract['subjects'] ?? [];
        $subjectIds = array_column($subjects, 'id');
        if ($subjectIds !== self::VOCABULARY['attestation_subjects']) {
            $errors[] = 'attestation subjects do not match the governed vocabulary';
        }
        foreach ($subjects as $subject) {
            $expected = self::SUBJECT_FIELDS[$subject['id'] ?? ''] ?? null;
            if ($expected === null || [$subject['field'] ?? null, $subject['value_type'] ?? null] !== $expected) {
                $errors[] = sprintf('attestation subject %s has invalid identity semantics', $subject['id'] ?? '?');
            }
        }

        $invariants = $manifest['policy']['invariants'] ?? [];
        $invariantIds = [];
        foreach ($invariants as $invariant) {
            $id = $invariant['id'] ?? '?';
            $invariantIds[] = $id;
            if (!is_string($id) || !preg_match('/^[a-z][a-z0-9-]*$/', $id)) {
                $errors[] = 'invariant IDs must be stable kebab-case identifiers';
            }
            if (!is_string($invariant['owner'] ?? null) || $invariant['owner'] === '') {
                $errors[] = sprintf('invariant %s must name an owner', $id);
            }
        }
        if (count($invariantIds) !== count(array_unique($invariantIds))) {
            $errors[] = 'invariant IDs must be unique';
        }

        $producers = $manifest['policy']['producers'] ?? [];
        $producerIds = array_column($producers, 'id');
        if (count($producerIds) !== count(array_unique($producerIds))) {
            $errors[] = 'producer IDs must be unique';
        }
        $knownSubjects = array_fill_keys(self::VOCABULARY['attestation_subjects'], true);
        foreach ($producers as $producer) {
            $id = $producer['id'] ?? '?';
            foreach (['id', 'workflow_policy_id', 'role', 'expansion', 'selection', 'disposition', 'authority', 'cadence', 'invariant', 'attestation_subjects'] as $field) {
                if (!array_key_exists($field, $producer)) {
                    $errors[] = sprintf('%s is missing %s', $id, $field);
                }
            }
            if (!is_string($id)
                || !preg_match('/^[a-z][a-z0-9-]*$/', $id)
                || !is_string($producer['workflow_policy_id'] ?? null)
                || !preg_match('/^[a-z][a-z0-9-]*$/', $producer['workflow_policy_id'])) {
                $errors[] = 'producer IDs and workflow policy IDs must be stable kebab-case identifiers';
            }
            foreach (['roles' => 'role', 'expansions' => 'expansion', 'dispositions' => 'disposition', 'authorities' => 'authority'] as $vocabulary => $field) {
                if (!in_array($producer[$field] ?? null, self::VOCABULARY[$vocabulary], true)) {
                    $errors[] = sprintf('%s has unknown %s %s', $id, $field, $producer[$field] ?? '?');
                }
            }
            $selections = $producer['selection'] ?? null;
            if (!is_array($selections) || !array_is_list($selections) || $selections === []) {
                $errors[] = sprintf('%s selection must be a non-empty list', $id);
                $selections = [];
            }
            foreach ($selections as $selection) {
                if (!in_array($selection['type'] ?? null, self::VOCABULARY['selections'], true)) {
                    $errors[] = sprintf('%s has unknown selection %s', $id, $selection['type'] ?? '?');
                }
            }
            $cadences = $producer['cadence'] ?? null;
            if (!is_array($cadences) || !array_is_list($cadences) || $cadences === []) {
                $errors[] = sprintf('%s cadence must be a non-empty list', $id);
                $cadences = [];
            }
            foreach ($cadences as $cadence) {
                if (!in_array($cadence, self::VOCABULARY['cadences'], true)) {
                    $errors[] = sprintf('%s has unknown cadence %s', $id, $cadence);
                }
            }
            if (!in_array($producer['invariant'] ?? null, $invariantIds, true)) {
                $errors[] = sprintf('%s references unknown invariant %s', $id, $producer['invariant'] ?? '?');
            }
            $attestationSubjects = $producer['attestation_subjects'] ?? null;
            if (!is_array($attestationSubjects) || !array_is_list($attestationSubjects) || $attestationSubjects === []) {
                $errors[] = sprintf('%s attestation subjects must be a non-empty list', $id);
                $attestationSubjects = [];
            }
            foreach ($attestationSubjects as $subject) {
                if (!isset($knownSubjects[$subject])) {
                    $errors[] = sprintf('%s references unknown attestation subject %s', $id, $subject);
                }
            }
            $hasMatrix = isset($producer['matrix']);
            if (($producer['expansion'] ?? null) === 'matrix' && (!$hasMatrix || !$this->validMatrix($producer['matrix']))) {
                $errors[] = sprintf('%s must define a bounded matrix expansion', $id);
            }
            if (($producer['expansion'] ?? null) === 'singleton' && $hasMatrix) {
                $errors[] = sprintf('%s singleton must not define a matrix', $id);
            }
        }

        foreach (self::VOCABULARY['roles'] as $role) {
            if (!in_array($role, array_column($producers, 'role'), true)) {
                $errors[] = sprintf('policy producers must demonstrate the %s role', $role);
            }
        }
        $this->validateAutoMergePolicy($manifest, $errors);
        $this->validateAggregateContract($manifest, $errors);
        $this->validateArtifactContract($manifest, $errors);
        $this->validateRequiredProjection($manifest, $errors);
        $this->validateResidualTasks($manifest, $errors);

        return array_values(array_unique($errors));
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
    private function validateAutoMergePolicy(array $manifest, array &$errors): void
    {
        $producer = $this->producer($manifest, 'enable-native-auto-merge');
        $selectionTypes = array_column($producer['selection'] ?? [], 'type');
        if (($producer['role'] ?? null) !== 'orchestration'
            || ($producer['authority'] ?? null) !== 'operational'
            || !in_array('event', $selectionTypes, true)
            || !in_array('actor', $selectionTypes, true)) {
            $errors[] = 'conditional native auto-merge must be representable as operational orchestration';
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
        if (($contract['prerequisite_result_requirement'] ?? null) !== 'success') {
            $errors[] = 'aggregate prerequisites must explicitly require success';
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
            if (($owner['role'] ?? null) !== 'aggregate') {
                $errors[] = sprintf('%s must reference an aggregate producer', $aggregate['producer_id'] ?? '?');
            }
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
    }

    /** @param array<string, mixed> $manifest
     *  @param list<string> $errors
     */
    private function validateRequiredProjection(array $manifest, array &$errors): void
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
        if ($names !== self::REQUIRED_CONTEXTS) {
            $errors[] = 'required projection context order and names must match frozen evidence';
        }
        foreach ($contexts as $item) {
            $name = $item['context'] ?? '?';
            $binding = $item['binding'] ?? [];
            if ($name === 'ci/mutation-pilot') {
                if (($binding['mode'] ?? null) !== 'name-only' || array_key_exists('integration_id', $binding) && $binding['integration_id'] !== null) {
                    $errors[] = 'ci/mutation-pilot must remain intentionally name-only';
                }
                continue;
            }
            if (($binding['mode'] ?? null) !== 'github-app' || ($binding['integration_id'] ?? null) !== 15368) {
                $errors[] = sprintf('%s must bind to GitHub Actions app 15368', $name);
            }
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
        $randomOrder = $manifest['policy']['random_order_measurement'] ?? [];
        if (($randomOrder['cadence'] ?? null) !== 'every-pull-request' || ($randomOrder['change_allowed_before_task'] ?? null) !== 8) {
            $errors[] = 'random-order must remain on every pull request during measurement';
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

    /** @param array<string, mixed> $manifest
     *  @return array<string, mixed>
     */
    private function context(array $manifest, string $name): array
    {
        foreach ($manifest['policy']['required_projection']['contexts'] ?? [] as $context) {
            if (($context['context'] ?? null) === $name) {
                return $context;
            }
        }

        self::fail(sprintf('Missing required context %s.', $name));
    }
}
