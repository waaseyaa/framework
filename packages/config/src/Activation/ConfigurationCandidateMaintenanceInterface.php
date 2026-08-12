<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Activation;

/** Safe lifecycle maintenance for uncommitted configuration candidates. @api */
interface ConfigurationCandidateMaintenanceInterface
{
    public function supersedeStagedCandidates(ConfigurationCandidateSweepRequest $request): int;
}
