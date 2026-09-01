<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\User\UserIdentityLookupInterface;
use Waaseyaa\Auth\AtomicRateLimiterInterface;
use Waaseyaa\Auth\Config\AuthConfig;
use Waaseyaa\Auth\Config\MailMissingPolicy;
use Waaseyaa\Auth\Controller\ResendVerificationController;
use Waaseyaa\Auth\RateLimiter;
use Waaseyaa\Auth\Token\AuthTokenRepositoryInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Tests\Support\UserInternalFieldReaderFixture;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\User\AuthMailer;
use Waaseyaa\User\User;

#[CoversClass(ResendVerificationController::class)]
final class ResendVerificationControllerTest extends TestCase
{
    private function controller(
        ?User $found = null,
        ?AtomicRateLimiterInterface $rateLimiter = null,
        ?AuthTokenRepositoryInterface $tokens = null,
        ?AuthMailer $mailer = null,
        ?UserIdentityLookupInterface $lookup = null,
        ?LoggerInterface $logger = null,
        MailMissingPolicy $mailPolicy = MailMissingPolicy::DevLog,
    ): ResendVerificationController {
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $manager = $this->createStub(EntityTypeManager::class);
        $manager->method('getRepository')->willReturn($repository);

        if ($lookup === null) {
            $lookup = $this->createStub(UserIdentityLookupInterface::class);
            $lookup->method('findActiveByMail')->willReturn($found);
        }

        $tokens ??= $this->createStub(AuthTokenRepositoryInterface::class);
        $tokens->method('createToken')->willReturn('verification-token');

        $mailer ??= $this->createStub(AuthMailer::class);
        $mailer->method('isConfigured')->willReturn(false);

        return new ResendVerificationController(
            new AuthConfig('open', true, $mailPolicy, 'test-secret', []),
            $manager,
            $tokens,
            $mailer,
            $rateLimiter ?? new RateLimiter(),
            $lookup,
            new UserInternalFieldReaderFixture(),
            $logger,
        );
    }

    private function request(string $email, string $ip = '127.0.0.1'): Request
    {
        return Request::create(
            '/api/auth/resend-verification',
            'POST',
            server: ['REMOTE_ADDR' => $ip],
            content: json_encode(['email' => $email], JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function it_is_usable_without_an_authenticated_account(): void
    {
        $tokens = $this->createMock(AuthTokenRepositoryInterface::class);
        $tokens->expects(self::once())->method('createToken')->with(7, 'email_verification', 86400)->willReturn('token');
        $user = new User(['uid' => 7, 'status' => true, 'mail' => 'member@example.test', 'email_verified' => false]);

        $response = ($this->controller(found: $user, tokens: $tokens))($this->request(' MEMBER@EXAMPLE.TEST '));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('If an unverified account exists', (string) $response->getContent());
    }

    #[Test]
    public function absent_and_verified_accounts_have_the_same_public_response(): void
    {
        $absent = ($this->controller())($this->request('member@example.test'));
        $verifiedUser = new User(['uid' => 7, 'status' => true, 'mail' => 'member@example.test', 'email_verified' => true]);
        $verified = ($this->controller(found: $verifiedUser))($this->request('member@example.test'));

        self::assertSame(200, $absent->getStatusCode());
        self::assertSame($absent->getContent(), $verified->getContent());
    }

    #[Test]
    public function a_username_match_cannot_redirect_verification_to_another_address(): void
    {
        $tokens = $this->createMock(AuthTokenRepositoryInterface::class);
        $tokens->expects(self::never())->method('createToken');
        $user = new User(['uid' => 7, 'status' => true, 'mail' => 'different@example.test', 'email_verified' => false]);

        $response = ($this->controller(found: $user, tokens: $tokens))($this->request('member@example.test'));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function it_rate_limits_normalized_email_and_source_ip(): void
    {
        $limiter = new RateLimiter();
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $limiter->hit('resend_verification:email:' . hash('sha256', 'member@example.test'), 3600);
        }

        $response = ($this->controller(rateLimiter: $limiter))($this->request('Member@Example.Test'));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('3600', $response->headers->get('Retry-After'));
    }

    #[Test]
    public function valid_long_email_uses_a_fixed_length_rate_limit_bucket(): void
    {
        $email = str_repeat('a', 60) . '@' . implode('.', array_fill(0, 4, str_repeat('b', 45))) . '.test';
        self::assertNotFalse(filter_var($email, FILTER_VALIDATE_EMAIL));
        $expectedKey = 'resend_verification:email:' . hash('sha256', $email);

        $limiter = $this->createMock(AtomicRateLimiterInterface::class);
        $limiter->expects(self::exactly(2))
            ->method('consume')
            ->willReturnCallback(static function (string $key, int $limit, int $windowSeconds) use ($expectedKey): bool {
                self::assertContains($key, [$expectedKey, 'resend_verification:ip:127.0.0.1']);
                self::assertLessThanOrEqual(255, strlen($key));
                self::assertContains([$limit, $windowSeconds], [[3, 3600], [10, 3600]]);

                return true;
            });

        $response = ($this->controller(rateLimiter: $limiter))($this->request($email));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function production_mail_failure_is_global_and_precedes_lookup(): void
    {
        $response = ($this->controller(mailPolicy: MailMissingPolicy::Fail))($this->request('missing@example.test'));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('mail_not_configured', json_decode((string) $response->getContent(), true)['error']);
    }

    #[Test]
    public function delivery_failure_keeps_the_same_public_response_as_an_absent_account(): void
    {
        $absent = ($this->controller())($this->request('member@example.test'));

        $tokens = $this->createMock(AuthTokenRepositoryInterface::class);
        $tokens->method('createToken')->willReturn('plain-token');
        $tokens->expects(self::once())->method('revokeTokensForUser')->with(7, 'email_verification');
        $mailer = $this->createStub(AuthMailer::class);
        $mailer->method('isConfigured')->willReturn(true);
        $mailer->method('sendEmailVerification')->willThrowException(new \RuntimeException('smtp unavailable'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Verification delivery failed after a public resend request.');
        $user = new User(['uid' => 7, 'status' => true, 'mail' => 'member@example.test', 'email_verified' => false]);

        $failed = ($this->controller(found: $user, tokens: $tokens, mailer: $mailer, logger: $logger))(
            $this->request('member@example.test'),
        );

        self::assertSame(200, $failed->getStatusCode());
        self::assertSame($absent->getContent(), $failed->getContent());
    }

    #[Test]
    public function mixed_case_legacy_address_is_looked_up_before_the_normalized_fallback(): void
    {
        $user = new User(['uid' => 7, 'status' => true, 'mail' => 'Member@Example.Test', 'email_verified' => false]);
        $lookup = $this->createMock(UserIdentityLookupInterface::class);
        $lookup->expects(self::once())
            ->method('findActiveByMail')
            ->with(self::isInstanceOf(EntityRepositoryInterface::class), 'Member@Example.Test')
            ->willReturn($user);

        $response = ($this->controller(found: $user, lookup: $lookup))(
            $this->request('Member@Example.Test'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function email_shaped_username_cannot_shadow_the_mail_owner(): void
    {
        $mailOwner = new User(['uid' => 7, 'name' => 'victim', 'status' => true, 'mail' => 'victim@example.test', 'email_verified' => false]);
        $lookup = $this->createMock(UserIdentityLookupInterface::class);
        $lookup->expects(self::once())
            ->method('findActiveByMail')
            ->with(self::isInstanceOf(EntityRepositoryInterface::class), 'victim@example.test')
            ->willReturn($mailOwner);
        $lookup->expects(self::never())->method('findActiveByLogin');
        $tokens = $this->createMock(AuthTokenRepositoryInterface::class);
        $tokens->expects(self::once())->method('createToken')->with(7, 'email_verification', 86400)->willReturn('token');

        $response = ($this->controller(tokens: $tokens, lookup: $lookup))(
            $this->request('victim@example.test'),
        );

        self::assertSame(200, $response->getStatusCode());
    }
}
