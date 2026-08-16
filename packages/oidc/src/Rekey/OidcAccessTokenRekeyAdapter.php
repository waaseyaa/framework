<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Rekey;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Security\ApplicationSecret;

/** Joint access-token ciphertext and lookup-index application-master owner. @api */
final class OidcAccessTokenRekeyAdapter extends AbstractOidcTokenRekeyAdapter
{
    public const string ID = 'oidc-access-token-v1';

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
            'oidc_access_token',
            ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_ENCRYPTION,
            ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_LOOKUP,
        );
    }
}
