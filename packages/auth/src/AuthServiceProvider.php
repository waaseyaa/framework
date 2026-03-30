<?php

declare(strict_types=1);

namespace Waaseyaa\Auth;

use Waaseyaa\Auth\Config;
use Waaseyaa\Auth\Token;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(AuthManager::class, fn() => new AuthManager());

        $this->singleton(RateLimiter::class, fn() => new RateLimiter());

        $authConfig = $this->config['auth'] ?? [];
        $appEnv = $this->config['app_env'] ?? ($_ENV['APP_ENV'] ?? 'production');

        $this->singleton(Config\AuthConfig::class, fn() => Config\AuthConfig::fromArray($authConfig, $appEnv));

        $this->singleton(Token\AuthTokenRepositoryInterface::class, function () use ($authConfig) {
            $secret = $authConfig['token_secret'] ?? ($this->config['app_secret'] ?? 'change-me');
            $db = $this->resolve(\Waaseyaa\DatabaseLegacy\DatabaseInterface::class);
            $repo = new Token\AuthTokenRepository($db, $secret);
            $repo->ensureSchema();
            return $repo;
        });

        $this->singleton(TwoFactorManager::class, fn() => new TwoFactorManager());
    }

    /**
     * @return list<HttpMiddlewareInterface>
     */
    public function middleware(EntityTypeManager $entityTypeManager): array
    {
        return [];
    }
}
