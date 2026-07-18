<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Support;

use Waaseyaa\Access\User\UserIdentityLookupInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

/** Test-only repository adapter for unit tests below the audit integration boundary. */
final class UserIdentityLookupFixture implements UserIdentityLookupInterface
{
    public function findActiveByLogin(EntityRepositoryInterface $repository, string $login): ?EntityInterface
    {
        foreach (['name', 'mail'] as $field) {
            $ids = $repository->getQuery()->accessCheck(false)->condition($field, $login)->condition('status', 1)->range(0, 1)->execute();
            if ($ids !== []) {
                return $repository->find((string) reset($ids));
            }
        }
        return null;
    }

    public function mailExists(EntityRepositoryInterface $repository, string $mail): bool
    {
        return $repository->getQuery()->accessCheck(false)->condition('mail', $mail)->range(0, 1)->execute() !== [];
    }
}
