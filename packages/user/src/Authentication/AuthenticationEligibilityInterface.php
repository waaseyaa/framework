<?php

declare(strict_types=1);

namespace Waaseyaa\User\Authentication;

use Waaseyaa\User\User;

/** One policy boundary for deciding whether a User may authenticate. @api */
interface AuthenticationEligibilityInterface
{
    public function allows(User $user, AuthenticationStage $stage): bool;
}
