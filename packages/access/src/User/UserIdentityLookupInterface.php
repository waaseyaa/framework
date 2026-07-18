<?php

declare(strict_types=1);

namespace Waaseyaa\Access\User;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

/** Audited non-public login-identity query boundary. @api */
interface UserIdentityLookupInterface
{
    public function findActiveByLogin(EntityRepositoryInterface $repository, string $login): ?EntityInterface;

    public function mailExists(EntityRepositoryInterface $repository, string $mail): bool;
}
