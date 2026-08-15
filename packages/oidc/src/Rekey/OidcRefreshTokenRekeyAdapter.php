<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Rekey;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Security\ApplicationSecret;

/** Joint refresh-token ciphertext and lookup-index application-master owner. @api */
final class OidcRefreshTokenRekeyAdapter extends AbstractOidcTokenRekeyAdapter
{
    public const string ID = 'oidc-refresh-token-v1';

    public function __construct(
        DatabaseInterface $database,
        #[\SensitiveParameter]
        ?string $legacyEncryptionKey = null,
        #[\SensitiveParameter]
        ?string $legacyLookupKey = null,
    ) {
        parent::__construct(
            $database,
            $legacyEncryptionKey,
            $legacyLookupKey,
            self::ID,
            'oidc_refresh_token',
            ApplicationSecret::PURPOSE_OIDC_REFRESH_TOKEN_ENCRYPTION,
            ApplicationSecret::PURPOSE_OIDC_REFRESH_TOKEN_LOOKUP,
        );
    }
}
