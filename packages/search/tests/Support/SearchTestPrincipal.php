<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Support;

use Waaseyaa\Access\AuthorizationPrincipal;

final class SearchTestPrincipal
{
    public static function create(int|string $id = 1): AuthorizationPrincipal
    {
        return new AuthorizationPrincipal(
            accountId: $id,
            authenticated: true,
            roles: ['authenticated'],
            permissions: [],
            claimsGeneration: 'search-test-claims',
        );
    }
}
