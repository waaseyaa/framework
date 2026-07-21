<?php

declare(strict_types=1);

namespace Waaseyaa\Routing;

use Waaseyaa\Access\User\UserIdentityLookupInterface;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Auth\Config\AuthConfig;
use Waaseyaa\Auth\Controller\DisableTwoFactorController;
use Waaseyaa\Auth\Controller\EnableTwoFactorController;
use Waaseyaa\Auth\Controller\ForgotPasswordController;
use Waaseyaa\Auth\Controller\LoginController;
use Waaseyaa\Auth\Controller\LogoutController;
use Waaseyaa\Auth\Controller\MeController;
use Waaseyaa\Auth\Controller\RegisterController;
use Waaseyaa\Auth\Controller\ResendVerificationController;
use Waaseyaa\Auth\Controller\ResetPasswordController;
use Waaseyaa\Auth\Controller\SetupTwoFactorController;
use Waaseyaa\Auth\Controller\VerifyEmailController;
use Waaseyaa\Auth\Controller\VerifyTwoFactorController;
use Waaseyaa\Auth\RateLimiterInterface;
use Waaseyaa\Auth\Token\AuthTokenRepositoryInterface;
use Waaseyaa\Auth\TwoFactorService;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Oidc\Authorize\AuthorizeController;
use Waaseyaa\Oidc\Consent\ConsentScreenController;
use Waaseyaa\Oidc\Revoke\RevocationController;
use Waaseyaa\Oidc\Token\TokenController;
use Waaseyaa\Oidc\Userinfo\UserinfoController;
use Waaseyaa\User\AuthMailer;
use Waaseyaa\User\Http\AuthController as UserAuthController;

/**
 * Registers auth and OIDC HTTP routes. Layer 4: uses RouteBuilder / WaaseyaaRouter only here,
 * not in waaseyaa/auth or waaseyaa/oidc service providers.
 */
final class AuthOidcRouteServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function routes(WaaseyaaRouter $router, EntityTypeManager $entityTypeManager): void
    {
        $this->registerAuthRoutes($router, $entityTypeManager);
        $this->registerOidcRoutes($router);
    }

    private function registerAuthRoutes(WaaseyaaRouter $router, EntityTypeManager $entityTypeManager): void
    {
        $authConfig = $this->resolve(AuthConfig::class);
        $tokenRepo = $this->resolve(AuthTokenRepositoryInterface::class);
        $rateLimiter = $this->resolve(RateLimiterInterface::class);
        $authMailer = $this->resolve(AuthMailer::class);
        $twoFactor = $this->resolve(TwoFactorService::class);
        $identityLookup = $this->resolve(UserIdentityLookupInterface::class);
        $internalFields = $this->resolve(UserInternalFieldReaderInterface::class);
        $etm = $entityTypeManager;

        $router->addRoute(
            'api.auth.register',
            RouteBuilder::create('/api/auth/register')
                ->controller(new RegisterController(
                    config: $authConfig,
                    entityTypeManager: $etm,
                    tokenRepo: $tokenRepo,
                    authMailer: $authMailer,
                    rateLimiter: $rateLimiter,
                    identityLookup: $identityLookup,
                    internalFields: $internalFields,
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
                    entityTypeManager: $etm,
                    tokenRepo: $tokenRepo,
                    authMailer: $authMailer,
                    rateLimiter: $rateLimiter,
                    identityLookup: $identityLookup,
                ))
                ->allowAll()
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'api.auth.reset_password',
            RouteBuilder::create('/api/auth/reset-password')
                ->controller(new ResetPasswordController(
                    entityTypeManager: $etm,
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
                    entityTypeManager: $etm,
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
                    entityTypeManager: $etm,
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
                    entityTypeManager: $etm,
                    rateLimiter: $rateLimiter,
                    twoFactor: $twoFactor,
                    identityLookup: $identityLookup,
                    internalFields: $internalFields,
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
                ->controller(new MeController(new UserAuthController($internalFields)))
                ->allowAll()
                ->methods('GET')
                // Beat JsonApiRouteProvider's `/api/user/{id}` (priority 0),
                // which would otherwise treat `me` as a literal entity id.
                ->priority(10)
                ->build(),
        );

        $router->addRoute(
            'api.auth.2fa.setup',
            RouteBuilder::create('/api/auth/2fa/setup')
                ->controller(new SetupTwoFactorController($twoFactor))
                ->allowAll()
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'api.auth.2fa.enable',
            RouteBuilder::create('/api/auth/2fa/enable')
                ->controller(new EnableTwoFactorController($twoFactor))
                ->allowAll()
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'api.auth.2fa.verify',
            RouteBuilder::create('/api/auth/2fa/verify')
                ->controller(new VerifyTwoFactorController($twoFactor, $rateLimiter, $etm))
                ->allowAll()
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'api.auth.2fa.disable',
            RouteBuilder::create('/api/auth/2fa/disable')
                ->controller(new DisableTwoFactorController($twoFactor))
                ->allowAll()
                ->methods('POST')
                ->build(),
        );
    }

    private function registerOidcRoutes(WaaseyaaRouter $router): void
    {
        if (!class_exists(AuthorizeController::class)) {
            return;
        }

        $authorizeController = null;
        try {
            $authorizeController = $this->resolve(AuthorizeController::class);
        } catch (\Throwable $exception) {
            $this->logOidcResolutionFailure(AuthorizeController::class, $exception);
        }

        $tokenController = null;
        try {
            $tokenController = $this->resolve(TokenController::class);
        } catch (\Throwable $exception) {
            $this->logOidcResolutionFailure(TokenController::class, $exception);
        }

        $revocationController = null;
        try {
            $revocationController = $this->resolve(RevocationController::class);
        } catch (\Throwable $exception) {
            $this->logOidcResolutionFailure(RevocationController::class, $exception);
        }

        $userinfoController = null;
        try {
            $userinfoController = $this->resolve(UserinfoController::class);
        } catch (\Throwable $exception) {
            $this->logOidcResolutionFailure(UserinfoController::class, $exception);
        }

        $consentScreenController = null;
        try {
            $consentScreenController = $this->resolve(ConsentScreenController::class);
        } catch (\Throwable $exception) {
            $this->logOidcResolutionFailure(ConsentScreenController::class, $exception);
        }

        new OidcHttpRoutes(
            authorizeController: $authorizeController,
            tokenController: $tokenController,
            revocationController: $revocationController,
            userinfoController: $userinfoController,
            consentScreenController: $consentScreenController,
        )->registerRoutes($router);
    }

    /** @param class-string $controller */
    private function logOidcResolutionFailure(string $controller, \Throwable $exception): void
    {
        $logger = $this->resolveOptional(LoggerInterface::class);
        if (!$logger instanceof LoggerInterface) {
            return;
        }

        $logger->warning('OIDC route controller could not be resolved; route registration skipped.', [
            'controller' => $controller,
            'exception' => $exception,
        ]);
    }
}
