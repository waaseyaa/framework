<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Extension;

/** Fail-closed application disposition layered after core registration checks. @api */
final readonly class RegistrationDecision
{
    private function __construct(
        public bool $allowed,
        public bool $requiresApproval,
    ) {}

    public static function allow(): self
    {
        return new self(true, false);
    }

    public static function requireApproval(): self
    {
        return new self(true, true);
    }

    public static function deny(): self
    {
        return new self(false, false);
    }
}
