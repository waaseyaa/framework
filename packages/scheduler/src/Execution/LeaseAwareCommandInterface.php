<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Execution;

/** A scheduled command that cooperatively renews and fences its effects. */
interface LeaseAwareCommandInterface
{
    public function run(LeaseExecutionContext $context): void;
}
