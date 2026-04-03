<?php

declare(strict_types=1);

namespace Waaseyaa\Auth;

use Waaseyaa\Auth\Controller\ForgotPasswordController;
use Waaseyaa\Auth\Controller\LoginController;
use Waaseyaa\Auth\Controller\LogoutController;
use Waaseyaa\Auth\Controller\MeController;
use Waaseyaa\Auth\Controller\RegisterController;
use Waaseyaa\Auth\Controller\ResendVerificationController;
use Waaseyaa\Auth\Controller\ResetPasswordController;
use Waaseyaa\Auth\Controller\VerifyEmailController;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\User\AuthMailer;

final class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(AuthManager::class, fn() => new AuthManager());

        $this->singleton(RateLimiterInterface::class, function () {
            $db = $this->resolve(\Waaseyaa\Database\DatabaseInterface::class);
            return new DatabaseRateLimiter($db);
        });

        $authConfig = $this->config['auth'] ?? [];
        $appEnv = $this->config['app_env'] ?? ($_ENV['APP_ENV'] ?? 'production');

        $this->singleton(Config\AuthConfig::class, fn() => Config\AuthConfig::fromArray($authConfig, $appEnv));

        $this->singleton(Token\AuthTokenRepositoryInterface::class, function () use ($authConfig) {
            $secret = $authConfig['token_secret'] ?? ($this->config['app_secret'] ?? 'change-me');
            $db = $this->resolve(\Waaseyaa\Database\DatabaseInterface::class);
            $repo = new Token\AuthTokenRepository($db, $secret);
            $repo->ensureSchema();
            return $repo;
        });

        $this->singleton(TwoFactorManager::class, fn() => new TwoFactorManager());
    }

    public function routes(WaaseyaaRouter $router, ?EntityTypeManager $entityTypeManager = null): void
    {
        $authConfig = $this->resolve(Config\AuthConfig::class);
        $tokenRepo = $this->resolve(Token\AuthTokenRepositoryInterface::class);
        $rateLimiter = $this->resolve(RateLimiterInterface::class);
        $authMailer = $this->resolve(AuthMailer::class);

        $router->addRoute(
            'api.auth.register',
            RouteBuilder::create('/api/auth/register')
                ->controller(new RegisterController(
                    config: $authConfig,
                    entityTypeManager: $entityTypeManager ?? $this->resolve(EntityTypeManager::class),
                    tokenRepo: $tokenRepo,
                    authMailer: $authMailer,
                    rateLimiter: $rateLimiter,
                ))
                ->allowAll()
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'api.auth.forgot_password',
            RouteBuilder::create('/api/auth/forgot-password')
                ->controller(new ForgotPasswordController(
                    config: $authConfig,
                    entityTypeManager: $entityTypeManager ?? $this->resolve(EntityTypeManager::class),
                    tokenRepo: $tokenRepo,
                    authMailer: $authMailer,
                    rateLimiter: $rateLimiter,
                ))
                ->allowAll()
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'api.auth.reset_password',
            RouteBuilder::create('/api/auth/reset-password')
                ->controller(new ResetPasswordController(
                    entityTypeManager: $entityTypeManager ?? $this->resolve(EntityTypeManager::class),
                    tokenRepo: $tokenRepo,
                ))
                ->allowAll()
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'api.auth.verify_email',
            RouteBuilder::create('/api/auth/verify-email')
                ->controller(new VerifyEmailController(
                    entityTypeManager: $entityTypeManager ?? $this->resolve(EntityTypeManager::class),
                    tokenRepo: $tokenRepo,
                ))
                ->allowAll()
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'api.auth.resend_verification',
            RouteBuilder::create('/api/auth/resend-verification')
                ->controller(new ResendVerificationController(
                    config: $authConfig,
                    entityTypeManager: $entityTypeManager ?? $this->resolve(EntityTypeManager::class),
                    tokenRepo: $tokenRepo,
                    authMailer: $authMailer,
                    rateLimiter: $rateLimiter,
                ))
                ->requireAuthentication()
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'api.auth.login',
            RouteBuilder::create('/api/auth/login')
                ->controller(new LoginController(
                    entityTypeManager: $entityTypeManager ?? $this->resolve(EntityTypeManager::class),
                    rateLimiter: $rateLimiter,
                ))
                ->allowAll()
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'api.auth.logout',
            RouteBuilder::create('/api/auth/logout')
                ->controller(new LogoutController())
                ->allowAll()
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'api.user.me',
            RouteBuilder::create('/api/user/me')
                ->controller(new MeController(
                    entityTypeManager: $entityTypeManager ?? $this->resolve(EntityTypeManager::class),
                ))
                ->allowAll()
                ->methods('GET')
                ->build(),
        );
    }

    /**
     * @return list<HttpMiddlewareInterface>
     */
    public function middleware(EntityTypeManager $entityTypeManager): array
    {
        return [];
    }
}
