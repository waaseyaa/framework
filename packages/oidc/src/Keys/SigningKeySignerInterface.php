<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Keys;

/** Non-exporting authority for the active OIDC signing key. @api */
interface SigningKeySignerInterface
{
    public function key(): SigningKey;

    /** Return raw RS256 signature bytes for the supplied signing input. */
    public function sign(string $signingInput): string;
}
