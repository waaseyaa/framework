<?php

declare(strict_types=1);

namespace Waaseyaa\User;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

/**
 * Policy-checked active-state changes with durable session revocation.
 *
 * Consumer consent/group changes belong in the caller's transaction. This
 * service never issues credentials or widens the generic field write policy.
 * @api
 */
final readonly class UserAccountLifecycle
{
    public function __construct(
        private EntityAccessHandler $access,
        private UserInternalFieldReaderInterface $internalFields,
    ) {}

    public function setActive(EntityRepositoryInterface $repository, User $target, bool $active, AuthorizationPrincipalInterface $actor): void
    {
        if (!$actor->isAuthenticated() || (string) $actor->id() === (string) $target->id()
            || !$this->access->check($target, 'update', $actor)->isAllowed()
            || !$this->access->check($target, 'change_active_status', $actor)->isAllowed()) {
            throw new \RuntimeException('Account status change is not authorized.');
        }
        if ($this->internalFields->verification($target)->active === $active) {
            return;
        }
        if (!$active) {
            $generation = $this->internalFields->sessionIdentity($target)->generation;
            if ($generation === PHP_INT_MAX) {
                throw new \RuntimeException('Session revocation generation is exhausted.');
            }
            $target->setSessionGeneration($generation + 1);
        }
        $target->setActive($active);
        $repository->save($target);
    }
}
