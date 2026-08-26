<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Extension;

/** Token-free input to auth mail presentation policy. @api */
final readonly class AuthMailContext
{
    public function __construct(
        public string $kind,
        public ?string $userId,
    ) {
        if (!in_array($kind, ['password_reset', 'email_verification', 'welcome'], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown auth mail kind "%s".', $kind));
        }
    }
}
