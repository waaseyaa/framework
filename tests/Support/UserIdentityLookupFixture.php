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
        foreach ([['name', '='], ['mail', '='], ['mail', 'CASE_INSENSITIVE_EQUALS']] as [$field, $operator]) {
            $query = $repository->getQuery()->accessCheck(false);
            $query = $operator === '='
                ? $query->condition($field, $login)
                : $query->condition($field, $login, $operator);
            $ids = $query->condition('status', 1)->range(0, $operator === '=' ? 1 : 2)->execute();
            if ($operator !== '=' && count($ids) !== 1) {
                return null;
            }
            if ($ids !== []) {
                return $repository->find((string) reset($ids));
            }
        }
        return null;
    }

    public function mailExists(EntityRepositoryInterface $repository, string $mail): bool
    {
        return $repository->getQuery()
            ->accessCheck(false)
            ->condition('mail', $mail, 'CASE_INSENSITIVE_EQUALS')
            ->range(0, 1)
            ->execute() !== [];
    }
}
