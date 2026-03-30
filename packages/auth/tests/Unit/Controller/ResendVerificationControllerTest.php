<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Auth\Config\AuthConfig;
use Waaseyaa\Auth\Config\MailMissingPolicy;
use Waaseyaa\Auth\Controller\ResendVerificationController;
use Waaseyaa\Auth\RateLimiter;
use Waaseyaa\Auth\Token\AuthTokenRepositoryInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\User\AuthMailer;

#[CoversClass(ResendVerificationController::class)]
final class ResendVerificationControllerTest extends TestCase
{
    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeConfig(MailMissingPolicy $policy = MailMissingPolicy::DevLog): AuthConfig
    {
        return new AuthConfig(
            registration: 'open',
            requireVerifiedEmail: false,
            mailMissingPolicy: $policy,
            tokenSecret: 'test-secret',
            tokenTtls: [],
        );
    }

    private function makeTokenRepo(): AuthTokenRepositoryInterface
    {
        $repo = $this->createMock(AuthTokenRepositoryInterface::class);
        $repo->method('createToken')->willReturn('verify-token-abc');

        return $repo;
    }

    private function makeEntityTypeManager(?EntityStorageInterface $storage = null): EntityTypeManager
    {
        $manager = $this->createMock(EntityTypeManager::class);
        if ($storage !== null) {
            $manager->method('getStorage')->willReturn($storage);
        }

        return $manager;
    }

    private function makeAuthMailer(bool $configured = false): AuthMailer
    {
        $mailer = $this->createStub(AuthMailer::class);
        $mailer->method('isConfigured')->willReturn($configured);

        return $mailer;
    }

    private function makeAccount(int $userId): object
    {
        return new class ($userId) {
            public function __construct(private readonly int $uid) {}

            public function id(): int
            {
                return $this->uid;
            }
        };
    }

    private function requestWithAccount(?object $account): Request
    {
        $request = Request::create('/', 'POST');
        if ($account !== null) {
            $request->attributes->set('_account', $account);
        }

        return $request;
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    #[Test]
    public function returns_401_when_not_authenticated(): void
    {
        $controller = new ResendVerificationController(
            $this->makeConfig(),
            $this->makeEntityTypeManager(),
            $this->makeTokenRepo(),
            $this->makeAuthMailer(),
            new RateLimiter(),
        );

        $response = $controller($this->requestWithAccount(null));

        self::assertSame(401, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('unauthenticated', $data['error']);
    }

    #[Test]
    public function returns_429_when_rate_limited(): void
    {
        $rateLimiter = new RateLimiter();
        $account = $this->makeAccount(7);

        // Pre-fill rate limiter to exceed limit
        for ($i = 0; $i < 3; $i++) {
            $rateLimiter->hit('resend_verification:7', 3600);
        }

        $controller = new ResendVerificationController(
            $this->makeConfig(),
            $this->makeEntityTypeManager(),
            $this->makeTokenRepo(),
            $this->makeAuthMailer(),
            $rateLimiter,
        );

        $response = $controller($this->requestWithAccount($account));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('3600', $response->headers->get('Retry-After'));
    }

    #[Test]
    public function returns_200_and_sends_verification_email(): void
    {
        $account = $this->makeAccount(10);
        $user = new \Waaseyaa\User\User(['uid' => 10, 'name' => 'Alice', 'mail' => 'alice@example.com']);

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('load')->willReturn($user);

        $tokenRepo = $this->createMock(AuthTokenRepositoryInterface::class);
        $tokenRepo->method('createToken')->willReturn('new-verify-token');
        $tokenRepo->expects(self::once())->method('revokeTokensForUser')->with(10, 'email_verification');

        $mailer = $this->createStub(AuthMailer::class);
        $mailer->method('isConfigured')->willReturn(true);

        $controller = new ResendVerificationController(
            $this->makeConfig(),
            $this->makeEntityTypeManager($storage),
            $tokenRepo,
            $mailer,
            new RateLimiter(),
        );

        $response = $controller($this->requestWithAccount($account));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertTrue($data['ok']);
        self::assertSame('Verification email sent.', $data['message']);
    }

    #[Test]
    public function logs_url_when_mail_not_configured_and_policy_is_devlog(): void
    {
        $account = $this->makeAccount(11);
        $user = new \Waaseyaa\User\User(['uid' => 11, 'name' => 'Bob', 'mail' => 'bob@example.com']);

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('load')->willReturn($user);

        $mailer = $this->createStub(AuthMailer::class);
        $mailer->method('isConfigured')->willReturn(false);

        $controller = new ResendVerificationController(
            $this->makeConfig(MailMissingPolicy::DevLog),
            $this->makeEntityTypeManager($storage),
            $this->makeTokenRepo(),
            $mailer,
            new RateLimiter(),
        );

        $response = $controller($this->requestWithAccount($account));

        self::assertSame(200, $response->getStatusCode());
    }
}
