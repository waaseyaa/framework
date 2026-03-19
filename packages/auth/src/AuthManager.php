<?php

declare(strict_types=1);

namespace Waaseyaa\Auth;

use Waaseyaa\User\User;

final class AuthManager
{
    /**
     * Validate user credentials.
     */
    public function authenticate(User $user, string $password): bool
    {
        if (!$user->isActive()) {
            return false;
        }

        return $user->checkPassword($password);
    }

    /**
     * Log in a user by setting the session.
     */
    public function login(User $user): void
    {
        $_SESSION['waaseyaa_uid'] = $user->id();
    }

    /**
     * Log out by clearing the session user.
     */
    public function logout(): void
    {
        unset($_SESSION['waaseyaa_uid']);
    }

    /**
     * Check if the current session has an authenticated user.
     */
    public function isAuthenticated(): bool
    {
        return isset($_SESSION['waaseyaa_uid']) && $_SESSION['waaseyaa_uid'] !== '';
    }
}
