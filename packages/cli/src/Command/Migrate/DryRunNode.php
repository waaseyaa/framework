<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Migrate;

/**
 * One node entry in a {@see DryRunResult}.
 *
 * `steps` is empty for:
 * - Legacy nodes (their `up()` body is imperative — we cannot pre-compile).
 * - Already-applied nodes (would be a no-op at apply time, no SQL to preview).
 *
 * For v2 pending nodes, `steps` carries the canonical-JSON dictionary of
 * each {@see \Waaseyaa\Foundation\Schema\Compiler\CompiledStep} the
 * compiler would emit.
 *
 * `stateDependent` marks a node whose preview could not be resolved exactly,
 * because an earlier operation in the same plan changes the state a later one
 * is judged against and dry-run executes nothing. Such a node's operations are
 * preserved in `steps` rather than filtered out: showing work that may prove
 * unnecessary is honest, silently omitting work that will run is not.
 */
final readonly class DryRunNode
{
    /**
     * @param list<string>                    $dependencies
     * @param list<array<string, mixed>>      $steps
     */
    public function __construct(
        public string $id,
        public string $package,
        public string $kind,
        public array $dependencies,
        public array $steps,
        public bool $alreadyApplied,
        public bool $stateDependent = false,
    ) {}
}
