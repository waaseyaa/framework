<?php

declare(strict_types=1);

namespace Waaseyaa\Access\User;

/** Exact credential-verification inputs obtained through audited authority. @api */
final readonly class UserCredentialSnapshot
{
    public function __construct(public bool $active, public string $passwordHash) {}
}
