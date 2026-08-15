<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Manifest;

/** CFG-04 trust-policy implementation of the CFG-03 verifier seam. @api */
final readonly class ConfigManifestEd25519Verifier implements ConfigManifestSignatureVerifierInterface
{
    public function __construct(private ConfigManifestTrustPolicy $trustPolicy) {}

    public function verify(
        string $trustKeyReference,
        string $algorithm,
        string $preAuthenticatedBytes,
        string $signature,
    ): bool {
        if ($algorithm !== SignedConfigManifestEnvelope::ALGORITHM_V1
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        $publicKey = $this->trustPolicy->trustedPublicKey($trustKeyReference, $algorithm);
        if ($publicKey === null) {
            return false;
        }

        return sodium_crypto_sign_verify_detached(
            $signature,
            $preAuthenticatedBytes,
            $publicKey,
        );
    }
}
