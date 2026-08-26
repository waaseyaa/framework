<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Extension;

/** Selects response redirect metadata after a successful core operation. @api */
interface AuthRedirectPolicyInterface
{
    public function redirect(AuthRedirectContext $context): AuthRedirect;
}
