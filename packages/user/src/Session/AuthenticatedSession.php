<?php

declare(strict_types=1);

namespace Waaseyaa\User\Session;

use Waaseyaa\User\User;

/** Canonical keys and mutations for a password-authenticated PHP session. */
final class AuthenticatedSession
{
    public const string USER_ID_KEY = 'waaseyaa_uid';
    public const string GENERATION_KEY = 'waaseyaa_session_generation';

    public static function issue(User $user, int $generation): void
    {
        if ($generation < 0) {
            throw new \InvalidArgumentException('Session generation cannot be negative.');
        }

        $_SESSION[self::USER_ID_KEY] = $user->id();
        $_SESSION[self::GENERATION_KEY] = $generation;
    }

    public static function clearIdentity(): void
    {
        unset($_SESSION[self::USER_ID_KEY], $_SESSION[self::GENERATION_KEY]);
    }
}
