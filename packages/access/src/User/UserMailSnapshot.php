<?php

declare(strict_types=1);

namespace Waaseyaa\Access\User;

/** Exact mail-delivery identity obtained through audited authority. @api */
final readonly class UserMailSnapshot
{
    public function __construct(public string $name, public string $mail) {}
}
