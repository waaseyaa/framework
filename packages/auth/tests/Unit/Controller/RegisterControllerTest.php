<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Auth\Config\AuthConfig;
use Waaseyaa\Auth\Config\MailMissingPolicy;
use Waaseyaa\Auth\Controller\RegisterController;
use Waaseyaa\Auth\RateLimiter;
use Waaseyaa\Auth\Token\AuthTokenRepositoryInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\User\AuthMailer;

#[CoversClass(RegisterController::class)]
final class RegisterControllerTest extends TestCase
{
    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeConfig(string $registration = 'open'): AuthConfig
    {
        return new AuthConfig(
            registration: $registration,
            requireVerifiedEmail: false,
            mailMissingPolicy: MailMissingPolicy::DevLog,
            tokenSecret: 'test-secret',
            tokenTtls: [],
        );
    }

    private function makeEntityTypeManager(?EntityStorageInterface $storage = null): EntityTypeManager
    {
        $manager = $this->createMock(EntityTypeManager::class);

        if ($storage !== null) {
            $manager->method('getStorage')->willReturn($storage);
        }

        return $manager;
    }

    private function makeStorage(mixed $existing = null): EntityStorageInterface
    {
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('loadByKey')->willReturn($existing);
        $storage->method('save')->willReturn(1); // SAVED_NEW

        return $storage;
    }

    private function makeTokenRepo(): AuthTokenRepositoryInterface
    {
        $repo = $this->createMock(AuthTokenRepositoryInterface::class);
        $repo->method('createToken')->willReturn('tok_' . bin2hex(random_bytes(8)));
        $repo->method('validateToken')->willReturn(['id' => 1, 'user_id' => null, 'meta' => null]);

        return $repo;
    }

    private function makeAuthMailer(bool $configured = false): AuthMailer
    {
        $mailer = $this->createMock(AuthMailer::class);
        $mailer->method('isConfigured')->willReturn($configured);

        return $mailer;
    }

    private function makeController(
        AuthConfig $config,
        ?EntityTypeManager $entityTypeManager = null,
        ?AuthTokenRepositoryInterface $tokenRepo = null,
        ?AuthMailer $authMailer = null,
        ?RateLimiter $rateLimiter = null,
    ): RegisterController {
        return new RegisterController(
            config: $config,
            entityTypeManager: $entityTypeManager ?? $this->makeEntityTypeManager(),
            tokenRepo: $tokenRepo ?? $this->makeTokenRepo(),
            authMailer: $authMailer ?? $this->makeAuthMailer(),
            rateLimiter: $rateLimiter ?? new RateLimiter(),
        );
    }

    private function makeRequest(array $body = [], string $ip = '127.0.0.1'): Request
    {
        $request = Request::create(
            '/api/auth/register',
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
    public function returns_403_when_registration_is_admin(): void
    {
        $controller = $this->makeController(
            config: $this->makeConfig('admin'),
            entityTypeManager: $this->makeEntityTypeManager(), // not called, but required
        );

        $response = $controller(new Request());

        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('registration_disabled', $data['error']);
    }

    #[Test]
    public function returns_422_for_missing_fields(): void
    {
        $controller = $this->makeController(config: $this->makeConfig('open'));

        $response = $controller($this->makeRequest([]));

        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('name', $data['errors']);
        $this->assertArrayHasKey('email', $data['errors']);
        $this->assertArrayHasKey('password', $data['errors']);
    }

    #[Test]
    public function returns_422_for_short_name(): void
    {
        $controller = $this->makeController(config: $this->makeConfig('open'));

        $response = $controller($this->makeRequest([
            'name' => 'A',
            'email' => 'test@example.com',
            'password' => 'password123',
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('name', $data['errors']);
    }

    #[Test]
    public function returns_422_for_invalid_email(): void
    {
        $controller = $this->makeController(config: $this->makeConfig('open'));

        $response = $controller($this->makeRequest([
            'name' => 'Alice',
            'email' => 'not-an-email',
            'password' => 'password123',
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('email', $data['errors']);
    }

    #[Test]
    public function returns_422_for_short_password(): void
    {
        $controller = $this->makeController(config: $this->makeConfig('open'));

        $response = $controller($this->makeRequest([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'short',
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('password', $data['errors']);
    }

    #[Test]
    public function returns_422_for_invite_mode_without_token(): void
    {
        $controller = $this->makeController(config: $this->makeConfig('invite'));

        $response = $controller($this->makeRequest([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'password123',
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('invite_token', $data['errors']);
    }

    #[Test]
    public function returns_422_for_invalid_invite_token(): void
    {
        $tokenRepo = $this->createMock(AuthTokenRepositoryInterface::class);
        $tokenRepo->method('validateToken')->willReturn(null);

        $controller = $this->makeController(
            config: $this->makeConfig('invite'),
            tokenRepo: $tokenRepo,
        );

        $response = $controller($this->makeRequest([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'password123',
            'invite_token' => 'bad-token',
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('invite_token', $data['errors']);
    }

    #[Test]
    public function returns_422_for_duplicate_email(): void
    {
        // Storage returns an existing user for loadByKey
        $existingUser = new \Waaseyaa\User\User(['uid' => 1, 'mail' => 'alice@example.com']);
        $storage = $this->makeStorage($existingUser);

        $controller = $this->makeController(
            config: $this->makeConfig('open'),
            entityTypeManager: $this->makeEntityTypeManager($storage),
        );

        $response = $controller($this->makeRequest([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'password123',
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('email', $data['errors']);
    }

    #[Test]
    public function returns_201_on_successful_open_registration(): void
    {
        $_SESSION = [];
        $storage = $this->makeStorage(null);
        $mailer = $this->makeAuthMailer(false);

        $controller = $this->makeController(
            config: $this->makeConfig('open'),
            entityTypeManager: $this->makeEntityTypeManager($storage),
            authMailer: $mailer,
        );

        $response = $controller($this->makeRequest([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'password123',
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertSame('Alice', $data['data']['name']);
        $this->assertSame('alice@example.com', $data['data']['email']);
        $this->assertFalse($data['data']['email_verified']);
    }

    #[Test]
    public function returns_201_on_successful_invite_registration(): void
    {
        $_SESSION = [];
        $storage = $this->makeStorage(null);

        $tokenRepo = $this->createMock(AuthTokenRepositoryInterface::class);
        $tokenRepo->method('validateToken')->willReturn(['id' => 42, 'user_id' => null, 'meta' => null]);
        $tokenRepo->expects($this->once())->method('consumeToken')->with(42);

        $controller = $this->makeController(
            config: $this->makeConfig('invite'),
            entityTypeManager: $this->makeEntityTypeManager($storage),
            tokenRepo: $tokenRepo,
        );

        $response = $controller($this->makeRequest([
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => 'password123',
            'invite_token' => 'valid-invite-token',
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertTrue($data['data']['email_verified']);
    }

    #[Test]
    public function sends_verification_email_for_open_mode_with_mail_configured(): void
    {
        $_SESSION = [];
        $storage = $this->makeStorage(null);

        $tokenRepo = $this->createMock(AuthTokenRepositoryInterface::class);
        $tokenRepo->method('createToken')->willReturn('verify-token-abc');

        $mailer = $this->createMock(AuthMailer::class);
        $mailer->method('isConfigured')->willReturn(true);
        $mailer->expects($this->once())->method('sendEmailVerification');
        // sendWelcome() is void — no willReturn needed

        $controller = $this->makeController(
            config: $this->makeConfig('open'),
            entityTypeManager: $this->makeEntityTypeManager($storage),
            tokenRepo: $tokenRepo,
            authMailer: $mailer,
        );

        $response = $controller($this->makeRequest([
            'name' => 'Carol',
            'email' => 'carol@example.com',
            'password' => 'password123',
        ]));

        $this->assertSame(201, $response->getStatusCode());
    }

    #[Test]
    public function returns_429_when_rate_limit_exceeded(): void
    {
        $rateLimiter = new RateLimiter();
        $ip = '10.0.0.1';
        $key = 'register:' . $ip;

        // Pre-fill 5 hits so the 6th triggers the limit
        for ($i = 0; $i < 5; $i++) {
            $rateLimiter->hit($key, 900);
        }

        $controller = $this->makeController(
            config: $this->makeConfig('open'),
            rateLimiter: $rateLimiter,
        );

        $response = $controller($this->makeRequest([], $ip));

        $this->assertSame(429, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('too_many_attempts', $data['error']);
    }
}
