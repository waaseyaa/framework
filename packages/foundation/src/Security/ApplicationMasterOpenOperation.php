<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security;

/** @implements SecretConsumerInterface<string> @internal */
final readonly class ApplicationMasterOpenOperation implements SecretConsumerInterface
{
    use ApplicationMasterSymmetricOperation;

    public function __construct(private ApplicationMasterEnvelope $envelope) {}

    public static function id(): string
    {
        return 'waaseyaa.application.master-open.v1';
    }

    public static function secretClass(): SecretClass
    {
        return SecretClass::ApplicationMaster;
    }

    public static function purpose(): string
    {
        return ApplicationMasterKeyring::MASTER_PURPOSE;
    }

    public function consume(#[\SensitiveParameter] string $bytes, string $version): string
    {
        $key = self::deriveKey($bytes, $this->envelope->masterVersion, $this->envelope->purpose);
        try {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $this->envelope->ciphertextBytes(),
                $this->envelope->associatedData(),
                $this->envelope->nonceBytes(),
                $key,
            );
        } finally {
            sodium_memzero($key);
        }
        if ($plaintext === false) {
            throw new SecretConsumerRefusalException(SecretConsumptionCode::AuthenticationFailed);
        }

        return $plaintext;
    }
}
