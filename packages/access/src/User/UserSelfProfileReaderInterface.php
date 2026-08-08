<?php

declare(strict_types=1);

namespace Waaseyaa\Access\User;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Entity\EntityInterface;

/** Purpose-scoped authority for an authenticated account to read its own profile identity. @api */
interface UserSelfProfileReaderInterface
{
    public function read(EntityInterface $user, AuthorizationPrincipalInterface $actor): UserSelfProfileSnapshot;
}
