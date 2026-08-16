<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security;

/** @implements SecretConsumerInterface<bool> @internal */
final readonly class ApplicationMasterVerifyOperation implements SecretConsumerInterface
{
    use ApplicationMasterSymmetricOperation;

    public function __construct(
        private ApplicationMasterAuthenticationTag $tag,
        #[\SensitiveParameter]
        private string $message,
    ) {}

    public static function id(): string
    {
        return 'waaseyaa.application.master-verify.v1';
    }

    public static function secretClass(): SecretClass
    {
        return SecretClass::ApplicationMaster;
    }

    public static function purpose(): string
    {
        return ApplicationMasterKeyring::MASTER_PURPOSE;
    }

    public function consume(#[\SensitiveParameter] string $bytes, string $version): bool
    {
        $key = self::deriveKey($bytes, $this->tag->masterVersion, $this->tag->purpose);
        try {
            $expected = hash_hmac('sha256', $this->message, $key, true);

            return hash_equals($expected, $this->tag->digestBytes());
        } finally {
            sodium_memzero($key);
        }
    }

    /** @return array{operation: string, master_version: int, purpose: string} */
    public function __debugInfo(): array
    {
        return [
            'operation' => '[NON_EXPORTING]',
            'master_version' => $this->tag->masterVersion,
            'purpose' => $this->tag->purpose,
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Application-master verify operations cannot be serialized.');
    }
}
