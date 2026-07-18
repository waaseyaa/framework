<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Support;

use Waaseyaa\Access\AuthorizationPrincipal;

/** Test-only immutable principal builder for request and policy boundaries. */
final class AuthorizationPrincipalFactory
{
    /** @param array{uid?: int|string, permissions?: list<string>, roles?: list<string>} $values */
    public static function fromValues(array $values): AuthorizationPrincipal
    {
        return self::authenticated(
            permissions: $values['permissions'] ?? [],
            roles: $values['roles'] ?? ['authenticated'],
            accountId: $values['uid'] ?? 1,
        );
    }

    /**
     * @param list<string> $permissions
     * @param list<string> $roles
     */
    public static function authenticated(
        array $permissions = [],
        array $roles = ['authenticated'],
        int|string $accountId = 1,
    ): AuthorizationPrincipal {
        return new AuthorizationPrincipal(
            accountId: $accountId,
            authenticated: true,
            roles: $roles,
            permissions: $permissions,
            claimsGeneration: 'integration-test-principal',
        );
    }
}
