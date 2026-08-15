<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security;

/** Shared domain-separated derivation for guarded application-master operations. @internal */
trait ApplicationMasterSymmetricOperation
{
    private const string HKDF_SALT = 'waaseyaa.application-master.hkdf.v1';
    private const string HKDF_INFO_PREFIX = 'waaseyaa.application-master.envelope-key.v1/';

    private static function deriveKey(
        #[\SensitiveParameter]
        string $master,
        int $masterVersion,
        string $purpose,
    ): string {
        if (strlen($master) !== 32) {
            throw new \RuntimeException('Application-master custody requires exactly 32 bytes.');
        }

        return hash_hkdf(
            'sha256',
            $master,
            32,
            self::HKDF_INFO_PREFIX . 'v' . $masterVersion . '/' . $purpose,
            self::HKDF_SALT,
        );
    }
}
