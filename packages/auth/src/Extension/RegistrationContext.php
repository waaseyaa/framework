<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Extension;

/** Credential-free input to application registration policy. @api */
final readonly class RegistrationContext
{
    public function __construct(
        public string $name,
        public string $mail,
        public string $configuredMode,
    ) {}
}
