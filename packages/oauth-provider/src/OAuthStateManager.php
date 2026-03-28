<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider;

final class OAuthStateManager
{
    public function __construct(
        private readonly int $ttlSeconds = 600,
    ) {}

    public function generate(SessionInterface $session): string
    {
        $state = bin2hex(random_bytes(32));
        $session->set('_oauth_state', $state);
        $session->set('_oauth_state_ts', time());

        return $state;
    }

    public function validate(SessionInterface $session, string $state): bool
    {
        $storedState = $session->get('_oauth_state');
        $storedTimestamp = $session->get('_oauth_state_ts');

        if ($storedState === null || $storedTimestamp === null) {
            return false;
        }

        $session->remove('_oauth_state');
        $session->remove('_oauth_state_ts');

        if (!\is_string($storedState) || !hash_equals($storedState, $state)) {
            return false;
        }

        if (!\is_int($storedTimestamp) || (time() - $storedTimestamp) > $this->ttlSeconds) {
            return false;
        }

        return true;
    }
}
