<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Migrate;

use Waaseyaa\Foundation\Migration\Dag\MigrationGraph;
use Waaseyaa\Foundation\Migration\Dag\MigrationKind;
use Waaseyaa\Foundation\Migration\Dag\MigrationNode;
use Waaseyaa\Foundation\Migration\Executor\OpPrecondition;
use Waaseyaa\Foundation\Migration\Executor\OpPreconditionResolver;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Schema\Compiler\Sqlite\SqliteCompiler;
use Waaseyaa\Foundation\Schema\Compiler\Validation\PlanPolicy;
use Waaseyaa\Foundation\Schema\Diff\CompositeDiff;
use Waaseyaa\Foundation\Schema\Migration\MigrationInterfaceV2;

/**
 * Pure builder for the dry-run plan: walks the same {@see MigrationGraph}
 * the {@see \Waaseyaa\Foundation\Migration\Migrator} would walk, and for
 * each pending v2 node compiles the {@see \Waaseyaa\Foundation\Schema\Diff\CompositeDiff}
 * into a {@see \Waaseyaa\Foundation\Schema\Compiler\CompiledMigrationPlan}
 * — but never executes the SQL and never writes the ledger.
 *
 * Legacy nodes appear in the plan but carry no `steps` (their `up()`
 * body is imperative; we cannot pre-execute it without running it).
 *
 * This class is the pure logic; {@see DryRunFormatter} renders the
 * result in human or JSON form.
 */
final readonly class DryRunPlanner
{
    public function __construct(
        private MigrationRepository $repository,
        private SqliteCompiler $compiler,
        private PlanPolicy $policy = new PlanPolicy(),
        private ?OpPreconditionResolver $preconditions = null,
    ) {}

    /**
     * @param array<string, array<string, Migration>> $legacy
     * @param list<MigrationInterfaceV2>              $v2
     */
    public function plan(array $legacy, array $v2): DryRunResult
    {
        $nodes = [];
        foreach ($legacy as $package => $packageMigrations) {
            foreach ($packageMigrations as $name => $migration) {
                $nodes[] = MigrationNode::fromLegacy($name, $package, $migration);
            }
        }
        foreach ($v2 as $v2Migration) {
            $nodes[] = MigrationNode::fromV2($v2Migration);
        }

        $ordered = MigrationGraph::build($nodes)->topologicalOrder();

        $result = [];
        foreach ($ordered as $node) {
            $alreadyApplied = $this->repository->hasRun($node->id);
            $result[] = new DryRunNode(
                id: $node->id,
                package: $node->package,
                kind: $node->kind->value,
                dependencies: $node->dependencies,
                steps: $this->compileStepsFor($node, $alreadyApplied),
                alreadyApplied: $alreadyApplied,
            );
        }

        return new DryRunResult($result);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function compileStepsFor(MigrationNode $node, bool $alreadyApplied): array
    {
        if ($alreadyApplied || $node->kind !== MigrationKind::V2 || $node->v2 === null) {
            return [];
        }

        $root = $node->v2->plan()->root;

        // Compile the whole plan first so a policy or capability refusal surfaces
        // in dry-run exactly as it would during apply.
        $compiled = $this->compiler->compile($root, $this->policy);

        // #2701: report what would actually run. Without this the plan advertises
        // SQL for operations the live schema already satisfies, which is untrue
        // for one of the two lifecycles the same catalogue has to serve. Note the
        // check is a snapshot: dry-run does not execute, so it cannot show an
        // operation becoming outstanding because an earlier one ran.
        if ($this->preconditions !== null) {
            $outstanding = array_values(array_filter(
                $root->ops,
                fn(object $op): bool => $this->preconditions->resolve($op) === OpPrecondition::NeedsApply,
            ));

            if ($outstanding === []) {
                return [];
            }
            if (count($outstanding) !== count($root->ops)) {
                $compiled = $this->compiler->compile(new CompositeDiff($outstanding), $this->policy);
            }
        }

        $steps = [];
        foreach ($compiled->steps as $step) {
            $steps[] = $step->toCanonical();
        }

        return $steps;
    }
}
