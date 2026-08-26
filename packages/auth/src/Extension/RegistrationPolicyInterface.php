<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Extension;

/** Application registration availability and approval policy. @api */
interface RegistrationPolicyInterface
{
    public function decide(RegistrationContext $context): RegistrationDecision;
}
