<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Support;

use Waaseyaa\Access\User\UserAuthorizationSnapshot;
use Waaseyaa\Access\User\UserCredentialSnapshot;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Access\User\UserMailSnapshot;
use Waaseyaa\Access\User\UserSessionSnapshot;
use Waaseyaa\Access\User\UserTwoFactorSnapshot;
use Waaseyaa\Access\User\UserVerificationSnapshot;
use Waaseyaa\Entity\EntityInterface;

/** Test-only adapter for unit tests below the audit integration boundary. */
final class UserInternalFieldReaderFixture implements UserInternalFieldReaderInterface
{
    public function credentials(EntityInterface $user): UserCredentialSnapshot
    {
        return new UserCredentialSnapshot((bool) $user->get('status'), (string) ($user->get('pass') ?? ''));
    }

    public function twoFactor(EntityInterface $user): UserTwoFactorSnapshot
    {
        $hashes = $user->get('two_factor_recovery_codes_hash');
        return new UserTwoFactorSnapshot(
            (string) ($user->get('mail') ?? ''),
            is_string($user->get('two_factor_secret')) ? $user->get('two_factor_secret') : null,
            is_array($hashes) ? array_values(array_filter($hashes, 'is_string')) : [],
            is_int($user->get('two_factor_last_used_step')) ? $user->get('two_factor_last_used_step') : null,
        );
    }

    public function mailDelivery(EntityInterface $user): UserMailSnapshot
    {
        return new UserMailSnapshot((string) ($user->get('name') ?? ''), (string) ($user->get('mail') ?? ''));
    }

    public function verification(EntityInterface $user): UserVerificationSnapshot
    {
        return new UserVerificationSnapshot((string) ($user->get('mail') ?? ''), (bool) $user->get('email_verified'));
    }

    public function sessionIdentity(EntityInterface $user): UserSessionSnapshot
    {
        $roles = $user->get('roles');
        return new UserSessionSnapshot(
            (string) ($user->get('name') ?? ''),
            (string) ($user->get('mail') ?? ''),
            is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [],
        );
    }

    public function maintenanceAuthorization(EntityInterface $user): UserAuthorizationSnapshot
    {
        $roles = $user->get('roles');
        $permissions = $user->get('permissions');
        return new UserAuthorizationSnapshot(
            is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [],
            is_array($permissions) ? array_values(array_filter($permissions, 'is_string')) : [],
        );
    }
}
