<?php

declare(strict_types=1);

namespace Waaseyaa\Routing;

use Waaseyaa\Access\User\UserIdentityLookupInterface;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Auth\AtomicRateLimiterInterface;
use Waaseyaa\Auth\Authentication\VerifiedEmailAuthenticationEligibility;
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
use Waaseyaa\Auth\EmailVerificationTransaction;
use Waaseyaa\Auth\Extension\AuthExtensionRegistry;
use Waaseyaa\Auth\Password\LegacyPasswordUpgrade;
use Waaseyaa\Auth\RateLimiterInterface;
use Waaseyaa\Auth\Token\AuthTokenRepositoryInterface;
use Waaseyaa\Auth\TwoFactorService;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Routing\Metadata\HandlerReference;
use Waaseyaa\Foundation\Routing\Metadata\RouteContributionContext;
use Waaseyaa\Foundation\Routing\Metadata\RouteDefinition;
use Waaseyaa\Foundation\ServiceProvider\Capability\ContributesRouteMetadataInterface;
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
final class AuthOidcRouteServiceProvider extends ServiceProvider implements ContributesRouteMetadataInterface
{
    public function register(): void
    {
        // Admit the package-owned metadata helper during bootstrap, before inspection.
        class_exists(OidcHttpRoutes::class);
        $this->bind(RegisterController::class, fn(): RegisterController => $this->createRegisterController());
        $this->bind(ForgotPasswordController::class, fn(): ForgotPasswordController => $this->createForgotPasswordController());
        $this->bind(ResetPasswordController::class, fn(): ResetPasswordController => $this->createResetPasswordController());
        $this->bind(VerifyEmailController::class, fn(): VerifyEmailController => $this->createVerifyEmailController());
        $this->bind(ResendVerificationController::class, fn(): ResendVerificationController => $this->createResendVerificationController());
        $this->bind(LoginController::class, fn(): LoginController => $this->createLoginController());
        $this->bind(LogoutController::class, fn(): LogoutController => $this->createLogoutController());
        $this->bind(MeController::class, fn(): MeController => $this->createMeController());
        $this->bind(SetupTwoFactorController::class, fn(): SetupTwoFactorController => $this->createSetupTwoFactorController());
        $this->bind(EnableTwoFactorController::class, fn(): EnableTwoFactorController => $this->createEnableTwoFactorController());
        $this->bind(VerifyTwoFactorController::class, fn(): VerifyTwoFactorController => $this->createVerifyTwoFactorController());
        $this->bind(DisableTwoFactorController::class, fn(): DisableTwoFactorController => $this->createDisableTwoFactorController());
    }

    public function routes(WaaseyaaRouter $router, EntityTypeManager $entityTypeManager): void
    {
        $this->registerAuthRoutes($router, $entityTypeManager);
        $this->registerOidcRoutes($router);
    }

    public function routeDefinitions(RouteContributionContext $context): iterable
    {
        foreach ($this->authDefinitions($context) as $definition) {
            yield $definition;
        }
        foreach (OidcHttpRoutes::routeDefinitions($context, 12) as $definition) {
            yield $definition;
        }
    }

    /** @return iterable<RouteDefinition> */
    private function authDefinitions(RouteContributionContext $context): iterable
    {
        foreach ([
            ['api.auth.register', '/api/auth/register', RegisterController::class, 'POST', 0],
            ['api.auth.forgot_password', '/api/auth/forgot-password', ForgotPasswordController::class, 'POST', 0],
            ['api.auth.reset_password', '/api/auth/reset-password', ResetPasswordController::class, 'POST', 0],
            ['api.auth.verify_email', '/api/auth/verify-email', VerifyEmailController::class, 'POST', 0],
            ['api.auth.resend_verification', '/api/auth/resend-verification', ResendVerificationController::class, 'POST', 0],
            ['api.auth.login', '/api/auth/login', LoginController::class, 'POST', 0],
            ['api.auth.logout', '/api/auth/logout', LogoutController::class, 'POST', 0],
            ['api.user.me', '/api/user/me', MeController::class, 'GET', 10],
            ['api.auth.2fa.setup', '/api/auth/2fa/setup', SetupTwoFactorController::class, 'POST', 0],
            ['api.auth.2fa.enable', '/api/auth/2fa/enable', EnableTwoFactorController::class, 'POST', 0],
            ['api.auth.2fa.verify', '/api/auth/2fa/verify', VerifyTwoFactorController::class, 'POST', 0],
            ['api.auth.2fa.disable', '/api/auth/2fa/disable', DisableTwoFactorController::class, 'POST', 0],
        ] as $ordinal => [$name, $path, $controller, $method, $priority]) {
            yield new RouteDefinition($name, $path, HandlerReference::fromString('class:' . $controller . '::__invoke'), methods: [$method], options: ['_public' => true], priority: $priority, sourceId: $context->sourceId, ordinal: $ordinal);
        }
    }

    /** Compatibility projection; admitted HTTP never invokes this hook. */
    private function registerAuthRoutes(WaaseyaaRouter $router, EntityTypeManager $entityTypeManager): void
    {
        foreach ($this->authDefinitions(new RouteContributionContext(self::class, 0)) as $definition) {
            $controller = match ($definition->handler->target) {
                RegisterController::class => $this->createRegisterController($entityTypeManager),
                ForgotPasswordController::class => $this->createForgotPasswordController($entityTypeManager),
                ResetPasswordController::class => $this->createResetPasswordController($entityTypeManager),
                VerifyEmailController::class => $this->createVerifyEmailController($entityTypeManager),
                ResendVerificationController::class => $this->createResendVerificationController($entityTypeManager),
                LoginController::class => $this->createLoginController($entityTypeManager),
                LogoutController::class => $this->createLogoutController($entityTypeManager),
                MeController::class => $this->createMeController($entityTypeManager),
                SetupTwoFactorController::class => $this->createSetupTwoFactorController($entityTypeManager),
                EnableTwoFactorController::class => $this->createEnableTwoFactorController($entityTypeManager),
                VerifyTwoFactorController::class => $this->createVerifyTwoFactorController($entityTypeManager),
                DisableTwoFactorController::class => $this->createDisableTwoFactorController($entityTypeManager),
                default => throw new \LogicException('Unknown auth handler.'),
            };
            $route = new RouteMetadataCompiler()->compileRoute($definition);
            if ($definition->priority === 0) {
                $options = $route->getOptions();
                unset($options['_waaseyaa_priority']);
                $route->setOptions($options);
            }
            $route->setDefault('_controller', $controller);
            $router->addRoute($definition->name, $route);
        }
    }

    private function createRegisterController(?EntityTypeManager $entityTypeManager = null): RegisterController
    {
        return new RegisterController(
            config: $this->resolve(AuthConfig::class),
            entityTypeManager: $entityTypeManager ?? $this->resolve(EntityTypeManager::class),
            tokenRepo: $this->resolve(AuthTokenRepositoryInterface::class),
            authMailer: $this->resolve(AuthMailer::class),
            rateLimiter: $this->resolve(RateLimiterInterface::class),
            identityLookup: $this->resolve(UserIdentityLookupInterface::class),
            internalFields: $this->resolve(UserInternalFieldReaderInterface::class),
            eligibility: $this->resolve(VerifiedEmailAuthenticationEligibility::class),
            logger: $this->resolveOptional(LoggerInterface::class),
            extensions: $this->resolve(AuthExtensionRegistry::class),
        );
    }

    private function createForgotPasswordController(?EntityTypeManager $entityTypeManager = null): ForgotPasswordController
    {
        return new ForgotPasswordController(
            config: $this->resolve(AuthConfig::class),
            entityTypeManager: $entityTypeManager ?? $this->resolve(EntityTypeManager::class),
            tokenRepo: $this->resolve(AuthTokenRepositoryInterface::class),
            authMailer: $this->resolve(AuthMailer::class),
            rateLimiter: $this->resolve(RateLimiterInterface::class),
            identityLookup: $this->resolve(UserIdentityLookupInterface::class),
            logger: $this->resolveOptional(LoggerInterface::class),
            extensions: $this->resolve(AuthExtensionRegistry::class),
        );
    }

    private function createResetPasswordController(?EntityTypeManager $entityTypeManager = null): ResetPasswordController
    {
        return new ResetPasswordController(
            entityTypeManager: $entityTypeManager ?? $this->resolve(EntityTypeManager::class),
            tokenRepo: $this->resolve(AuthTokenRepositoryInterface::class),
            internalFields: $this->resolve(UserInternalFieldReaderInterface::class),
        );
    }

    private function createVerifyEmailController(?EntityTypeManager $entityTypeManager = null): VerifyEmailController
    {
        return new VerifyEmailController(
            entityTypeManager: $entityTypeManager ?? $this->resolve(EntityTypeManager::class),
            tokenRepo: $this->resolve(AuthTokenRepositoryInterface::class),
            verificationTransaction: $this->resolve(EmailVerificationTransaction::class),
            extensions: $this->resolve(AuthExtensionRegistry::class),
        );
    }

    private function createResendVerificationController(?EntityTypeManager $entityTypeManager = null): ResendVerificationController
    {
        return new ResendVerificationController(
            config: $this->resolve(AuthConfig::class),
            entityTypeManager: $entityTypeManager ?? $this->resolve(EntityTypeManager::class),
            tokenRepo: $this->resolve(AuthTokenRepositoryInterface::class),
            authMailer: $this->resolve(AuthMailer::class),
            rateLimiter: $this->resolve(AtomicRateLimiterInterface::class),
            identityLookup: $this->resolve(UserIdentityLookupInterface::class),
            internalFields: $this->resolve(UserInternalFieldReaderInterface::class),
            logger: $this->resolveOptional(LoggerInterface::class),
            extensions: $this->resolve(AuthExtensionRegistry::class),
        );
    }

    private function createLoginController(?EntityTypeManager $entityTypeManager = null): LoginController
    {
        return new LoginController(
            entityTypeManager: $entityTypeManager ?? $this->resolve(EntityTypeManager::class),
            rateLimiter: $this->resolve(AtomicRateLimiterInterface::class),
            twoFactor: $this->resolve(TwoFactorService::class),
            identityLookup: $this->resolve(UserIdentityLookupInterface::class),
            internalFields: $this->resolve(UserInternalFieldReaderInterface::class),
            eligibility: $this->resolve(VerifiedEmailAuthenticationEligibility::class),
            extensions: $this->resolve(AuthExtensionRegistry::class),
            passwords: $this->resolve(LegacyPasswordUpgrade::class),
        );
    }

    private function createLogoutController(?EntityTypeManager $entityTypeManager = null): LogoutController
    {
        return new LogoutController($this->resolve(AuthExtensionRegistry::class));
    }

    private function createMeController(?EntityTypeManager $entityTypeManager = null): MeController
    {
        return new MeController(new UserAuthController($this->resolve(UserInternalFieldReaderInterface::class)));
    }

    private function createSetupTwoFactorController(?EntityTypeManager $entityTypeManager = null): SetupTwoFactorController
    {
        return new SetupTwoFactorController($this->resolve(TwoFactorService::class));
    }

    private function createEnableTwoFactorController(?EntityTypeManager $entityTypeManager = null): EnableTwoFactorController
    {
        return new EnableTwoFactorController($this->resolve(TwoFactorService::class));
    }

    private function createVerifyTwoFactorController(?EntityTypeManager $entityTypeManager = null): VerifyTwoFactorController
    {
        return new VerifyTwoFactorController(
            twoFactor: $this->resolve(TwoFactorService::class),
            rateLimiter: $this->resolve(RateLimiterInterface::class),
            entityTypeManager: $entityTypeManager ?? $this->resolve(EntityTypeManager::class),
            internalFields: $this->resolve(UserInternalFieldReaderInterface::class),
            eligibility: $this->resolve(VerifiedEmailAuthenticationEligibility::class),
            extensions: $this->resolve(AuthExtensionRegistry::class),
        );
    }

    private function createDisableTwoFactorController(?EntityTypeManager $entityTypeManager = null): DisableTwoFactorController
    {
        return new DisableTwoFactorController($this->resolve(TwoFactorService::class));
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
