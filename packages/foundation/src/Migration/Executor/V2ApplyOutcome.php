<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Migration\Executor;

use Waaseyaa\Foundation\Schema\Compiler\CompiledMigrationPlan;

/**
 * What one v2 node's apply actually did.
 *
 * `compiled` is always the **full** compiled plan, even when some operations
 * were already satisfied and their SQL was never issued. That keeps
 * `diff_hash` a stable function of the authored diff, so verification compares
 * the same value on a fresh site and an upgraded one.
 *
 * @see docs/change-records/FW-2701.md — ledger interaction
 */
final readonly class V2ApplyOutcome
{
    /**
     * @param list<string> $materializedTables entity base tables created by C1
     */
    public function __construct(
        public CompiledMigrationPlan $compiled,
        public ApplyMode $mode,
        public array $materializedTables = [],
    ) {}

    public function diffHash(): string
    {
        return $this->compiled->diffHash();
    }
}
