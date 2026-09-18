<?php

declare(strict_types=1);

namespace Waaseyaa\User;

/** Canonical registered-role membership and its flattened permission union. @api */
final readonly class RegisteredRoleAssignment
{
    /** @param list<string> $roles @param list<string> $permissions */
    public function __construct(
        public array $roles,
        public array $permissions,
    ) {}
}
