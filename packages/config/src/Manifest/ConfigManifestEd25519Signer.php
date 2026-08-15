<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Manifest;

use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretHandle;
use Waaseyaa\Foundation\Security\SecretReference;
use Waaseyaa\Foundation\Security\SecretResolverRegistry;

/** CFG-04 guarded Ed25519 implementation of the CFG-03 signer seam. @api */
final class ConfigManifestEd25519Signer implements ConfigManifestSignerInterface
{
    public const string PACKAGE = 'waaseyaa/config';

    private readonly SecretHandle $signingKey;

    public function __construct(
        SecretResolverRegistry $registry,
        SecretReference $reference,
        private readonly string $trustKeyReference,
        private readonly string $expectedPublicKey,
    ) {
        if ($reference->secretClass() !== SecretClass::TokenSigningPrivateKey
            || $reference->purpose() !== Ed25519ManifestSigningOperation::PURPOSE) {
            throw new \InvalidArgumentException('CFG-04 manifest signer reference class or purpose is invalid.');
        }
        if (preg_match('~^[a-zA-Z0-9][a-zA-Z0-9._:/-]*$~D', $trustKeyReference) !== 1) {
            throw new \InvalidArgumentException('CFG-04 manifest trust-key reference is invalid.');
        }
        if (strlen($expectedPublicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new \InvalidArgumentException('CFG-04 manifest signer requires an exact Ed25519 public key.');
        }
        $this->signingKey = SecretHandle::fromReference(
            $registry,
            $reference,
            self::PACKAGE,
            [Ed25519ManifestSigningOperation::class],
        );
    }

    public function algorithm(): string
    {
        return SignedConfigManifestEnvelope::ALGORITHM_V1;
    }

    public function trustKeyReference(): string
    {
        return $this->trustKeyReference;
    }

    public function sign(string $preAuthenticatedBytes): string
    {
        return $this->signingKey->consume(
            new Ed25519ManifestSigningOperation($preAuthenticatedBytes, $this->expectedPublicKey),
        );
    }

    /** @return array{signer: string, algorithm: string, trust_key_reference: string} */
    public function __debugInfo(): array
    {
        return [
            'signer' => '[NON_EXPORTING]',
            'algorithm' => $this->algorithm(),
            'trust_key_reference' => $this->trustKeyReference,
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Configuration manifest signer handles cannot be serialized.');
    }

    private function __clone() {}
}
