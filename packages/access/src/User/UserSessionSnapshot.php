<?php

declare(strict_types=1);

namespace Waaseyaa\Access\User;

/** Exact authenticated-session response identity obtained under audit. @api */
final readonly class UserSessionSnapshot
{
    /** @param list<string> $roles */
    public function __construct(
        public string $name,
        public string $mail,
        public array $roles,
        public int $generation = 0,
    ) {}
}
