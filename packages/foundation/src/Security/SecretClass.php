<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security;

/**
 * Closed classes for governed secret references and guarded values.
 *
 * @api
 */
enum SecretClass: string
{
    case ApplicationMaster = 'application-master';
    case DerivedPurposeKey = 'derived-purpose-key';
    case TokenSigningPrivateKey = 'token-signing-private-key';
    case IntegrationCredential = 'integration-credential';
    case ProviderCredential = 'provider-credential';
    case PasswordVerifier = 'password-verifier';
    case BuildPublishCredential = 'build-publish-credential';
    case BackupRecoveryCredential = 'backup-recovery-credential';
}
