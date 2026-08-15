<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Keys;

interface OidcKeyLoaderInterface
{
    /**
     * @return list<SigningKey>
     */
    public function loadSigningKeys(): array;

    /** Return a non-exporting signer for the configured private key. */
    public function loadCurrentSigner(): SigningKeySignerInterface;
}
