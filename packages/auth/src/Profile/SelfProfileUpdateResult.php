<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Profile;

/** A committed save; mail delivery is a separate, explicit outcome. @api */
final readonly class SelfProfileUpdateResult
{
    public function __construct(
        public bool $emailChanged,
        public bool $verificationRequired,
        public bool $verificationSent,
        public bool $completionEffectsFailed = false,
    ) {}
}
