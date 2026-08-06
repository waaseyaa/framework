<?php

declare(strict_types=1);

namespace Waaseyaa\Testing\Factory;

use Waaseyaa\Access\AuthorizationPrincipal;

/**
 * Builds real immutable authorization principals with explicit test defaults.
 *
 * @api
 */
final class AuthorizationPrincipalFactory
{
    private const string DEFAULT_CLAIMS_GENERATION = 'test-claims-v1';

    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    public static function authenticated(
        int|string $id = 1,
        array $roles = [],
        array $permissions = [],
        string $claimsGeneration = self::DEFAULT_CLAIMS_GENERATION,
        ?string $tenantId = null,
        ?string $communityId = null,
    ): AuthorizationPrincipal {
        return new AuthorizationPrincipal(
            accountId: $id,
            authenticated: true,
            roles: $roles,
            permissions: $permissions,
            claimsGeneration: $claimsGeneration,
            tenantId: $tenantId,
            communityId: $communityId,
        );
    }

    public static function anonymous(
        int|string $id = 0,
        string $claimsGeneration = self::DEFAULT_CLAIMS_GENERATION,
        ?string $tenantId = null,
        ?string $communityId = null,
    ): AuthorizationPrincipal {
        return new AuthorizationPrincipal(
            accountId: $id,
            authenticated: false,
            roles: [],
            permissions: [],
            claimsGeneration: $claimsGeneration,
            tenantId: $tenantId,
            communityId: $communityId,
        );
    }
}
