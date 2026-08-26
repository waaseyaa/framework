<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Extension;

/** Minimal identity released to application profile/lifecycle policy. @api */
final readonly class RegisteredUserReference
{
    public function __construct(
        public string $userId,
        public string $name,
        public bool $approvalRequired,
    ) {}
}
