<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Extension;

/** Public-safe application-profile validation errors. @api */
final class RegistrationProfileValidationException extends \RuntimeException
{
    /** @param array<string, string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Application registration profile is invalid.');
    }
}
