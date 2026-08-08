<?php

declare(strict_types=1);

namespace Waaseyaa\Access\User;

/** Exact self-profile identity obtained through account-bound audited authority. @api */
final readonly class UserSelfProfileSnapshot
{
    public function __construct(public string $name, public string $mail) {}
}
