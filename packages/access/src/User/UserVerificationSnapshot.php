<?php

declare(strict_types=1);

namespace Waaseyaa\Access\User;

/** Exact email-verification inputs obtained through audited authority. @api */
final readonly class UserVerificationSnapshot
{
    public function __construct(
        public string $mail,
        public bool $emailVerified,
        public bool $active,
    ) {}
}
