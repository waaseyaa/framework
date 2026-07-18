<?php

declare(strict_types=1);

namespace Waaseyaa\Access\User;

/** Exact two-factor verification inputs obtained through audited authority. @api */
final readonly class UserTwoFactorSnapshot
{
    /** @param list<string> $recoveryCodeHashes */
    public function __construct(
        public string $mail,
        public ?string $secret,
        public array $recoveryCodeHashes,
        public ?int $lastUsedStep,
    ) {}
}
