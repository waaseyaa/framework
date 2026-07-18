<?php

declare(strict_types=1);

namespace Waaseyaa\Access\User;

use Waaseyaa\Entity\EntityInterface;

/** Narrow reason-specific authority for framework User internals. @api */
interface UserInternalFieldReaderInterface
{
    public function credentials(EntityInterface $user): UserCredentialSnapshot;

    public function twoFactor(EntityInterface $user): UserTwoFactorSnapshot;

    public function mailDelivery(EntityInterface $user): UserMailSnapshot;

    public function verification(EntityInterface $user): UserVerificationSnapshot;

    public function sessionIdentity(EntityInterface $user): UserSessionSnapshot;

    public function maintenanceAuthorization(EntityInterface $user): UserAuthorizationSnapshot;
}
