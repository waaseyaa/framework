<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security;

/** @implements SecretConsumerInterface<ApplicationMasterEnvelope> @internal */
final readonly class ApplicationMasterSealOperation implements SecretConsumerInterface
{
    use ApplicationMasterSymmetricOperation;

    public function __construct(
        private int $masterVersion,
        private string $purposeId,
        private string $recordIdentity,
        private int $schemaVersion,
        #[\SensitiveParameter]
        private string $plaintext,
    ) {}

    public static function id(): string
    {
        return 'waaseyaa.application.master-seal.v1';
    }

    public static function secretClass(): SecretClass
    {
        return SecretClass::ApplicationMaster;
    }

    public static function purpose(): string
    {
        return ApplicationMasterKeyring::MASTER_PURPOSE;
    }

    public function consume(#[\SensitiveParameter] string $bytes, string $version): ApplicationMasterEnvelope
    {
        $key = self::deriveKey($bytes, $this->masterVersion, $this->purposeId);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        try {
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $this->plaintext,
                ApplicationMasterEnvelope::encodeAssociatedData(
                    $this->masterVersion,
                    $this->purposeId,
                    $this->recordIdentity,
                    $this->schemaVersion,
                ),
                $nonce,
                $key,
            );
        } finally {
            sodium_memzero($key);
        }

        return ApplicationMasterEnvelope::seal(
            $this->masterVersion,
            $this->purposeId,
            $this->recordIdentity,
            $this->schemaVersion,
            $nonce,
            $ciphertext,
        );
    }

    /** @return array{operation: string, master_version: int, purpose: string, record_identity: string, schema_version: int} */
    public function __debugInfo(): array
    {
        return [
            'operation' => '[NON_EXPORTING]',
            'master_version' => $this->masterVersion,
            'purpose' => $this->purposeId,
            'record_identity' => $this->recordIdentity,
            'schema_version' => $this->schemaVersion,
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Application-master seal operations cannot be serialized.');
    }

}
