<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Manifest;

use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretConsumerInterface;

/** @implements SecretConsumerInterface<string> @internal */
final readonly class Ed25519ManifestSigningOperation implements SecretConsumerInterface
{
    public const string PURPOSE = 'waaseyaa.config.manifest-signing.v1';

    public function __construct(
        private string $preAuthenticatedBytes,
        private string $expectedPublicKey,
    ) {}

    public static function id(): string
    {
        return 'waaseyaa.config.manifest-ed25519-signing.v1';
    }

    public static function secretClass(): SecretClass
    {
        return SecretClass::TokenSigningPrivateKey;
    }

    public static function purpose(): string
    {
        return self::PURPOSE;
    }

    public function consume(#[\SensitiveParameter] string $bytes, string $version): string
    {
        if (strlen($bytes) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('CFG-04 Ed25519 signing custody has invalid key material.');
        }
        $resolvedPublicKey = sodium_crypto_sign_publickey_from_secretkey($bytes);
        if (!hash_equals($this->expectedPublicKey, $resolvedPublicKey)) {
            throw new \RuntimeException('CFG-04 Ed25519 signing custody does not match its trust reference.');
        }

        return sodium_crypto_sign_detached($this->preAuthenticatedBytes, $bytes);
    }
}
