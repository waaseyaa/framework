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
 * 3. **Classify (C2/C3/C4).** Each operation is checked against live state.
 *    Exactly-satisfied operations are dropped from the executed set; an
 *    incompatible one throws.
 * 4. **Execute** only the outstanding operations, recompiled as their own
 *    composite so no partially-applicable step is issued.
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
        // 1. Compile the authored plan in full. Policy refusals fire here.
        $compiled = $this->compiler->compile($plan->root, $policy);

        // 2. Targeted materialization of absent entity base tables.
        $materialized = [];
        if ($this->materializer !== null && !$plan->root->isEmpty()) {
            $materialized = $this->materializer->materialize(PlanTargets::tables($plan->root));
        }

        // 3. Classify each authored op against live state.
        $resolver = new OpPreconditionResolver($this->connection);
        $outstanding = [];
        foreach ($plan->root->ops as $op) {
            if ($resolver->resolve($op) === OpPrecondition::NeedsApply) {
                $outstanding[] = $op;
            }
        }

        // An empty authored plan is a successful no-op apply (§15 Q3), not an
        // "already satisfied" node: there was never anything to satisfy.
        if ($plan->root->isEmpty()) {
            return new V2ApplyOutcome($compiled, ApplyMode::Applied, $materialized);
        }

        if ($outstanding === []) {
            return new V2ApplyOutcome($compiled, ApplyMode::AlreadySatisfied, $materialized);
        }

        // 4. Execute only what is outstanding.
        $toRun = count($outstanding) === count($plan->root->ops)
            ? $compiled
            : $this->compiler->compile(new CompositeDiff($outstanding), $policy);

        foreach ($toRun->steps as $step) {
            $this->connection->executeStatement($step->sql());
        }

        return new V2ApplyOutcome($compiled, ApplyMode::Applied, $materialized);
    }
}
