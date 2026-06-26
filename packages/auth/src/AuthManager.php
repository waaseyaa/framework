<?php

declare(strict_types=1);

namespace Waaseyaa\Auth;

use Waaseyaa\User\User;

/**
 * @api
 */
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
     *
     * Regenerates the session ID to prevent session fixation attacks.
     */
    public function login(User $user): void
    {
        // Prevent session fixation: regenerate ID and destroy old session.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION['waaseyaa_uid'] = $user->id();
    }

    /**
     * Log out by clearing and destroying the session.
     *
     * Clears all session data, then rotates + destroys the underlying session so
     * the pre-logout session id cannot be reused — symmetric with login()'s
     * session_regenerate_id(true). Guarded on PHP_SESSION_ACTIVE because the
     * session is started by the bootstrap (not here) and is inactive in CLI/tests.
     */
    public function logout(): void
    {
        // Clear all session data (not just the uid).
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            session_destroy();
        }
    }

    /**
     * Check if the current session has an authenticated user.
     */
    public function isAuthenticated(): bool
    {
        return isset($_SESSION['waaseyaa_uid']) && $_SESSION['waaseyaa_uid'] !== '';
    }
}
