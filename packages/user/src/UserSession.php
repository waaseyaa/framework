<?php

declare(strict_types=1);

namespace Aurora\User;

use Aurora\Access\AccountInterface;

/**
 * Wraps an AccountInterface as the "current user" for the request.
 *
 * Defaults to an AnonymousUser when no account is provided.
 */
final class UserSession
{
    private AccountInterface $account;

    public function __construct(?AccountInterface $account = null)
    {
        $this->account = $account ?? new AnonymousUser();
    }

    public function getAccount(): AccountInterface
    {
        return $this->account;
    }

    public function setAccount(AccountInterface $account): void
    {
        $this->account = $account;
    }

    public function isAuthenticated(): bool
    {
        return $this->account->isAuthenticated();
    }
}
