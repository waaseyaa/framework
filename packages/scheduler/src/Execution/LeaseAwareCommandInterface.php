<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Execution;

/** A scheduled command that cooperatively renews and fences its effects. @api */
interface LeaseAwareCommandInterface
{
    public function run(LeaseExecutionContext $context): void;
}
