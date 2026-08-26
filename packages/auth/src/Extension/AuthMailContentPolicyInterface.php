<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Extension;

use Waaseyaa\User\AuthMailPresentation;

/** Chooses presentation only; recipients and token URLs remain Framework-owned. @api */
interface AuthMailContentPolicyInterface
{
    public function presentation(AuthMailContext $context): AuthMailPresentation;
}
