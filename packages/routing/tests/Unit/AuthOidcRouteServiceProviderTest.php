<?php

declare(strict_types=1);

namespace Waaseyaa\Routing\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\User\UserIdentityLookupInterface;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Auth\Config\AuthConfig;
use Waaseyaa\Auth\Controller\ForgotPasswordController;
use Waaseyaa\Auth\Controller\LoginController;
use Waaseyaa\Auth\Controller\LogoutController;
use Waaseyaa\Auth\Controller\RegisterController;
use Waaseyaa\Auth\Controller\ResetPasswordController;
use Waaseyaa\Auth\Controller\ResendVerificationController;
use Waaseyaa\Auth\Controller\VerifyEmailController;
use Waaseyaa\Auth\Controller\VerifyTwoFactorController;
use Waaseyaa\Auth\Extension\AuthExtensionRegistry;
use Waaseyaa\Auth\Password\LegacyPasswordUpgrade;
use Waaseyaa\Auth\RateLimiterInterface;
use Waaseyaa\Auth\Token\AuthTokenRepositoryInterface;
use Waaseyaa\Auth\TwoFactorManager;
use Waaseyaa\Auth\TwoFactorService;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LoggerTrait;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Routing\AuthOidcRouteServiceProvider;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\User\AuthMailer;

#[CoversClass(AuthOidcRouteServiceProvider::class)]
final class AuthOidcRouteServiceProviderTest extends TestCase
{
    public function test_oidc_controller_resolution_failures_are_logged(): void
    {
        $logger = new class implements LoggerInterface {
            use LoggerTrait;

            /** @var list<array{level: LogLevel, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $services = new class ($logger) implements KernelServicesInterface {
            public function __construct(private readonly LoggerInterface $logger) {}

            public function get(string $abstract): ?object
            {
                if ($abstract === LoggerInterface::class) {
                    return $this->logger;
                }

                throw new \RuntimeException("Deliberate resolution failure for {$abstract}.");
            }
        };

        $provider = new AuthOidcRouteServiceProvider();
        $provider->setKernelServices($services);
        $method = new \ReflectionMethod($provider, 'registerOidcRoutes');
        $method->invoke($provider, new WaaseyaaRouter());

        self::assertCount(5, $logger->records);
        foreach ($logger->records as $record) {
            self::assertSame(LogLevel::WARNING, $record['level']);
            self::assertSame('OIDC route controller could not be resolved; route registration skipped.', $record['message']);
            self::assertArrayHasKey('controller', $record['context']);
            self::assertInstanceOf(\RuntimeException::class, $record['context']['exception']);
        }
    }

    /**
     * Production POST /api/auth/login is this provider's LoginController, not
     * AuthManager. A verifier that is bound but never passed here is a 401 that
     * looks like a forgotten password (#2544).
     */
    #[Test]
    public function the_login_route_receives_the_legacy_password_upgrade(): void
    {
        $entityTypes = $this->createStub(EntityTypeManager::class);
        $internalFields = $this->createStub(UserInternalFieldReaderInterface::class);
        $upgrade = new LegacyPasswordUpgrade($this->createStub(EntityTypeManagerInterface::class));
        $services = $this->authRouteServices([
            AuthConfig::class => AuthConfig::fromArray([]),
            AuthTokenRepositoryInterface::class => $this->createStub(AuthTokenRepositoryInterface::class),
            RateLimiterInterface::class => $this->createStub(RateLimiterInterface::class),
            AuthMailer::class => $this->createStub(AuthMailer::class),
            TwoFactorService::class => new TwoFactorService(new TwoFactorManager(), $entityTypes, $internalFields),
            UserIdentityLookupInterface::class => $this->createStub(UserIdentityLookupInterface::class),
            UserInternalFieldReaderInterface::class => $internalFields,
            LegacyPasswordUpgrade::class => $upgrade,
            AuthExtensionRegistry::class => AuthExtensionRegistry::defaults(),
        ]);

        $provider = new AuthOidcRouteServiceProvider();
        $provider->setKernelServices($services);
        $router = new WaaseyaaRouter();
        new \ReflectionMethod($provider, 'registerAuthRoutes')->invoke($provider, $router, $entityTypes);

        $controller = $router->getRouteCollection()->get('api.auth.login')?->getDefault('_controller');
        self::assertInstanceOf(LoginController::class, $controller);
        self::assertSame(
            $upgrade,
            new \ReflectionProperty(LoginController::class, 'passwords')->getValue($controller),
            'The registered login controller must receive the same LegacyPasswordUpgrade the container bound.',
        );
    }

    /**
     * Application auth policies and lifecycle listeners are composed into the
     * container registry. Every route controller that consumes that registry
     * must receive the composed instance instead of silently using defaults.
     */
    #[Test]
    public function auth_routes_receive_composed_extensions_and_runtime_logging(): void
    {
        $entityTypes = $this->createStub(EntityTypeManager::class);
        $internalFields = $this->createStub(UserInternalFieldReaderInterface::class);
        $extensions = AuthExtensionRegistry::defaults();
        $logger = new NullLogger();
        $services = $this->authRouteServices([
            AuthConfig::class => AuthConfig::fromArray([]),
            AuthTokenRepositoryInterface::class => $this->createStub(AuthTokenRepositoryInterface::class),
            RateLimiterInterface::class => $this->createStub(RateLimiterInterface::class),
            AuthMailer::class => $this->createStub(AuthMailer::class),
            TwoFactorService::class => new TwoFactorService(new TwoFactorManager(), $entityTypes, $internalFields),
            UserIdentityLookupInterface::class => $this->createStub(UserIdentityLookupInterface::class),
            UserInternalFieldReaderInterface::class => $internalFields,
            LegacyPasswordUpgrade::class => new LegacyPasswordUpgrade($this->createStub(EntityTypeManagerInterface::class)),
            AuthExtensionRegistry::class => $extensions,
            LoggerInterface::class => $logger,
        ]);

        $provider = new AuthOidcRouteServiceProvider();
        $provider->setKernelServices($services);
        $router = new WaaseyaaRouter();
        new \ReflectionMethod($provider, 'registerAuthRoutes')->invoke($provider, $router, $entityTypes);

        foreach ([
            'api.auth.register' => RegisterController::class,
            'api.auth.forgot_password' => ForgotPasswordController::class,
            'api.auth.verify_email' => VerifyEmailController::class,
            'api.auth.resend_verification' => ResendVerificationController::class,
            'api.auth.login' => LoginController::class,
            'api.auth.logout' => LogoutController::class,
            'api.auth.2fa.verify' => VerifyTwoFactorController::class,
        ] as $routeName => $controllerClass) {
            $controller = $router->getRouteCollection()->get($routeName)?->getDefault('_controller');
            self::assertInstanceOf($controllerClass, $controller);
            self::assertSame(
                $extensions,
                new \ReflectionProperty($controllerClass, 'extensions')->getValue($controller),
                "The {$routeName} controller must receive the container's composed auth extension registry.",
            );
        }

        $resetController = $router->getRouteCollection()->get('api.auth.reset_password')?->getDefault('_controller');
        self::assertInstanceOf(ResetPasswordController::class, $resetController);
        self::assertSame(
            $internalFields,
            new \ReflectionProperty(ResetPasswordController::class, 'internalFields')->getValue($resetController),
            'Password reset must receive the audited session-generation reader used for account-wide revocation.',
        );

        foreach ([
            'api.auth.register' => RegisterController::class,
            'api.auth.forgot_password' => ForgotPasswordController::class,
            'api.auth.resend_verification' => ResendVerificationController::class,
        ] as $routeName => $controllerClass) {
            $controller = $router->getRouteCollection()->get($routeName)?->getDefault('_controller');
            self::assertSame(
                $logger,
                new \ReflectionProperty($controllerClass, 'logger')->getValue($controller),
                "The {$routeName} controller must receive the runtime logger.",
            );
        }
    }

    /**
     * @param array<class-string, object> $map
     */
    private function authRouteServices(array $map): KernelServicesInterface
    {
        return new class ($map) implements KernelServicesInterface {
            /** @param array<class-string, object> $map */
            public function __construct(private readonly array $map) {}

            public function get(string $abstract): ?object
            {
                return $this->map[$abstract] ?? null;
            }
        };
    }
}
