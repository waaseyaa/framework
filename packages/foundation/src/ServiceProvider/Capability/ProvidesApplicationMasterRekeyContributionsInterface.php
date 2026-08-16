<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\ServiceProvider\Capability;

use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyContribution;

/** Provider capability for active application-master rekey owners. @api */
interface ProvidesApplicationMasterRekeyContributionsInterface
{
    /** @return iterable<ApplicationMasterRekeyContribution> */
    public function applicationMasterRekeyContributions(): iterable;
}
