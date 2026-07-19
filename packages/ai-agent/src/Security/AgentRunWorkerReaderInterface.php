<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Security;

use Waaseyaa\AI\Agent\Entity\AgentRun;

/** Exact worker authority; callers cannot select fields. @api */
interface AgentRunWorkerReaderInterface
{
    public function read(AgentRun $run): AgentRunWorkerFields;
}
