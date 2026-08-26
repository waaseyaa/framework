<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Extension;

/** Validates and persists an application-owned profile linked by user id. @api */
interface RegistrationProfileHandlerInterface
{
    /** @param array<string, mixed> $profile */
    public function validate(array $profile): ValidatedRegistrationProfile;

    public function store(RegisteredUserReference $user, ValidatedRegistrationProfile $profile): void;
}
