<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Extension;

/** Selects registry-known roles for a newly registered account. @api */
interface InitialRolePolicyInterface
{
    /** @return list<string> */
    public function roles(RegisteredUserReference $user): array;
}
