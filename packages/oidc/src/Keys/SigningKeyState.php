<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Keys;

/** Exact CFG-04 signing and verification authority states. */
enum SigningKeyState: string
{
    case StagedVerifyOnly = 'staged-verify-only';
    case ActiveSignAndVerify = 'active-sign-and-verify';
    case RetiredVerifyOnly = 'retired-verify-only';
    case Revoked = 'revoked';

    public function canVerify(): bool
    {
        return $this !== self::Revoked;
    }

    public function canSign(): bool
    {
        return $this === self::ActiveSignAndVerify;
    }
}
