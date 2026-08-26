<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Extension;

/** Credential-free successful-auth redirect context. @api */
final readonly class AuthRedirectContext
{
    public function __construct(
        public string $action,
        public ?string $userId,
    ) {
        if (!in_array($action, ['registration', 'login', 'logout', 'verification'], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown auth redirect action "%s".', $action));
        }
    }
}
