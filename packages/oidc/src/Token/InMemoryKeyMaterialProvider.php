<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Token;

use RuntimeException;
use Waaseyaa\Oidc\Keys\OidcKeyLoaderInterface;
use Waaseyaa\Oidc\Keys\SigningKey;

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
        foreach ($this->keyLoader->loadSigningKeys() as $key) {
            if ($key->algorithm === 'RS256' && $key->privateKeyPem !== null) {
                return $key;
            }
        }

        throw new RuntimeException('No RS256 signing key with private key material is available.');
    }

    public function allActive(): array
    {
        return $this->keyLoader->loadSigningKeys();
    }
}
