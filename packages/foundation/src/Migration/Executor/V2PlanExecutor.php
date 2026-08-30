<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Migration\Executor;

use Doctrine\DBAL\Connection;
use Waaseyaa\Foundation\Migration\EntityTableMaterializerInterface;
use Waaseyaa\Foundation\Schema\Compiler\Sqlite\SqliteCompiler;
use Waaseyaa\Foundation\Schema\Compiler\Validation\PlanPolicy;
use Waaseyaa\Foundation\Schema\Diff\CompositeDiff;
use Waaseyaa\Foundation\Schema\Diff\PlanTargets;
use Waaseyaa\Foundation\Schema\Migration\MigrationPlan;

/**
 * Executes a v2 {@see MigrationPlan} against the live connection.
 *
 * The sequence is load-bearing and runs entirely inside the Migrator's per-node
 * transaction:
 *
 * 1. **Compile the full plan first.** Policy gates — destructive operations,
 *    SQLite-unsupported shapes — must fire before anything touches the database,
 *    and the resulting {@see CompiledMigrationPlan} is what supplies `diff_hash`
 *    regardless of what is executed below.
 * 2. **Materialize (C1).** Absent entity base tables the plan targets are created
 *    by the canonical entity-schema materializer, which the composition site
 *    injects. A table the materializer does not own stays absent, so the
 *    migration fails closed on a real SQL error rather than on a guess.
 * 3. **Classify and execute (C2/C3/C4), one operation at a time, in authored
 *    order.** Each operation is checked against the state its predecessors left
 *    behind, then executed if outstanding. Exactly-satisfied operations issue no
 *    SQL; an incompatible one throws. Classifying the whole plan against a single
 *    pre-execution snapshot would be wrong: an operation another operation in the
 *    same composite makes necessary would be dropped as already satisfied while
 *    the full `diff_hash` was still recorded, leaving verification reporting a
 *    match for a database missing part of the plan.
 *
 * When every operation is already satisfied, no SQL is issued and the node is
 * recorded with {@see ApplyMode::AlreadySatisfied}.
 *
 * **Transaction boundary:** unchanged. The executor remains transaction-agnostic;
 * the Migrator wraps compile, materialize, execute and the ledger write together
 * so an interruption leaves the node wholly applied or wholly absent.
 *
 * @see docs/change-records/FW-2701.md
 */
final readonly class V2PlanExecutor
{
    public function __construct(
        private Connection $connection,
        private SqliteCompiler $compiler,
        private ?EntityTableMaterializerInterface $materializer = null,
    ) {}

    public function execute(MigrationPlan $plan, PlanPolicy $policy): V2ApplyOutcome
    {
        // 1. Compile the authored plan in full. Policy refusals, illegal op
        //    ordering and unsupported shapes all fire here, before anything
        //    touches the database, and this is the plan that fixes diff_hash.
        $compiled = $this->compiler->compile($plan->root, $policy);

        // 2. Targeted materialization of absent prerequisite entity base tables.
        $materialized = [];
        if ($this->materializer !== null && !$plan->root->isEmpty()) {
            $materialized = $this->materializer->materialize(PlanTargets::tables($plan->root));
        }

        // An empty authored plan is a successful no-op apply (§15 Q3), not an
        // "already satisfied" node: there was never anything to satisfy.
        if ($plan->root->isEmpty()) {
            return new V2ApplyOutcome($compiled, ApplyMode::Applied, $materialized);
        }

        // 3 + 4. Classify and execute ONE operation at a time, in authored
        //        order. Classifying the whole plan against a single
        //        pre-execution snapshot would judge each op against a database
        //        its predecessors have not yet changed — an op that a preceding
        //        op makes necessary would be dropped as already satisfied, and
        //        the full diff_hash would still be recorded, so verification
        //        would report a match for a database missing part of the plan.
        $resolver = new OpPreconditionResolver($this->connection);
        $executed = 0;
        foreach ($plan->root->ops as $op) {
            if ($resolver->resolve($op) === OpPrecondition::AlreadySatisfied) {
                continue;
            }
            foreach ($this->compiler->compile(new CompositeDiff([$op]), $policy)->steps as $step) {
                $this->connection->executeStatement($step->sql());
            }
            ++$executed;
        }

        return new V2ApplyOutcome(
            $compiled,
            $executed === 0 ? ApplyMode::AlreadySatisfied : ApplyMode::Applied,
            $materialized,
        );
    }
}
