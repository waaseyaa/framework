<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Token;

use Waaseyaa\Oidc\Keys\OidcKeyLoaderInterface;
use Waaseyaa\Oidc\Keys\SigningKey;
use Waaseyaa\Oidc\Keys\SigningKeySignerInterface;

/**
 * File-backed KeyMaterialProvider — delegates to the existing OidcKeyLoaderInterface.
 *
 * Used by WP01 until WP04 installs RealKeyMaterialProvider (DB-backed).
 * Not suitable for production key rotation — it loads PEM files from disk.
 */
final readonly class InMemoryKeyMaterialProvider implements KeyMaterialProviderInterface
{
    public function __construct(private OidcKeyLoaderInterface $keyLoader) {}

    public function currentKey(): SigningKey
    {
        return $this->currentSigner()->key();
    }

    public function currentSigner(): SigningKeySignerInterface
    {
        return $this->keyLoader->loadCurrentSigner();
    }

    public function allActive(): array
    {
        return $this->keyLoader->loadSigningKeys();
    }
}
