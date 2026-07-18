<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Security;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Agent\Entity\AgentRun;

/** Exact account-context AgentRun projection; callers cannot select fields. @api */
interface AgentRunAccountProjectionReaderInterface
{
    public function read(AgentRun $run, AccountInterface $account): AgentRunAccountProjection;
}
