<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use CiWorkflowInventoryFailure;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Focused proof for the generated CI workflow inventory
 * (FW-CI-CHECK-ROSTER-AUDIT-01, Task 2; GitHub mirror #3087).
 *
 * Fixture roots exercise every derivation rule the generator documents;
 * negative fixtures prove it fails closed with a distinguishing message; and
 * one repository-level case proves the tracked
 * `tools/ci-workflow-inventory.json` is byte-identical to a fresh generation
 * and pins a handful of real workflow facts.
 *
 * The test reads workflow YAML and the generated inventory only. It never
 * reads `tools/ci-check-roster.json` and makes no policy or conformance
 * claim: comparing the inventory with the policy is Task 3.
 */
#[CoversNothing]
final class CiWorkflowInventoryGeneratorTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/bin/lib/ci-workflow-inventory.php';
    }

    protected function tearDown(): void
    {
        $filesystem = new Filesystem();
        foreach ($this->roots as $root) {
            if (is_dir($root)) {
                $filesystem->remove($root);
            }
        }
        $this->roots = [];
    }

    // -----------------------------------------------------------------------
    // Visible contexts
    // -----------------------------------------------------------------------

    #[Test]
    public function jobNamesDeriveTheVisibleContextsTheRulesDocument(): void
    {
        $inventory = $this->build(['naming.yml' => self::namingWorkflow()]);
        $workflow = self::workflow($inventory, 'naming.yml');

        $literal = self::job($inventory, 'naming.yml', 'literal-name');
        self::assertSame('literal', $literal['name']['kind']);
        self::assertSame(['ci/literal'], self::contexts($literal));
        self::assertSame(['literal'], array_column($literal['contexts'], 'derivation'));

        $template = self::job($inventory, 'naming.yml', 'template-name');
        self::assertSame('bounded-expansion', $template['name']['kind']);
        self::assertSame('matrix', $template['expansion']);
        self::assertSame(
            ['ci/test-shard-1', 'ci/test-shard-2', 'ci/test-shard-3'],
            self::contexts($template),
        );
        self::assertSame(['id' => 1], $template['contexts'][0]['matrix']);

        $object = self::job($inventory, 'naming.yml', 'object-axis');
        self::assertSame('bounded-expansion', $object['name']['kind']);
        self::assertSame(['split alpha', 'split beta'], self::contexts($object));
        self::assertSame(
            ['pkg' => ['local' => 'packages/alpha', 'remote' => 'alpha']],
            $object['contexts'][0]['matrix'],
        );

        $singleton = self::job($inventory, 'naming.yml', 'unnamed-singleton');
        self::assertSame(['unnamed-singleton'], self::contexts($singleton));
        self::assertSame(['default-job-key'], array_column($singleton['contexts'], 'derivation'));
        self::assertNull($singleton['name']['raw']);

        $defaulted = self::job($inventory, 'naming.yml', 'unnamed-matrix');
        self::assertSame('bounded-expansion', $defaulted['name']['kind']);
        self::assertSame(['default-matrix'], array_unique(array_column($defaulted['contexts'], 'derivation')));
        self::assertSame(
            ['unnamed-matrix (8.4, ubuntu-latest)', 'unnamed-matrix (8.5, ubuntu-latest)'],
            self::contexts($defaulted),
        );

        $flattened = self::job($inventory, 'naming.yml', 'unnamed-object-matrix');
        self::assertSame(
            ['unnamed-object-matrix (packages/alpha, alpha)'],
            self::contexts($flattened),
        );

        $expression = self::job($inventory, 'naming.yml', 'expression-name');
        self::assertSame('unresolved-expression', $expression['name']['kind']);
        self::assertSame('unresolved-expression', $expression['contexts'][0]['derivation']);
        self::assertSame([null], self::contexts($expression));
        self::assertContains(
            'jobs.expression-name.name: Recover ${{ inputs.tag }}',
            $workflow['unresolved_expressions'],
        );

        self::assertSame(
            ['scope' => 'repository-default', 'kind' => 'unresolved-expression', 'shorthand' => null, 'grants' => null],
            $literal['effective_permissions'],
        );
    }

    // -----------------------------------------------------------------------
    // Matrices
    // -----------------------------------------------------------------------

    #[Test]
    public function expressionMatricesStayUnresolvedAndAreReported(): void
    {
        $inventory = $this->build(['unresolved.yml' => self::unresolvedMatrixWorkflow()]);
        $workflow = self::workflow($inventory, 'unresolved.yml');

        $whole = self::job($inventory, 'unresolved.yml', 'whole-matrix');
        self::assertSame('unresolved-expression', $whole['matrix']['resolution']);
        self::assertNull($whole['matrix']['combinations']);
        self::assertSame('${{ fromJSON(needs.prepare.outputs.matrix) }}', $whole['matrix']['raw_expression']);
        self::assertSame('default-matrix-unresolved', $whole['name']['derivation']);
        self::assertSame([null], self::contexts($whole));

        $axis = self::job($inventory, 'unresolved.yml', 'axis-expression');
        self::assertSame('unresolved-expression', $axis['matrix']['resolution']);
        self::assertNull($axis['matrix']['combinations']);
        self::assertSame(
            ['package' => '${{ fromJSON(needs.prepare.outputs.packages) }}'],
            $axis['matrix']['expression_axes'],
        );

        self::assertSame([
            'jobs.axis-expression.name: default name over an unresolved matrix',
            'jobs.axis-expression.strategy.matrix.package: ${{ fromJSON(needs.prepare.outputs.packages) }}',
            'jobs.whole-matrix.name: default name over an unresolved matrix',
            'jobs.whole-matrix.strategy.matrix: ${{ fromJSON(needs.prepare.outputs.matrix) }}',
        ], $workflow['unresolved_expressions']);
        // `prepare` is the only job whose context the generator can name.
        self::assertSame(1, $inventory['summary']['visible_context_count']);
        self::assertSame(4, $inventory['summary']['unresolved_expression_count']);
    }

    #[Test]
    public function includeAndExcludeFollowTheDocumentedGithubSemantics(): void
    {
        $inventory = $this->build(['matrix.yml' => self::includeExcludeWorkflow()]);

        // The worked example from GitHub's "Using a matrix for your jobs"
        // documentation, asserted combination for combination.
        self::assertSame([
            ['fruit' => 'apple', 'animal' => 'cat', 'color' => 'pink', 'shape' => 'circle'],
            ['fruit' => 'apple', 'animal' => 'dog', 'color' => 'green', 'shape' => 'circle'],
            ['fruit' => 'pear', 'animal' => 'cat', 'color' => 'pink'],
            ['fruit' => 'pear', 'animal' => 'dog', 'color' => 'green'],
            ['fruit' => 'banana'],
            ['fruit' => 'banana', 'animal' => 'cat'],
        ], self::job($inventory, 'matrix.yml', 'documented-include')['matrix']['combinations']);

        self::assertSame([
            ['os' => 'ubuntu-latest', 'php' => '8.4'],
            ['os' => 'ubuntu-latest', 'php' => '8.5'],
            ['os' => 'windows-latest', 'php' => '8.5'],
        ], self::job($inventory, 'matrix.yml', 'excluded')['matrix']['combinations']);
    }

    // -----------------------------------------------------------------------
    // Dependency graph, aggregates, expected skips
    // -----------------------------------------------------------------------

    #[Test]
    public function dependencyEdgesAggregatesAndExpectedSkipsAreDerivedFromTheGraph(): void
    {
        $inventory = $this->build(['deps.yml' => self::dependencyWorkflow()]);

        $alpha = self::job($inventory, 'deps.yml', 'alpha');
        self::assertSame([], $alpha['needs']);
        self::assertSame(['beta', 'gate'], $alpha['needed_by']);
        self::assertTrue($alpha['expected_skip']['own_condition']);
        self::assertFalse($alpha['expected_skip']['propagates_prerequisite_failure']);

        // `needs: alpha` (a scalar) and `needs: [beta, alpha]` (a list) both
        // normalize to a sorted list.
        $beta = self::job($inventory, 'deps.yml', 'beta');
        self::assertSame(['alpha'], $beta['needs']);
        self::assertSame(['gate'], $beta['needed_by']);
        self::assertSame([
            'own_condition' => false,
            'inherited_from' => ['alpha'],
            'propagates_prerequisite_failure' => true,
        ], $beta['expected_skip']);

        $gate = self::job($inventory, 'deps.yml', 'gate');
        self::assertSame(['alpha', 'beta'], $gate['needs']);
        self::assertSame('aggregate', $gate['structural_role']);
        self::assertSame([
            'gate' => 'always',
            'prerequisites' => ['alpha', 'beta'],
            'result_checked_prerequisites' => ['beta'],
            'result_unchecked_prerequisites' => ['alpha'],
        ], $gate['aggregate']);
        self::assertSame([
            ['job' => 'beta', 'locations' => ['steps.env']],
        ], $gate['dependency_result_references']);
        self::assertSame([
            'own_condition' => false,
            'inherited_from' => ['alpha'],
            'propagates_prerequisite_failure' => false,
        ], $gate['expected_skip']);
    }

    // -----------------------------------------------------------------------
    // Artifacts
    // -----------------------------------------------------------------------

    #[Test]
    public function artifactFlowsBindPatternConsumersAndCrossRunCandidates(): void
    {
        $inventory = $this->build([
            'artifacts.yml' => self::artifactProducerWorkflow(),
            'cross-run.yml' => self::crossRunConsumerWorkflow(),
        ]);

        $bounded = self::flow($inventory, 'artifacts.yml', 'foo-${{ matrix.id }}');
        self::assertSame('producer', $bounded['producers'][0]['job']);
        self::assertSame('bounded-expansion', $bounded['producers'][0]['kind']);
        self::assertSame(['foo-1', 'foo-2'], $bounded['producers'][0]['expansion']);

        $pattern = self::flow($inventory, 'artifacts.yml', 'foo-*');
        self::assertSame([], $pattern['producers']);
        self::assertSame('consumer', $pattern['consumers'][0]['job']);
        self::assertSame('pattern', $pattern['consumers'][0]['via']);
        self::assertSame(['producer'], $pattern['consumers'][0]['matched_producers']);

        // A cross-run download names no in-workflow producer; its candidates
        // are glob matches over every bounded producer name in the repository
        // and are candidates only.
        $crossRun = self::flow($inventory, 'cross-run.yml', 'foo-*');
        self::assertSame([], $crossRun['consumers']);
        self::assertSame('collect', $crossRun['cross_run_consumers'][0]['job']);
        self::assertSame([], $crossRun['cross_run_consumers'][0]['matched_producers']);
        self::assertSame(['artifacts.yml#producer'], $crossRun['cross_run_producer_candidates']);
    }

    // -----------------------------------------------------------------------
    // Triggers
    // -----------------------------------------------------------------------

    #[Test]
    public function triggerSelectorsArePreservedVerbatimInAStableOrder(): void
    {
        $triggers = self::workflow(
            $this->build(['triggers.yml' => self::triggerWorkflow()]),
            'triggers.yml',
        )['triggers'];

        self::assertSame(
            ['pull_request', 'push', 'schedule', 'workflow_dispatch', 'workflow_run'],
            $triggers['events'],
        );
        self::assertSame([
            ['event' => 'pull_request', 'paths_ignore' => ['docs/**'], 'types' => ['opened', 'labeled']],
            ['event' => 'push', 'branches' => ['main'], 'tags' => ['v*'], 'paths' => ['packages/**']],
            ['event' => 'schedule', 'cron' => ['0 3 * * *', '30 7 * * 1']],
            ['event' => 'workflow_dispatch', 'inputs' => [
                ['name' => 'alpha', 'required' => true, 'type' => 'number', 'has_default' => false],
                ['name' => 'zebra', 'required' => false, 'type' => 'string', 'has_default' => true],
            ]],
            ['event' => 'workflow_run', 'types' => ['completed'], 'workflows' => ['Triggers']],
        ], $triggers['selectors']);
    }

    // -----------------------------------------------------------------------
    // Conditions, roles, permissions, local equivalents
    // -----------------------------------------------------------------------

    #[Test]
    public function jobConditionsAreClassifiedByWhatTheyReferenceNotByEvaluation(): void
    {
        $inventory = $this->build(['conditions.yml' => self::conditionWorkflow()]);

        $labelled = self::job($inventory, 'conditions.yml', 'labelled')['if'];
        self::assertSame('unresolved-expression', $labelled['kind']);
        self::assertSame(['event', 'label'], $labelled['classification']);
        self::assertSame(['auto-merge-when-green', 'pull_request'], $labelled['literals']);
        self::assertSame(['github.event.label.name', 'github.event_name'], $labelled['references']);

        // An enclosing ${{ }} wrapper is stripped for the normalized form and
        // kept verbatim in `raw`.
        $actor = self::job($inventory, 'conditions.yml', 'by-actor')['if'];
        self::assertSame(['actor'], $actor['classification']);
        self::assertSame('${{ github.actor == \'dependabot[bot]\' }}', $actor['raw']);
        self::assertSame('github.actor == \'dependabot[bot]\'', $actor['normalized']);
        self::assertSame(['dependabot[bot]'], $actor['literals']);

        $gate = self::job($inventory, 'conditions.yml', 'gate')['if'];
        self::assertSame(['always', 'dependency-result'], $gate['classification']);
        self::assertSame(['needs.labelled.result'], $gate['references']);
    }

    #[Test]
    public function notCancelledAndFailureGatesAreDistinguishedFromAlways(): void
    {
        $inventory = $this->build(['conditions.yml' => self::conditionWorkflow()]);

        // `!cancelled()` runs on success AND failure, so it neither propagates
        // a prerequisite failure nor fails to qualify as an aggregate gate.
        // The classification carries both tokens; a bare cancelled() would
        // carry only `cancelled`.
        $notCancelled = self::job($inventory, 'conditions.yml', 'not-cancelled-gate');
        self::assertSame(['cancelled', 'not-cancelled'], $notCancelled['if']['classification']);
        self::assertSame('aggregate', $notCancelled['structural_role']);
        self::assertSame('not-cancelled', $notCancelled['aggregate']['gate']);
        self::assertSame(['by-actor', 'labelled'], $notCancelled['aggregate']['prerequisites']);
        self::assertFalse($notCancelled['expected_skip']['propagates_prerequisite_failure']);
        self::assertTrue($notCancelled['expected_skip']['own_condition']);

        // `failure()` also does not propagate — it runs BECAUSE something
        // failed — but it cannot gate a successful run, so it is no aggregate.
        $onFailure = self::job($inventory, 'conditions.yml', 'on-failure');
        self::assertSame(['failure'], $onFailure['if']['classification']);
        self::assertNull($onFailure['aggregate']);
        self::assertSame('execution', $onFailure['structural_role']);
        self::assertFalse($onFailure['expected_skip']['propagates_prerequisite_failure']);

        // The control: no condition at all still propagates.
        self::assertTrue(
            self::job($inventory, 'conditions.yml', 'plain-dependent')['expected_skip']['propagates_prerequisite_failure'],
        );
    }

    #[Test]
    public function singletonShapesAreRecordedWithoutInventingExpansionOrExpressions(): void
    {
        $inventory = $this->build(['shapes.yml' => self::shapeWorkflow()]);
        $workflow = self::workflow($inventory, 'shapes.yml');

        // A repeated prerequisite is one edge in both directions.
        $duplicate = self::job($inventory, 'shapes.yml', 'duplicate-needs');
        self::assertSame(['alpha'], $duplicate['needs']);
        self::assertSame(['duplicate-needs'], self::job($inventory, 'shapes.yml', 'alpha')['needed_by']);
        self::assertSame(['kind' => 'literal', 'value' => 12], $duplicate['timeout_minutes']);

        // `strategy:` without `matrix:` is legal and expands nothing.
        $noMatrix = self::job($inventory, 'shapes.yml', 'no-matrix-strategy');
        self::assertSame('singleton', $noMatrix['expansion']);
        self::assertNull($noMatrix['matrix']);
        self::assertSame([
            'keys' => ['fail-fast', 'max-parallel'],
            'fail_fast' => false,
            'max_parallel' => 2,
        ], $noMatrix['strategy']);
        self::assertSame(['no-matrix-strategy'], self::contexts($noMatrix));

        // An expression timeout is reported, never silently dropped to null.
        $timeout = self::job($inventory, 'shapes.yml', 'expression-timeout');
        self::assertSame('unresolved-expression', $timeout['timeout_minutes']['kind']);
        self::assertContains(
            'jobs.expression-timeout.timeout-minutes: "${{ fromJSON(inputs.budget) }}"',
            $workflow['unresolved_expressions'],
        );

        // A job-level `uses:` is a literal reference, not an expression.
        $reusable = self::job($inventory, 'shapes.yml', 'reusable');
        self::assertSame('./.github/workflows/naming.yml', $reusable['reusable_workflow']);
        self::assertNull($reusable['runs_on']);
        foreach ($workflow['unresolved_expressions'] as $entry) {
            self::assertStringNotContainsString('.uses:', $entry);
        }
        self::assertSame(
            ['jobs.expression-timeout.timeout-minutes: "${{ fromJSON(inputs.budget) }}"'],
            $workflow['unresolved_expressions'],
        );
    }

    #[Test]
    public function commandScanningNeverSplicesUnrelatedLinesIntoACommand(): void
    {
        $spliced = self::job($this->build(['splice.yml' => self::spliceWorkflow()]), 'splice.yml', 'spliced');

        // A dropped comment line would join `echo composer` to
        // `install-something`, and a bare line-leading match would read a
        // continued PHPUnit path argument as an executable. Neither happens.
        self::assertSame(['vendor/bin/phpunit'], $spliced['local_equivalent']['commands']);
        self::assertSame('no-recognised-command', self::job(
            $this->build(['splice.yml' => self::spliceWorkflow()]),
            'splice.yml',
            'silent',
        )['local_equivalent']['status']);
    }

    #[Test]
    public function structuralRolesPermissionsAndLocalEquivalentsFollowTheDocumentedRules(): void
    {
        $inventory = $this->build(['roles.yml' => self::roleWorkflow()]);

        $setup = self::job($inventory, 'roles.yml', 'setup-step');
        self::assertSame('setup', $setup['structural_role']);
        self::assertSame(['plan'], $setup['outputs']);
        self::assertSame('discoverable', $setup['local_equivalent']['status']);
        self::assertSame(['bin/build-phpunit-shards'], $setup['local_equivalent']['commands']);
        self::assertSame(['scope' => 'workflow', 'kind' => 'literal', 'shorthand' => null, 'grants' => [
            'contents' => 'read',
        ]], $setup['effective_permissions']);

        $execution = self::job($inventory, 'roles.yml', 'execution-step');
        self::assertSame('execution', $execution['structural_role']);
        self::assertSame(['scope' => 'job', 'kind' => 'literal', 'shorthand' => null, 'grants' => [
            'contents' => 'read',
            'packages' => 'write',
        ]], $execution['effective_permissions']);

        $publication = self::job($inventory, 'roles.yml', 'publication-step');
        self::assertSame('publication', $publication['structural_role']);
        self::assertSame(['git-push'], $publication['role_evidence']['publication_signals']);
        self::assertSame('partial', $publication['local_equivalent']['status']);
        self::assertSame(['bin/sync-internal-versions'], $publication['local_equivalent']['commands']);
        self::assertSame(['git-push', 'secrets'], $publication['local_equivalent']['hosted_signals']);

        $orchestration = self::job($inventory, 'roles.yml', 'orchestration-step');
        self::assertSame('orchestration', $orchestration['structural_role']);
        self::assertSame(['governed-auto-merge'], $orchestration['role_evidence']['orchestration_signals']);

        $hosted = self::job($inventory, 'roles.yml', 'hosted-signals-step');
        self::assertSame('execution', $hosted['structural_role']);
        self::assertSame('hosted-signals-only', $hosted['local_equivalent']['status']);
        self::assertSame([], $hosted['local_equivalent']['commands']);
        self::assertSame(['gh-cli'], $hosted['local_equivalent']['hosted_signals']);
    }

    // -----------------------------------------------------------------------
    // Determinism
    // -----------------------------------------------------------------------

    #[Test]
    public function generationIsByteIdenticalAcrossRunsAndLineEndingConventions(): void
    {
        $lf = self::namingWorkflow();
        $crlf = str_replace("\n", "\r\n", $lf);

        $first = \cwi_render($this->build(['naming.yml' => $lf]));
        $second = \cwi_render($this->build(['naming.yml' => $lf]));
        $windows = \cwi_render($this->build(['naming.yml' => $crlf]));

        self::assertSame($first, $second);
        self::assertSame($first, $windows, 'CRLF input must produce identical bytes, hashes included.');
        self::assertStringEndsWith("}\n", $first);
        self::assertStringNotContainsString("\r", $first);
    }

    #[Test]
    public function jobOrderInTheSourceFileDoesNotAffectTheInventory(): void
    {
        $forward = $this->build(['order.yml' => self::orderWorkflow(false)]);
        $reversed = $this->build(['order.yml' => self::orderWorkflow(true)]);

        // Only the source hashes may differ: the bytes really are different,
        // but every derived record is ordered by job key, not by YAML order.
        self::assertNotSame(
            $forward['source']['files'][0]['sha256'],
            $reversed['source']['files'][0]['sha256'],
        );
        self::assertSame(self::withoutSourceHashes($forward), self::withoutSourceHashes($reversed));
    }

    // -----------------------------------------------------------------------
    // Fail-closed behaviour
    // -----------------------------------------------------------------------

    #[Test]
    #[DataProvider('invalidWorkflowCases')]
    public function generationFailsClosedOnStructurallyInvalidWorkflows(string $yaml, string $expected): void
    {
        $this->expectException(CiWorkflowInventoryFailure::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expected, '/') . '/');

        $this->build(['broken.yml' => $yaml]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidWorkflowCases(): iterable
    {
        yield 'unparsable YAML' => [
            "on: push\njobs:\n  a: 'unterminated\n",
            'unparsable workflow broken.yml',
        ];
        yield 'no triggers' => [
            "name: No triggers\njobs:\n  build:\n    runs-on: ubuntu-latest\n    steps:\n      - run: echo hi\n",
            'workflow broken.yml declares no `on` triggers',
        ];
        yield 'no jobs key' => [
            "name: No jobs\non: push\n",
            'workflow broken.yml declares no jobs',
        ];
        yield 'empty jobs mapping' => [
            "name: Empty jobs\non: push\njobs: {}\n",
            'workflow broken.yml declares no jobs',
        ];
        yield 'job without a runner' => [
            "on: push\njobs:\n  orphan:\n    steps:\n      - run: echo hi\n",
            'workflow broken.yml job orphan declares neither runs-on nor uses',
        ];
        yield 'needs an unknown job' => [
            self::minimalWorkflow("    needs: absent\n"),
            'workflow broken.yml job build needs unknown job absent',
        ];
        // `strategy:` without `matrix:` is legal and is a positive case; see
        // singletonShapesAreRecordedWithoutInventingExpansionOrExpressions().
        yield 'strategy that is not a mapping' => [
            self::minimalWorkflow("    strategy: nope\n"),
            'workflow broken.yml job build strategy is not a mapping',
        ];
        yield 'axis that is a plain string' => [
            self::minimalWorkflow("    strategy:\n      matrix:\n        php: '8.5'\n"),
            'workflow broken.yml job build matrix axis php is a non-expression string',
        ];
        yield 'axis that is an empty list' => [
            self::minimalWorkflow("    strategy:\n      matrix:\n        php: []\n"),
            'workflow broken.yml job build matrix axis php must be a non-empty list',
        ];
        yield 'include entry that is not a mapping' => [
            self::minimalWorkflow("    strategy:\n      matrix:\n        php: ['8.5']\n        include:\n          - plain\n"),
            'workflow broken.yml job build matrix include entries must be mappings',
        ];
    }

    // -----------------------------------------------------------------------
    // Command-line contract
    // -----------------------------------------------------------------------

    #[Test]
    public function theGeneratorWritesAtomicallyAndDetectsDriftAgainstTheTrackedFile(): void
    {
        $root = $this->fixtureRoot(['naming.yml' => self::namingWorkflow()]);
        mkdir($root . '/tools');

        $missing = self::runCli(['--root=' . $root, '--check']);
        self::assertSame(1, $missing['exit'], $missing['error']);
        self::assertStringContainsString('does not exist', $missing['error']);

        $written = self::runCli(['--root=' . $root, '--write']);
        self::assertSame(0, $written['exit'], $written['error']);
        self::assertSame(0, self::runCli(['--root=' . $root, '--check'])['exit']);

        $target = $root . '/tools/ci-workflow-inventory.json';
        self::assertSame(
            (string) file_get_contents($target),
            self::runCli(['--root=' . $root])['output'],
            'stdout and --write must render the same bytes.',
        );
        self::assertSame([], glob($root . '/tools/ci-workflow-inventory.json.tmp-*') ?: []);

        file_put_contents($target, "{}\n");
        $stale = self::runCli(['--root=' . $root, '--check']);
        self::assertSame(1, $stale['exit']);
        self::assertStringContainsString('differs from a fresh generation', $stale['error']);
    }

    #[Test]
    public function theGeneratorRejectsUnknownOptionsAndAMissingWorkflowDirectory(): void
    {
        $usage = self::runCli(['--bogus']);
        self::assertSame(2, $usage['exit']);
        self::assertStringContainsString('unknown argument --bogus', $usage['error']);

        $empty = sys_get_temp_dir() . '/waaseyaa-cwi-empty-' . bin2hex(random_bytes(6));
        mkdir($empty, 0o777, true);
        $this->roots[] = $empty;

        $missing = self::runCli(['--root=' . $empty]);
        self::assertSame(1, $missing['exit']);
        self::assertStringContainsString('workflow directory not found', $missing['error']);

        $notADirectory = self::runCli(['--root=' . $empty . '/nope']);
        self::assertSame(2, $notADirectory['exit']);
    }

    // -----------------------------------------------------------------------
    // The repository itself
    // -----------------------------------------------------------------------

    #[Test]
    public function theTrackedInventoryIsByteIdenticalToAFreshGeneration(): void
    {
        $root = dirname(__DIR__, 2);
        $tracked = (string) file_get_contents($root . '/tools/ci-workflow-inventory.json');

        self::assertSame(
            $tracked,
            \cwi_render(\cwi_build_inventory($root)),
            'Run `php bin/generate-ci-workflow-inventory --write` after changing any workflow.',
        );
    }

    #[Test]
    public function theTrackedInventoryPinsTheRepositoryWorkflowFacts(): void
    {
        $inventory = self::trackedInventory();

        self::assertSame(1, $inventory['schema_version']);
        self::assertSame('.github/workflows', $inventory['source']['directory']);
        self::assertSame(22, $inventory['summary']['workflow_count']);
        self::assertCount(22, $inventory['workflows']);
        self::assertCount(22, $inventory['source']['files']);

        $shards = self::job($inventory, 'ci.yml', 'ci-test-shards');
        self::assertSame('bounded-expansion', $shards['name']['kind']);
        self::assertSame(
            ['ci/test-shard-1', 'ci/test-shard-2', 'ci/test-shard-3', 'ci/test-shard-4'],
            self::contexts($shards),
        );

        self::assertSame(
            ['ci/random-order-shard-1', 'ci/random-order-shard-2'],
            self::contexts(self::job($inventory, 'ci.yml', 'ci-random-order-shard')),
        );

        // 77 literal `package` entries in split.yml, each an object axis
        // value, rendered through the explicit stable package-name template.
        $split = self::job($inventory, 'split.yml', 'split');
        self::assertSame('literal', $split['matrix']['resolution']);
        self::assertCount(77, $split['matrix']['combinations']);
        self::assertSame('template-expanded', $split['name']['derivation']);
        self::assertSame('Split release / foundation', self::contexts($split)[0]);
        $provenance = self::flow($inventory, 'split.yml', 'split-provenance-${{ matrix.package.remote }}');
        self::assertSame('bounded-expansion', $provenance['producers'][0]['kind']);
        self::assertCount(77, $provenance['producers'][0]['expansion']);
        self::assertSame(
            ['split'],
            self::flow($inventory, 'split.yml', 'split-provenance-*')['consumers'][0]['matched_producers'],
        );

        $autoMerge = self::job($inventory, 'auto-merge.yml', 'enable-auto-merge');
        self::assertSame('orchestration', $autoMerge['structural_role']);
        self::assertSame(['event', 'label'], $autoMerge['if']['classification']);
        self::assertContains('auto-merge-when-green', $autoMerge['if']['literals']);

        $verify = self::job($inventory, 'packagist-update.yml', 'verify');
        self::assertSame('unresolved-expression', $verify['matrix']['resolution']);
        self::assertNull($verify['matrix']['combinations']);
        self::assertSame('unresolved-expression', $verify['name']['derivation']);
        self::assertSame('Verify Packagist publication / ${{ matrix.package }}', $verify['name']['raw']);
        self::assertSame([null], self::contexts($verify));

        $packagistPublication = self::job($inventory, 'split.yml', 'publish-packagist');
        self::assertSame('publication', $packagistPublication['structural_role']);
        self::assertSame(
            ['packagist-submit-action'],
            $packagistPublication['role_evidence']['publication_signals'],
        );
    }

    #[Test]
    public function theTrackedInventoryPinsTheShardArtifactAndAggregateLineage(): void
    {
        $inventory = self::trackedInventory();

        $shardFamily = self::flow($inventory, 'ci.yml', 'php-test-shard-${{ matrix.id }}');
        self::assertSame('ci-test-shards', $shardFamily['producers'][0]['job']);
        self::assertSame('bounded-expansion', $shardFamily['producers'][0]['kind']);
        self::assertSame(
            ['php-test-shard-1', 'php-test-shard-2', 'php-test-shard-3', 'php-test-shard-4'],
            $shardFamily['producers'][0]['expansion'],
        );

        $pattern = self::flow($inventory, 'ci.yml', 'php-test-shard-*');
        self::assertCount(1, $pattern['consumers']);
        self::assertSame('ci-coverage', $pattern['consumers'][0]['job']);
        self::assertSame('pattern', $pattern['consumers'][0]['via']);
        self::assertSame(['ci-test-shards'], $pattern['consumers'][0]['matched_producers']);

        foreach (['ci-coverage', 'ci-unit-tests'] as $key) {
            $aggregate = self::job($inventory, 'ci.yml', $key);
            self::assertSame('aggregate', $aggregate['structural_role'], $key);
            self::assertSame('always', $aggregate['aggregate']['gate'], $key);
            self::assertContains('ci-test-shards', $aggregate['aggregate']['result_checked_prerequisites'], $key);
        }
    }

    // -----------------------------------------------------------------------
    // Fixture and assertion helpers
    // -----------------------------------------------------------------------

    /**
     * @param array<string, string> $workflows file name => YAML contents
     * @return array<string, mixed>
     */
    private function build(array $workflows): array
    {
        return \cwi_build_inventory($this->fixtureRoot($workflows));
    }

    /** @param array<string, string> $workflows */
    private function fixtureRoot(array $workflows): string
    {
        $root = sys_get_temp_dir() . '/waaseyaa-cwi-' . bin2hex(random_bytes(6));
        mkdir($root . '/.github/workflows', 0o777, true);
        foreach ($workflows as $name => $contents) {
            file_put_contents($root . '/.github/workflows/' . $name, $contents);
        }
        $this->roots[] = $root;

        return $root;
    }

    /**
     * @param list<string> $arguments
     * @return array{exit: int, output: string, error: string}
     */
    private static function runCli(array $arguments): array
    {
        $process = new Process(
            array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/bin/generate-ci-workflow-inventory'], $arguments),
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

    /** @return array<string, mixed> */
    private static function trackedInventory(): array
    {
        return json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/tools/ci-workflow-inventory.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param array<string, mixed> $inventory
     * @return array<string, mixed>
     */
    private static function workflow(array $inventory, string $file): array
    {
        foreach ($inventory['workflows'] as $workflow) {
            if ($workflow['file'] === $file) {
                return $workflow;
            }
        }

        self::fail(sprintf('Workflow %s is not in the inventory.', $file));
    }

    /**
     * @param array<string, mixed> $inventory
     * @return array<string, mixed>
     */
    private static function job(array $inventory, string $file, string $key): array
    {
        foreach (self::workflow($inventory, $file)['jobs'] as $job) {
            if ($job['key'] === $key) {
                return $job;
            }
        }

        self::fail(sprintf('Job %s is not in %s.', $key, $file));
    }

    /**
     * @param array<string, mixed> $inventory
     * @return array<string, mixed>
     */
    private static function flow(array $inventory, string $file, string $id): array
    {
        foreach (self::workflow($inventory, $file)['artifact_flows'] as $flow) {
            if ($flow['id'] === $id) {
                return $flow;
            }
        }

        self::fail(sprintf('Artifact flow %s is not in %s.', $id, $file));
    }

    /**
     * @param array<string, mixed> $job
     * @return list<string|null>
     */
    private static function contexts(array $job): array
    {
        return array_column($job['contexts'], 'context');
    }

    /**
     * @param array<string, mixed> $inventory
     * @return array<string, mixed>
     */
    private static function withoutSourceHashes(array $inventory): array
    {
        foreach (array_keys($inventory['source']['files']) as $index) {
            $inventory['source']['files'][$index]['sha256'] = null;
        }
        foreach (array_keys($inventory['workflows']) as $index) {
            $inventory['workflows'][$index]['source_sha256'] = null;
        }

        return $inventory;
    }

    private static function minimalWorkflow(string $extra): string
    {
        return "name: Broken\non: push\njobs:\n  build:\n    runs-on: ubuntu-latest\n"
            . $extra
            . "    steps:\n      - run: echo hi\n";
    }

    // -----------------------------------------------------------------------
    // Fixture workflows
    // -----------------------------------------------------------------------

    private static function namingWorkflow(): string
    {
        return <<<'YAML'
            name: Naming
            on:
              push:
                branches: [main]
            jobs:
              literal-name:
                name: ci/literal
                runs-on: ubuntu-latest
                steps:
                  - run: echo literal
              template-name:
                name: ci/test-shard-${{ matrix.id }}
                runs-on: ubuntu-latest
                strategy:
                  matrix:
                    id: [1, 2, 3]
                steps:
                  - run: echo shard
              object-axis:
                name: split ${{ matrix.pkg.remote }}
                runs-on: ubuntu-latest
                strategy:
                  matrix:
                    pkg:
                      - { local: 'packages/alpha', remote: 'alpha' }
                      - { local: 'packages/beta', remote: 'beta' }
                steps:
                  - run: echo split
              unnamed-singleton:
                runs-on: ubuntu-latest
                steps:
                  - run: echo singleton
              unnamed-matrix:
                runs-on: ubuntu-latest
                strategy:
                  matrix:
                    php: ['8.4', '8.5']
                    os: [ubuntu-latest]
                steps:
                  - run: echo matrix
              unnamed-object-matrix:
                runs-on: ubuntu-latest
                strategy:
                  matrix:
                    pkg:
                      - { local: 'packages/alpha', remote: 'alpha' }
                steps:
                  - run: echo object
              expression-name:
                name: Recover ${{ inputs.tag }}
                runs-on: ubuntu-latest
                steps:
                  - run: echo recover

            YAML;
    }

    private static function unresolvedMatrixWorkflow(): string
    {
        return <<<'YAML'
            name: Unresolved matrices
            on: workflow_dispatch
            jobs:
              prepare:
                runs-on: ubuntu-latest
                outputs:
                  matrix: ${{ steps.plan.outputs.matrix }}
                  packages: ${{ steps.plan.outputs.packages }}
                steps:
                  - id: plan
                    run: echo done
              whole-matrix:
                needs: prepare
                runs-on: ubuntu-latest
                strategy:
                  matrix: ${{ fromJSON(needs.prepare.outputs.matrix) }}
                steps:
                  - run: echo whole
              axis-expression:
                needs: prepare
                runs-on: ubuntu-latest
                strategy:
                  matrix:
                    package: ${{ fromJSON(needs.prepare.outputs.packages) }}
                steps:
                  - run: echo axis

            YAML;
    }

    private static function includeExcludeWorkflow(): string
    {
        return <<<'YAML'
            name: Matrix semantics
            on: push
            jobs:
              documented-include:
                runs-on: ubuntu-latest
                strategy:
                  matrix:
                    fruit: [apple, pear]
                    animal: [cat, dog]
                    include:
                      - color: green
                      - color: pink
                        animal: cat
                      - fruit: apple
                        shape: circle
                      - fruit: banana
                      - fruit: banana
                        animal: cat
                steps:
                  - run: echo include
              excluded:
                runs-on: ubuntu-latest
                strategy:
                  matrix:
                    os: [ubuntu-latest, windows-latest]
                    php: ['8.4', '8.5']
                    exclude:
                      - os: windows-latest
                        php: '8.4'
                steps:
                  - run: echo exclude

            YAML;
    }

    private static function dependencyWorkflow(): string
    {
        return <<<'YAML'
            name: Dependencies
            on:
              pull_request:
                types: [opened, synchronize]
            jobs:
              alpha:
                name: alpha
                if: github.event_name == 'pull_request'
                runs-on: ubuntu-latest
                steps:
                  - run: echo alpha
              beta:
                name: beta
                needs: alpha
                runs-on: ubuntu-latest
                steps:
                  - run: echo beta
              gate:
                name: gate
                needs: [beta, alpha]
                if: ${{ always() }}
                runs-on: ubuntu-latest
                steps:
                  - name: Require a successful beta
                    env:
                      BETA: ${{ needs.beta.result }}
                    run: test "$BETA" = success

            YAML;
    }

    private static function artifactProducerWorkflow(): string
    {
        return <<<'YAML'
            name: Artifacts
            on: push
            jobs:
              producer:
                runs-on: ubuntu-latest
                strategy:
                  matrix:
                    id: [1, 2]
                steps:
                  - uses: actions/upload-artifact@v4
                    with:
                      name: foo-${{ matrix.id }}
                      path: build/out
              consumer:
                needs: producer
                runs-on: ubuntu-latest
                steps:
                  - uses: actions/download-artifact@v4
                    with:
                      pattern: foo-*
                      merge-multiple: true

            YAML;
    }

    private static function crossRunConsumerWorkflow(): string
    {
        return <<<'YAML'
            name: Cross run
            on: workflow_dispatch
            jobs:
              collect:
                runs-on: ubuntu-latest
                steps:
                  - uses: actions/download-artifact@v4
                    with:
                      pattern: foo-*
                      run-id: 4242
                      github-token: ${{ github.token }}

            YAML;
    }

    private static function triggerWorkflow(): string
    {
        return <<<'YAML'
            name: Triggers
            on:
              push:
                branches:
                  - main
                tags:
                  - 'v*'
                paths:
                  - 'packages/**'
              pull_request:
                types: [opened, labeled]
                paths-ignore:
                  - 'docs/**'
              schedule:
                - cron: '0 3 * * *'
                - cron: '30 7 * * 1'
              workflow_dispatch:
                inputs:
                  zebra:
                    required: false
                    type: string
                    default: z
                  alpha:
                    required: true
                    type: number
              workflow_run:
                workflows: ['Triggers']
                types: [completed]
            jobs:
              noop:
                runs-on: ubuntu-latest
                steps:
                  - run: echo noop

            YAML;
    }

    private static function conditionWorkflow(): string
    {
        return <<<'YAML'
            name: Conditions
            on:
              pull_request:
                types: [labeled]
            jobs:
              labelled:
                if: github.event_name == 'pull_request' && github.event.label.name == 'auto-merge-when-green'
                runs-on: ubuntu-latest
                steps:
                  - run: echo labelled
              by-actor:
                if: ${{ github.actor == 'dependabot[bot]' }}
                runs-on: ubuntu-latest
                steps:
                  - run: echo actor
              gate:
                needs: [labelled, by-actor]
                if: always() && needs.labelled.result == 'success'
                runs-on: ubuntu-latest
                steps:
                  - run: echo gate
              not-cancelled-gate:
                needs: [labelled, by-actor]
                if: ${{ !cancelled() }}
                runs-on: ubuntu-latest
                steps:
                  - run: echo not-cancelled
              on-failure:
                needs: [labelled]
                if: failure()
                runs-on: ubuntu-latest
                steps:
                  - run: echo on-failure
              plain-dependent:
                needs: [labelled]
                runs-on: ubuntu-latest
                steps:
                  - run: echo plain

            YAML;
    }

    private static function shapeWorkflow(): string
    {
        return <<<'YAML'
            name: Shapes
            on: workflow_dispatch
            jobs:
              alpha:
                runs-on: ubuntu-latest
                steps:
                  - run: echo alpha
              duplicate-needs:
                needs: [alpha, alpha]
                runs-on: ubuntu-latest
                timeout-minutes: 12
                steps:
                  - run: echo duplicate
              no-matrix-strategy:
                runs-on: ubuntu-latest
                strategy:
                  fail-fast: false
                  max-parallel: 2
                steps:
                  - run: echo no-matrix
              expression-timeout:
                runs-on: ubuntu-latest
                timeout-minutes: ${{ fromJSON(inputs.budget) }}
                steps:
                  - run: echo timeout
              reusable:
                uses: ./.github/workflows/naming.yml

            YAML;
    }

    private static function spliceWorkflow(): string
    {
        return <<<'YAML'
            name: Splice
            on: push
            jobs:
              spliced:
                runs-on: ubuntu-latest
                steps:
                  - run: |
                      echo composer
                      # a comment sitting between two unrelated lines
                      install-something
                  - run: |
                      php vendor/bin/phpunit --no-coverage `
                        tests/Integration/NotACommand
              silent:
                runs-on: ubuntu-latest
                steps:
                  - run: |
                      echo npm
                      # another comment
                      run build

            YAML;
    }

    private static function roleWorkflow(): string
    {
        return <<<'YAML'
            name: Roles
            on: push
            permissions:
              contents: read
            jobs:
              setup-step:
                runs-on: ubuntu-latest
                outputs:
                  plan: ${{ steps.plan.outputs.plan }}
                steps:
                  - id: plan
                    run: php bin/build-phpunit-shards --shards=2
              execution-step:
                needs: setup-step
                runs-on: ubuntu-latest
                permissions:
                  packages: write
                  contents: read
                steps:
                  - run: php bin/check-composer-policy
              publication-step:
                runs-on: ubuntu-latest
                steps:
                  - env:
                      TOKEN: ${{ secrets.SPLIT_TOKEN }}
                    run: |
                      php bin/sync-internal-versions
                      git push origin HEAD
              orchestration-step:
                runs-on: ubuntu-latest
                steps:
                  - run: bin/enable-governed-auto-merge 42
              hosted-signals-step:
                runs-on: ubuntu-latest
                steps:
                  - run: gh pr view 42 --json state

            YAML;
    }

    private static function orderWorkflow(bool $reversed): string
    {
        $jobs = [
            "  alpha:\n    name: alpha\n    runs-on: ubuntu-latest\n    steps:\n      - run: echo alpha\n",
            "  beta:\n    name: beta\n    needs: alpha\n    runs-on: ubuntu-latest\n    steps:\n      - run: echo beta\n",
            "  gamma:\n    name: gamma\n    needs: [alpha, beta]\n    runs-on: ubuntu-latest\n    steps:\n      - run: echo gamma\n",
        ];

        return "name: Order\non: push\njobs:\n" . implode('', $reversed ? array_reverse($jobs) : $jobs);
    }
}
