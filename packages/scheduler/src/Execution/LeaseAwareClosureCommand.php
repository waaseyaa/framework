<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Execution;

/** Stable command wrapper for dependency-injected scheduled work. */
final readonly class LeaseAwareClosureCommand implements LeaseAwareCommandInterface
{
    /** @param \Closure(LeaseExecutionContext): mixed $callback */
    public function __construct(private \Closure $callback) {}

    public function run(LeaseExecutionContext $context): void
    {
        ($this->callback)($context);
    }
}
