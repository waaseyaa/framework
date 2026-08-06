<?php

declare(strict_types=1);

namespace Waaseyaa\Testing\Traits;

/**
 * Trait providing authentication helpers for tests.
 *
 * Tracks the "current user" during a test so that tests can
 * simulate acting as a specific user without depending on
 * a full auth subsystem.
 *
 * @api
 * @deprecated since 0.1.0-alpha.288. Use AuthorizationPrincipalFactory and
 *   pass the resulting real principal through the production boundary.
 */
trait InteractsWithAuth
{
    /**
     * The currently acting user, or null for anonymous/guest.
     *
     * @var array<string, mixed>|null
     */
    private ?array $currentUser = null;

    /**
     * Set the current user for the test.
     *
     * Accepts a user values array (as returned by EntityFactory::create()).
     * The array should at minimum contain an 'id' or 'uid' key.
     *
     * @param array<string, mixed> $user User values array.
     * @return static
     */
    protected function actingAs(array $user): static
    {
        $this->currentUser = $user;

        return $this;
    }

    /**
     * Clear the current user, returning to guest/anonymous state.
     *
     * @return static
     */
    protected function actingAsGuest(): static
    {
        $this->currentUser = null;

        return $this;
    }

    /**
     * Assert that a user is currently set (authenticated).
     */
    protected function assertAuthenticated(string $message = ''): void
    {
        $this->assertNotNull(
            $this->currentUser,
            $message ?: 'Expected an authenticated user, but no user is set.',
        );
    }

    /**
     * Assert that no user is currently set (guest/anonymous).
     */
    protected function assertGuest(string $message = ''): void
    {
        $this->assertNull(
            $this->currentUser,
            $message ?: 'Expected a guest/anonymous user, but a user is set.',
        );
    }

    /**
     * Get the current user values array, or null if acting as guest.
     *
     * @return array<string, mixed>|null
     */
    protected function getCurrentUser(): ?array
    {
        return $this->currentUser;
    }

    /**
     * Reset authentication state.
     */
    private function resetAuth(): void
    {
        $this->currentUser = null;
    }
}
