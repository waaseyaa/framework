<?php

declare(strict_types=1);

namespace Waaseyaa\Access\User;

/** Exact maintenance authorization inputs obtained through audited authority. @api */
final readonly class UserAuthorizationSnapshot
{
    /** @param list<string> $roles @param list<string> $permissions */
    public function __construct(public array $roles, public array $permissions) {}
}
