<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider;

final class OAuthStateManager
{
    private const SESSION_KEY = 'oauth_state';

    public function generate(SessionInterface $session): string
    {
        $state = bin2hex(random_bytes(32));
        $session->set(self::SESSION_KEY, $state);
        return $state;
    }

    public function validate(SessionInterface $session, string $state): bool
    {
        $expected = $session->get(self::SESSION_KEY);
        $session->remove(self::SESSION_KEY);

        if ($expected === null || !is_string($expected)) {
            return false;
        }

        return hash_equals($expected, $state);
    }
}
