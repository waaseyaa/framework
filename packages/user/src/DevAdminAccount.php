<?php

declare(strict_types=1);

namespace Waaseyaa\User;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/**
 * Dev-only admin account with all permissions.
 *
 * Used as a fallback when running under a local development server and no user
 * is identified in the session. It is only ever constructed by
 * HttpKernel::shouldUseDevFallbackAccount(), which itself requires a development
 * environment (APP_ENV) plus the explicit `auth.dev_fallback_account` opt-in —
 * those are the real gates. The SAPI check below is a secondary safety belt
 * limiting construction to dev-capable runtimes: PHP's built-in server
 * (cli-server), the CLI (cli), and FrankenPHP (the worker runtime used both
 * locally and in production — production stays safe via the env/opt-in gates).
 *
 * MUST NOT be used in production.
 */
final class DevAdminAccount implements AuthorizationPrincipalInterface
{
    /** SAPIs under which a dev fallback account may be constructed. */
    private const array ALLOWED_SAPIS = ['cli-server', 'cli', 'frankenphp'];

    public function __construct()
    {
        if (!in_array(PHP_SAPI, self::ALLOWED_SAPIS, true)) {
            throw new \LogicException(
                'DevAdminAccount must only be used with a development server '
                . '(cli-server, cli, or frankenphp SAPI). Current SAPI: ' . PHP_SAPI,
            );
        }
    }

    public function id(): int
    {
        return PHP_INT_MAX;
    }

    public function hasPermission(string $permission): bool
    {
        return true;
    }

    /** @return string[] */
    public function getRoles(): array
    {
        return ['administrator'];
    }

    public function isAuthenticated(): bool
    {
        return true;
    }

    public function claimsGeneration(): string
    {
        return 'dev-admin';
    }

    public function tenantId(): ?string
    {
        return null;
    }

    public function communityId(): ?string
    {
        return null;
    }
}
