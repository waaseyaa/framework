<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security;

/** @implements SecretConsumerInterface<ApplicationMasterAuthenticationTag> @internal */
final readonly class ApplicationMasterLookupOperation implements SecretConsumerInterface
{
    use ApplicationMasterSymmetricOperation;

    public function __construct(
        private int $masterVersion,
        private string $purposeId,
        #[\SensitiveParameter]
        private string $lookupValue,
    ) {}

    public static function id(): string
    {
        return 'waaseyaa.application.master-lookup.v1';
    }

    public static function secretClass(): SecretClass
    {
        return SecretClass::ApplicationMaster;
    }

    public static function purpose(): string
    {
        return ApplicationMasterKeyring::MASTER_PURPOSE;
    }

    public function consume(#[\SensitiveParameter] string $bytes, string $version): ApplicationMasterAuthenticationTag
    {
        $key = self::deriveKey($bytes, $this->masterVersion, $this->purposeId);
        try {
            $digest = hash_hmac('sha256', $this->lookupValue, $key, true);
        } finally {
            sodium_memzero($key);
        }

        return ApplicationMasterAuthenticationTag::seal($this->masterVersion, $this->purposeId, $digest);
    }

    /** @return array{operation: string, master_version: int, purpose: string} */
    public function __debugInfo(): array
    {
        return [
            'operation' => '[NON_EXPORTING]',
            'master_version' => $this->masterVersion,
            'purpose' => $this->purposeId,
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Application-master lookup operations cannot be serialized.');
    }
}
