<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Auth\Authentication\VerifiedEmailAuthenticationEligibility;
use Waaseyaa\Auth\Config\AuthConfig;
use Waaseyaa\Auth\Config\MailMissingPolicy;
use Waaseyaa\Auth\Controller\LoginController;
use Waaseyaa\Auth\Controller\RegisterController;
use Waaseyaa\Auth\Controller\ResendVerificationController;
use Waaseyaa\Auth\Controller\VerifyEmailController;
use Waaseyaa\Auth\EmailVerificationTransaction;
use Waaseyaa\Auth\RateLimiter;
use Waaseyaa\Auth\Tests\Support\AuthSchema;
use Waaseyaa\Auth\Token\AuthTokenRepository;
use Waaseyaa\Auth\TwoFactorManager;
use Waaseyaa\Auth\TwoFactorService;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Tests\Support\UserIdentityLookupFixture;
use Waaseyaa\Tests\Support\UserInternalFieldReaderFixture;
use Waaseyaa\User\AuthMailer;
use Waaseyaa\User\Middleware\SessionMiddleware;
use Waaseyaa\User\User;

#[CoversNothing]
final class VerifiedEmailLifecycleTest extends TestCase
{
    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function registration_resend_verification_login_and_session_resolution_share_one_policy(): void
    {
        session_save_path(sys_get_temp_dir());
        self::assertTrue(session_start());
        $_SESSION = [];

        $database = DBALDatabase::createSqlite();
        AuthSchema::install($database);
        $userType = new EntityType(
            id: 'user',
            label: 'User',
            class: User::class,
            keys: ['id' => 'uid', 'uuid' => 'uuid', 'label' => 'name'],
        );
        new SqlSchemaHandler($userType, $database)->ensureTable();
        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $userType,
            new SqlStorageDriver(new SingleConnectionResolver($database), 'uid'),
            new EventDispatcher(),
            database: $database,
        );
        $entityTypes = new EntityTypeManager(
            new EventDispatcher(),
            repositoryFactory: static fn(): EntityRepository => $repository,
        );
        $entityTypes->registerEntityType($userType);

        $config = new AuthConfig('open', true, MailMissingPolicy::Fail, 'abcdefghijklmnopqrstuvwxyz012345', []);
        $internalFields = new UserInternalFieldReaderFixture();
        $identityLookup = new UserIdentityLookupFixture();
        $eligibility = new VerifiedEmailAuthenticationEligibility($config, $internalFields);
        $tokens = new AuthTokenRepository($database, 'abcdefghijklmnopqrstuvwxyz012345');
        $capturedToken = null;
        $mailer = $this->createStub(AuthMailer::class);
        $mailer->method('isConfigured')->willReturn(true);
        $mailer->method('sendEmailVerification')->willReturnCallback(
            static function (object $user, string $token) use (&$capturedToken): void {
                $capturedToken = $token;
            },
        );

        $register = new RegisterController(
            $config,
            $entityTypes,
            $tokens,
            $mailer,
            new RateLimiter(),
            $identityLookup,
            $internalFields,
            $eligibility,
        );
        $registration = $register($this->json('/api/auth/register', [
            'name' => 'member',
            'email' => 'member@example.test',
            'password' => 'correct horse battery staple',
        ]));
        self::assertSame(201, $registration->getStatusCode());
        self::assertArrayNotHasKey('waaseyaa_uid', $_SESSION);

        $resend = new ResendVerificationController(
            $config,
            $entityTypes,
            $tokens,
            $mailer,
            new RateLimiter(),
            $identityLookup,
            $internalFields,
        );
        self::assertSame(200, $resend($this->json('/api/auth/resend-verification', ['email' => 'member@example.test']))->getStatusCode());
        self::assertIsString($capturedToken);

        $verify = new VerifyEmailController(
            $entityTypes,
            $tokens,
            new EmailVerificationTransaction($database, $tokens),
        );
        self::assertSame(200, $verify($this->json('/api/auth/verify-email', ['token' => $capturedToken]))->getStatusCode());

        $login = new LoginController(
            $entityTypes,
            new RateLimiter(),
            new TwoFactorService(new TwoFactorManager(), $entityTypes, $internalFields),
            $identityLookup,
            $internalFields,
            $eligibility,
        );
        self::assertSame(200, $login($this->json('/api/auth/login', [
            'username' => 'member@example.test',
            'password' => 'correct horse battery staple',
        ]))->getStatusCode());

        $capturedAccount = null;
        $request = Request::create('/protected');
        $request->attributes->set('_session', $_SESSION);
        new SessionMiddleware(
            $repository,
            internalFields: $internalFields,
            authenticationEligibility: $eligibility,
        )->process($request, new class ($capturedAccount) implements HttpHandlerInterface {
            public function __construct(private ?AccountInterface &$account) {}
            public function handle(Request $request): Response
            {
                $this->account = $request->attributes->get('_account');
                return new Response();
            }
        });

        self::assertInstanceOf(User::class, $capturedAccount);
        self::assertTrue($internalFields->verification($capturedAccount)->emailVerified);
        session_write_close();
    }

    /** @param array<string, mixed> $body */
    private function json(string $path, array $body): Request
    {
        return Request::create(
            $path,
            'POST',
            server: ['REMOTE_ADDR' => '127.0.0.1'],
            content: json_encode($body, JSON_THROW_ON_ERROR),
        );
    }
}
