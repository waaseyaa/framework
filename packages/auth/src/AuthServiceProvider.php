<?php

declare(strict_types=1);

namespace Waaseyaa\Auth;

use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasMiddlewareInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class AuthServiceProvider extends ServiceProvider implements HasMiddlewareInterface
{
    public function register(): void
    {
        $this->singleton(AuthManager::class, fn() => new AuthManager(
            $this->resolve(UserInternalFieldReaderInterface::class),
        ));

        $this->singleton(RateLimiterInterface::class, function () {
            $db = $this->resolve(\Waaseyaa\Database\DatabaseInterface::class);
            return new DatabaseRateLimiter($db);
        });

        $authConfig = $this->config['auth'] ?? [];
        $appEnv = $this->config['app_env'] ?? ($_ENV['APP_ENV'] ?? 'production');

        $this->singleton(Config\AuthConfig::class, fn() => Config\AuthConfig::fromArray($authConfig, $appEnv));

        $this->singleton(Token\AuthTokenRepositoryInterface::class, function () use ($authConfig) {
            $secret = $authConfig['token_secret'] ?? ($this->config['app_secret'] ?? null);

            // The reset/verify token HMAC key must never be the literal 'change-me'
            // published in source (forgeable token hashes), nor empty. Real deployments
            // MUST configure a secret: fail loudly there. In dev/test, synthesise an
            // ephemeral random secret so boot still works — never a known string.
            // (Ephemeral means tokens do not survive a reboot; set AUTH_TOKEN_SECRET to
            // persist them. Under the boot-per-request dev runtime this is per-request.)
            if (!is_string($secret) || $secret === '' || $secret === 'change-me') {
                if (self::requiresConfiguredTokenSecret($this->resolveRuntimeEnvironment())) {
                    throw new \RuntimeException(
                        'Auth token secret is not configured. Set a real "auth.token_secret" (or "app_secret") '
                        . 'to a non-empty value; the insecure placeholder "change-me" is rejected. '
                        . 'Reset/verify token HMAC keys must not fall back to a value published in source.',
                    );
                }

                $secret = bin2hex(random_bytes(32));
            }

            $db = $this->resolve(\Waaseyaa\Database\DatabaseInterface::class);
            $repo = new Token\AuthTokenRepository($db, $secret);
            $repo->ensureSchema();
            return $repo;
        });

        $this->singleton(TwoFactorManager::class, fn() => new TwoFactorManager());

        $this->singleton(TwoFactorService::class, fn() => new TwoFactorService(
            $this->resolve(TwoFactorManager::class),
            $this->resolve(EntityTypeManager::class),
            $this->resolve(UserInternalFieldReaderInterface::class),
        ));
    }

    /**
     * @return list<HttpMiddlewareInterface>
     */
    public function middleware(EntityTypeManager $entityTypeManager): array
    {
        return [];
    }

    /**
     * Resolve the runtime environment the same way {@see \Waaseyaa\Foundation\Kernel\AbstractKernel}
     * does: the canonical source is the config `environment` key (what the kernel and the
     * integration test harness set), with `app_env` / `APP_ENV` as fallbacks. Reading only
     * `app_env` here would misclassify a `local` kernel as production and break boot.
     */
    private function resolveRuntimeEnvironment(): string
    {
        $env = $this->config['environment'] ?? $this->config['app_env'] ?? null;
        if (is_string($env) && $env !== '') {
            return $env;
        }

        $fromEnv = getenv('APP_ENV');

        return is_string($fromEnv) && $fromEnv !== '' ? $fromEnv : 'production';
    }

    /**
     * Whether the runtime environment must have an explicitly configured token
     * secret (real deployments) rather than tolerating an ephemeral dev secret.
     *
     * Dev/test environments (local, dev, development, testing) may synthesise an
     * ephemeral random secret so a misconfigured-but-non-production app still boots;
     * every other environment (production, staging, or anything unrecognised) must
     * fail loudly so a real deployment never silently ships without a stable secret.
     */
    private static function requiresConfiguredTokenSecret(string $appEnv): bool
    {
        return !in_array(strtolower($appEnv), ['local', 'dev', 'development', 'testing'], true);
    }
}
