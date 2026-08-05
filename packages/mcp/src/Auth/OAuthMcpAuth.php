<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Auth;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;

/** OAuth 2.1 resource-server authentication for an MCP write tier. */
final readonly class OAuthMcpAuth implements WriteTierAuthInterface, ScopedMcpAuthInterface
{
    public function __construct(
        private OAuthAccessTokenValidatorInterface $validator,
        private OAuthProtectedResourceMetadataConfig $resource,
        private ?LoggerInterface $logger = null,
    ) {}

    public function authenticate(?string $authorizationHeader): ?AuthorizationPrincipalInterface
    {
        return $this->authenticateWithScopes($authorizationHeader)?->account;
    }

    public function authenticateWithScopes(?string $authorizationHeader): ?ScopedPrincipal
    {
        if ($authorizationHeader === null || !\str_starts_with(\strtolower($authorizationHeader), 'bearer ')) {
            return null;
        }
        $token = \trim(\substr($authorizationHeader, 7));
        if ($token === '') {
            return null;
        }

        try {
            $principal = $this->validator->validate($token, $this->resource->resource);
        } catch (\Throwable $e) {
            $this->logger?->error('MCP OAuth access-token validation failed.', [
                'exception_class' => $e::class,
            ]);

            return null;
        }

        return $principal !== null && $principal->scopes !== [] ? $principal : null;
    }
}
