<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Auth\Config\AuthConfig;
use Waaseyaa\Auth\Config\MailMissingPolicy;
use Waaseyaa\Auth\Controller\ForgotPasswordController;
use Waaseyaa\Auth\RateLimiter;
use Waaseyaa\Auth\Token\AuthTokenRepositoryInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\User\AuthMailer;

#[CoversClass(ForgotPasswordController::class)]
final class ForgotPasswordControllerTest extends TestCase
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

    private function makeStorage(mixed $user = null): EntityStorageInterface
    {
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('loadByKey')->willReturn($user);

        return $storage;
    }

    private function makeEntityTypeManager(?EntityStorageInterface $storage = null): EntityTypeManager
    {
        $manager = $this->createMock(EntityTypeManager::class);
        $manager->method('getStorage')->willReturn($storage ?? $this->makeStorage());

        return $manager;
    }

    private function makeTokenRepo(): AuthTokenRepositoryInterface
    {
        $repo = $this->createMock(AuthTokenRepositoryInterface::class);
        $repo->method('createToken')->willReturn('reset-token-abc');

        return $repo;
    }

    private function makeAuthMailer(bool $configured = true): AuthMailer
    {
        $mailer = $this->createStub(AuthMailer::class);
        $mailer->method('isConfigured')->willReturn($configured);

        return $mailer;
    }

    private function makeController(
        ?AuthConfig $config = null,
        ?EntityTypeManager $entityTypeManager = null,
        ?AuthTokenRepositoryInterface $tokenRepo = null,
        ?AuthMailer $authMailer = null,
        ?RateLimiter $rateLimiter = null,
    ): ForgotPasswordController {
        return new ForgotPasswordController(
            config: $config ?? $this->makeConfig(),
            entityTypeManager: $entityTypeManager ?? $this->makeEntityTypeManager(),
            tokenRepo: $tokenRepo ?? $this->makeTokenRepo(),
            authMailer: $authMailer ?? $this->makeAuthMailer(),
            rateLimiter: $rateLimiter ?? new RateLimiter(),
        );
    }

    private function makeRequest(array $body = [], string $ip = '127.0.0.1'): Request
    {
        $request = Request::create(
            '/api/auth/forgot-password',
            'POST',
            [],
            [],
            [],
            ['REMOTE_ADDR' => $ip],
            json_encode($body, JSON_THROW_ON_ERROR),
        );
        $request->headers->set('Content-Type', 'application/json');

        return $request;
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    #[Test]
    public function returns_422_when_email_is_empty(): void
    {
        $controller = $this->makeController();

        $response = $controller($this->makeRequest([]));

        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('email_required', $data['error']);
    }

    #[Test]
    public function returns_generic_200_regardless_of_email_existence(): void
    {
        // User NOT found in storage — still returns 200
        $storage = $this->makeStorage(null);
        $controller = $this->makeController(
            entityTypeManager: $this->makeEntityTypeManager($storage),
            authMailer: $this->makeAuthMailer(true),
        );

        $response = $controller($this->makeRequest(['email' => 'notfound@example.com']));

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertTrue($data['ok']);
        $this->assertStringContainsString('password reset link', $data['message']);
    }

    #[Test]
    public function returns_503_when_mail_not_configured_and_policy_is_fail(): void
    {
        $controller = $this->makeController(
            config: $this->makeConfig(MailMissingPolicy::Fail),
            authMailer: $this->makeAuthMailer(false),
        );

        $response = $controller($this->makeRequest(['email' => 'user@example.com']));

        $this->assertSame(503, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('mail_not_configured', $data['error']);
    }

    #[Test]
    public function returns_429_when_email_rate_limit_exceeded(): void
    {
        $rateLimiter = new RateLimiter();
        $email = 'flood@example.com';
        $key = 'forgot:email:' . $email;

        for ($i = 0; $i < 3; $i++) {
            $rateLimiter->hit($key, 900);
        }

        $controller = $this->makeController(rateLimiter: $rateLimiter);

        $response = $controller($this->makeRequest(['email' => $email]));

        $this->assertSame(429, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('too_many_attempts', $data['error']);
    }

    #[Test]
    public function returns_200_when_mail_not_configured_and_policy_is_dev_log(): void
    {
        $user = new \Waaseyaa\User\User(['uid' => 5, 'mail' => 'dev@example.com', 'name' => 'Dev']);
        $storage = $this->makeStorage($user);

        $controller = $this->makeController(
            config: $this->makeConfig(MailMissingPolicy::DevLog),
            entityTypeManager: $this->makeEntityTypeManager($storage),
            authMailer: $this->makeAuthMailer(false),
        );

        $response = $controller($this->makeRequest(['email' => 'dev@example.com']));

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertTrue($data['ok']);
    }
}
