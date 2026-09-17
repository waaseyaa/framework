<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class CiCheckRosterManifestTest extends TestCase
{
    private const CLASSES = [
        'policy-gate',
        'setup',
        'execution',
        'matrix-shard',
        'aggregate',
        'publication',
    ];

    private const COST_CLASSES = ['tiny', 'small', 'medium', 'large'];

    private string $root;

    /** @var array<string, mixed> */
    private array $manifest;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->manifest = json_decode(
            (string) file_get_contents($this->root . '/tools/ci-check-roster.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    #[Test]
    public function roster_is_valid_and_matches_the_frozen_evidence_counts(): void
    {
        self::assertSame([], $this->validate($this->manifest));
        self::assertSame(1, $this->manifest['schema_version']);
        self::assertSame('FW-CI-CHECK-ROSTER-AUDIT-01', $this->manifest['change_record']);
        self::assertSame('2718edc02f8a47167db1b31b2192690bff773cfd', $this->manifest['source']['pinned_origin_main']);
        self::assertSame('5297875460d47d58a6328380d8702235d6afce2f', $this->manifest['source']['inventory_evidence']['final_pr_head']);
        self::assertSame(35275141646, $this->manifest['source']['inventory_evidence']['successful_ci_run']);
        self::assertSame('63c46174e08d3c3f50ad576980f6933c2d0b680c', $this->manifest['source']['inventory_evidence']['failed_head']);
        self::assertSame(35271711540, $this->manifest['source']['inventory_evidence']['failed_ci_run']);
        self::assertSame(15181711, $this->manifest['source']['ruleset']['id']);

        $expanded = $this->expandedContexts($this->manifest);
        self::assertCount(55, $expanded, 'The PR #3086 inventory must expand to 55 visible checks.');
        self::assertCount(55, array_unique($expanded), 'Every expanded visible check must be unique.');

        $floor = 0;
        foreach ($this->manifest['checks'] as $check) {
            if ($check['unconditional_pr_floor'] === true) {
                $floor += isset($check['matrix_pattern']) ? count($check['matrix_pattern']['values']) : 1;
            }
        }
        self::assertSame(46, $floor, 'The unconditional pull-request floor must remain 46 checks.');

        $required = array_values(array_filter(
            $this->manifest['checks'],
            static fn(array $check): bool => $check['current_required_status'] === true,
        ));
        self::assertCount(22, $required, 'The frozen ruleset contains 22 required contexts.');
    }

    #[Test]
    public function producer_jobs_and_visible_contexts_exist_in_the_pinned_workflows(): void
    {
        foreach ($this->manifest['checks'] as $check) {
            $workflow = $this->root . '/' . $check['producer']['workflow'];
            self::assertFileExists($workflow, $check['stable_id']);
            $contents = (string) file_get_contents($workflow);
            self::assertMatchesRegularExpression(
                '/^  ' . preg_quote($check['producer']['job'], '/') . ':$/m',
                $contents,
                sprintf('%s names a producer job absent from %s.', $check['stable_id'], $check['producer']['workflow']),
            );

            $context = $check['visible_context'] ?? $check['matrix_pattern']['template'];
            self::assertStringContainsString('name: ' . $context, $contents, $check['stable_id']);
        }
    }

    #[Test]
    public function validator_rejects_duplicate_stable_ids(): void
    {
        $mutated = $this->manifest;
        $mutated['checks'][1]['stable_id'] = $mutated['checks'][0]['stable_id'];

        self::assertContains('check stable IDs must be unique', $this->validate($mutated));
    }

    #[Test]
    public function validator_rejects_unknown_classes(): void
    {
        $mutated = $this->manifest;
        $mutated['checks'][0]['class'] = 'mystery';

        self::assertContains('ci.immutable-source-artifact has unknown class mystery', $this->validate($mutated));
    }

    #[Test]
    public function validator_rejects_malformed_matrix_patterns(): void
    {
        $mutated = $this->manifest;
        $index = $this->checkIndex($mutated, 'ci.phpunit-shards');
        $mutated['checks'][$index]['matrix_pattern']['template'] = 'ci/test-shard-{matrix.id}';

        self::assertContains('ci.phpunit-shards has a malformed matrix pattern', $this->validate($mutated));
    }

    #[Test]
    public function validator_rejects_missing_invariant_ownership(): void
    {
        $mutated = $this->manifest;
        $mutated['invariants'][0]['owner'] = '';

        self::assertContains(
            sprintf('invariant %s must name an owner', $mutated['invariants'][0]['id']),
            $this->validate($mutated),
        );
    }

    /** @param array<string, mixed> $manifest
     *  @return list<string>
     */
    private function validate(array $manifest): array
    {
        $errors = [];
        $checks = $manifest['checks'] ?? null;
        $invariants = $manifest['invariants'] ?? null;
        if (!is_array($checks) || !is_array($invariants)) {
            return ['checks and invariants must be arrays'];
        }

        $invariantOwners = [];
        foreach ($invariants as $invariant) {
            $id = $invariant['id'] ?? '?';
            if (!is_string($id) || $id === '') {
                $errors[] = 'every invariant must have an ID';
                continue;
            }
            if (!is_string($invariant['owner'] ?? null) || $invariant['owner'] === '') {
                $errors[] = sprintf('invariant %s must name an owner', $id);
            }
            $invariantOwners[$id] = $invariant['owner'] ?? null;
        }

        $ids = [];
        foreach ($checks as $check) {
            $id = $check['stable_id'] ?? '?';
            $ids[] = $id;
            foreach ([
                'stable_id', 'producer', 'class', 'invariant', 'trigger_selector', 'dependencies',
                'current_required_status', 'failure_owner', 'artifacts', 'local_equivalent',
                'cost_class', 'unconditional_pr_floor',
            ] as $field) {
                if (!array_key_exists($field, $check)) {
                    $errors[] = sprintf('%s is missing %s', $id, $field);
                }
            }
            if (!in_array($check['class'] ?? null, self::CLASSES, true)) {
                $errors[] = sprintf('%s has unknown class %s', $id, $check['class'] ?? '?');
            }
            if (!in_array($check['cost_class'] ?? null, self::COST_CLASSES, true)) {
                $errors[] = sprintf('%s has unknown cost class %s', $id, $check['cost_class'] ?? '?');
            }
            if (!isset($invariantOwners[$check['invariant'] ?? ''])) {
                $errors[] = sprintf('%s references an invariant without ownership', $id);
            }
            if (!is_string($check['failure_owner'] ?? null) || $check['failure_owner'] === '') {
                $errors[] = sprintf('%s must name a failure owner', $id);
            }
            if (!is_array($check['dependencies'] ?? null)) {
                $errors[] = sprintf('%s dependencies must be an array', $id);
            }
            if (!is_bool($check['current_required_status'] ?? null)) {
                $errors[] = sprintf('%s required status must be boolean', $id);
            }
            if (!is_bool($check['unconditional_pr_floor'] ?? null)) {
                $errors[] = sprintf('%s floor membership must be boolean', $id);
            }

            $hasExact = isset($check['visible_context']);
            $hasMatrix = isset($check['matrix_pattern']);
            if ($hasExact === $hasMatrix) {
                $errors[] = sprintf('%s must declare exactly one visible context shape', $id);
            }
            if ($hasMatrix && !$this->validMatrixPattern($check['matrix_pattern'])) {
                $errors[] = sprintf('%s has a malformed matrix pattern', $id);
            }
        }

        if (count($ids) !== count(array_unique($ids))) {
            $errors[] = 'check stable IDs must be unique';
        }

        $knownIds = array_fill_keys($ids, true);
        foreach ($checks as $check) {
            foreach ($check['dependencies'] ?? [] as $dependency) {
                if (!isset($knownIds[$dependency])) {
                    $errors[] = sprintf('%s references unknown dependency %s', $check['stable_id'], $dependency);
                }
            }
        }

        $requiredContexts = $manifest['source']['ruleset']['required_contexts'] ?? [];
        if (!is_array($requiredContexts) || count($requiredContexts) !== 22) {
            $errors[] = 'ruleset evidence must contain 22 required contexts';
        } else {
            foreach ($checks as $check) {
                $expected = isset($check['visible_context']) && in_array($check['visible_context'], $requiredContexts, true);
                if (($check['current_required_status'] ?? null) !== $expected) {
                    $errors[] = sprintf('%s required status disagrees with the frozen ruleset', $check['stable_id']);
                }
            }
        }

        return array_values(array_unique($errors));
    }

    /** @param array<string, mixed> $pattern */
    private function validMatrixPattern(array $pattern): bool
    {
        $template = $pattern['template'] ?? null;
        $axis = $pattern['axis'] ?? null;
        $values = $pattern['values'] ?? null;
        $observed = $pattern['observed_contexts'] ?? null;
        if (!is_string($template) || !is_string($axis) || !preg_match('/^[a-z][a-z0-9_]*$/', $axis)) {
            return false;
        }
        $token = '${{ matrix.' . $axis . ' }}';
        if (substr_count($template, $token) !== 1 || preg_match('/[{}]/', str_replace($token, '', $template))) {
            return false;
        }
        if (!is_array($values) || $values === [] || count($values) !== count(array_unique($values, SORT_REGULAR))) {
            return false;
        }
        if (!is_array($observed) || count($observed) !== count($values)) {
            return false;
        }
        $expanded = array_map(
            static fn(mixed $value): string => str_replace($token, (string) $value, $template),
            $values,
        );

        return $expanded === $observed;
    }

    /** @param array<string, mixed> $manifest
     *  @return list<string>
     */
    private function expandedContexts(array $manifest): array
    {
        $contexts = [];
        foreach ($manifest['checks'] as $check) {
            if (isset($check['visible_context'])) {
                $contexts[] = $check['visible_context'];
                continue;
            }
            foreach ($check['matrix_pattern']['observed_contexts'] as $context) {
                $contexts[] = $context;
            }
        }

        return $contexts;
    }

    /** @param array<string, mixed> $manifest */
    private function checkIndex(array $manifest, string $stableId): int
    {
        foreach ($manifest['checks'] as $index => $check) {
            if (($check['stable_id'] ?? null) === $stableId) {
                return $index;
            }
        }

        self::fail(sprintf('Missing check %s.', $stableId));
    }
}
