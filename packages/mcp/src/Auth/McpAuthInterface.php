<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Auth;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

interface McpAuthInterface
{
    /**
     * Authenticate from the Authorization header value.
     *
     * @param string|null $authorizationHeader The raw Authorization header value.
     * @return AuthorizationPrincipalInterface|null The authenticated principal, or null if auth fails.
     */
    public function authenticate(?string $authorizationHeader): ?AuthorizationPrincipalInterface;
}
