<?php

declare(strict_types=1);

namespace Waaseyaa\Auth;

use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Auth\Authentication\AuthenticationEligibilityException;
use Waaseyaa\Auth\Password\LegacyPasswordUpgrade;
use Waaseyaa\User\Authentication\AuthenticationEligibilityInterface;
use Waaseyaa\User\Authentication\AuthenticationStage;
use Waaseyaa\User\Session\AuthenticatedSession;
use Waaseyaa\User\User;

/**
 * @api
 */
final class AuthManager
{
    public function __construct(
        private readonly UserInternalFieldReaderInterface $internalFields,
        private readonly AuthenticationEligibilityInterface $eligibility,
        private readonly ?LegacyPasswordUpgrade $passwords = null,
    ) {}

    /**
     * Validate user credentials.
     *
     * Delegates to {@see LegacyPasswordUpgrade} when one is wired, so this path
     * and the HTTP login controller make the identical decision — including the
     * one-time upgrade of a migrated credential (#2544). Without one, behaviour
     * is exactly the historical native-only check.
     */
    public function authenticate(User $user, string $password): bool
    {
        $credentials = $this->internalFields->credentials($user);
        if ($this->passwords !== null) {
            return $this->passwords->verify($user, $password, $credentials);
        }

        if (!$credentials->active) {
            return false;
        }

        return $credentials->passwordHash !== '' && password_verify($password, $credentials->passwordHash);
    }

    /**
     * Log in a user by setting the session.
     *
     * Regenerates the session ID to prevent session fixation attacks.
     */
    public function login(User $user): void
    {
        if (!$this->eligibility->allows($user, AuthenticationStage::DirectLogin)) {
            throw new AuthenticationEligibilityException('The user is not eligible to authenticate.');
        }

        // Prevent session fixation: regenerate ID and destroy old session.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        AuthenticatedSession::issue($user, $this->internalFields->sessionIdentity($user)->generation);
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
        return isset($_SESSION[AuthenticatedSession::USER_ID_KEY])
            && $_SESSION[AuthenticatedSession::USER_ID_KEY] !== ''
            && isset($_SESSION[AuthenticatedSession::GENERATION_KEY])
            && is_int($_SESSION[AuthenticatedSession::GENERATION_KEY]);
    }
}
