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
use Waaseyaa\Foundation\Schema\Compiler\CompiledMigrationPlan;
use Waaseyaa\Foundation\Schema\Compiler\Sqlite\SqliteCompiler;
use Waaseyaa\Foundation\Schema\Compiler\Validation\PlanPolicy;
use Waaseyaa\Foundation\Schema\Diff\CompositeDiff;
use Waaseyaa\Foundation\Schema\Diff\PlanTargets;
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

        // Uncertainty accumulates across the WHOLE ordered graph, not within one
        // migration. An earlier migration file changes what a later one meets,
        // and dry-run executes nothing, so it cannot observe that change.
        /** @var list<string> $uncertainTables */
        $uncertainTables = [];
        $everythingUncertain = false;

        $result = [];
        foreach ($ordered as $node) {
            $alreadyApplied = $this->repository->hasRun($node->id);

            // One analysis produces the preview steps and the uncertainty flag
            // together, so the two can never disagree.
            [$steps, $stateDependent] = $this->analyse(
                $node,
                $alreadyApplied,
                $uncertainTables,
                $everythingUncertain,
            );

            $result[] = new DryRunNode(
                id: $node->id,
                package: $node->package,
                kind: $node->kind->value,
                dependencies: $node->dependencies,
                steps: $steps,
                alreadyApplied: $alreadyApplied,
                stateDependent: $stateDependent,
            );

            if ($alreadyApplied) {
                // Its effects are already in the live database, so it changes
                // nothing about what a later node will meet.
                continue;
            }
            if ($node->kind !== MigrationKind::V2 || $node->v2 === null) {
                // A legacy migration's up() body is imperative and opaque to
                // preview. Once one is pending, no later node can be resolved
                // against the live snapshot.
                $everythingUncertain = true;
                continue;
            }
            foreach (PlanTargets::affectedTables($node->v2->plan()->root) as $table) {
                if (!in_array($table, $uncertainTables, true)) {
                    $uncertainTables[] = $table;
                }
            }
        }

        return new DryRunResult($result);
    }

    /**
     * Produce this node's preview steps and its uncertainty flag in one pass.
     *
     * Mirrors the executor's per-operation walk — same ordering, same target
     * model, same precondition rule — with one deliberate divergence: the
     * executor resolves against ground truth because it has already applied the
     * preceding operations, and preview cannot. Where state is unknown an
     * operation is reported as outstanding and is never refused, because a
     * preceding operation may be exactly what changes the state being judged.
     *
     * @param list<string> $uncertainTables
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private function analyse(
        MigrationNode $node,
        bool $alreadyApplied,
        array $uncertainTables,
        bool $everythingUncertain,
    ): array {
        if ($alreadyApplied || $node->kind !== MigrationKind::V2 || $node->v2 === null) {
            return [[], false];
        }

        $root = $node->v2->plan()->root;

        // Compile the whole plan first so a policy or capability refusal surfaces
        // in dry-run exactly as it would during apply.
        $compiled = $this->compiler->compile($root, $this->policy);

        if ($this->preconditions === null) {
            return [self::stepDictionaries($compiled), $everythingUncertain];
        }

        $touched = $uncertainTables;
        $outstanding = [];
        $stateDependent = $everythingUncertain;

        foreach ($root->ops as $op) {
            $related = [...PlanTargets::prerequisitesForOp($op), ...PlanTargets::affectedByOp($op)];
            $uncertain = $everythingUncertain || array_intersect($related, $touched) !== [];

            if ($uncertain) {
                $outstanding[] = $op;
                $stateDependent = true;
            } elseif ($this->preconditions->resolve($op) === OpPrecondition::NeedsApply) {
                $outstanding[] = $op;
            }

            foreach (PlanTargets::affectedByOp($op) as $table) {
                if ($table !== '' && !in_array($table, $touched, true)) {
                    $touched[] = $table;
                }
            }
        }

        if ($outstanding === []) {
            return [[], $stateDependent];
        }
        if (count($outstanding) !== count($root->ops)) {
            $compiled = $this->compiler->compile(new CompositeDiff($outstanding), $this->policy);
        }

        return [self::stepDictionaries($compiled), $stateDependent];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function stepDictionaries(CompiledMigrationPlan $compiled): array
    {
        $steps = [];
        foreach ($compiled->steps as $step) {
            $steps[] = $step->toCanonical();
        }

        return $steps;
    }
}
