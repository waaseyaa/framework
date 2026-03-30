<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Auth\Controller\ResetPasswordController;
use Waaseyaa\Auth\Token\AuthTokenRepositoryInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\User\User;

#[CoversClass(ResetPasswordController::class)]
final class ResetPasswordControllerTest extends TestCase
{
    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeStorage(mixed $user = null): EntityStorageInterface
    {
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('load')->willReturn($user);
        $storage->method('save')->willReturn(1);

        return $storage;
    }

    private function makeEntityTypeManager(?EntityStorageInterface $storage = null): EntityTypeManager
    {
        $manager = $this->createMock(EntityTypeManager::class);
        $manager->method('getStorage')->willReturn($storage ?? $this->makeStorage());

        return $manager;
    }

    private function makeTokenRepo(?array $tokenData = ['id' => 1, 'user_id' => 42, 'meta' => null]): AuthTokenRepositoryInterface
    {
        $repo = $this->createMock(AuthTokenRepositoryInterface::class);
        $repo->method('validateToken')->willReturn($tokenData);

        return $repo;
    }

    private function makeController(
        ?EntityTypeManager $entityTypeManager = null,
        ?AuthTokenRepositoryInterface $tokenRepo = null,
    ): ResetPasswordController {
        return new ResetPasswordController(
            entityTypeManager: $entityTypeManager ?? $this->makeEntityTypeManager(),
            tokenRepo: $tokenRepo ?? $this->makeTokenRepo(),
        );
    }

    private function makeRequest(array $body = []): Request
    {
        $request = Request::create(
            '/api/auth/reset-password',
            'POST',
            [],
            [],
            [],
            [],
            json_encode($body, JSON_THROW_ON_ERROR),
        );
        $request->headers->set('Content-Type', 'application/json');

        return $request;
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    #[Test]
    public function returns_422_when_password_too_short(): void
    {
        $controller = $this->makeController();

        $response = $controller($this->makeRequest([
            'token' => 'some-token',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('password_too_short', $data['error']);
    }

    #[Test]
    public function returns_422_when_passwords_dont_match(): void
    {
        $controller = $this->makeController();

        $response = $controller($this->makeRequest([
            'token' => 'some-token',
            'password' => 'password123',
            'password_confirmation' => 'different456',
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('passwords_do_not_match', $data['error']);
    }

    #[Test]
    public function returns_422_for_invalid_token(): void
    {
        $tokenRepo = $this->makeTokenRepo(null); // validateToken returns null

        $controller = $this->makeController(tokenRepo: $tokenRepo);

        $response = $controller($this->makeRequest([
            'token' => 'bad-or-expired-token',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('invalid_or_expired_token', $data['error']);
    }

    #[Test]
    public function returns_422_when_user_not_found(): void
    {
        $tokenRepo = $this->makeTokenRepo(['id' => 1, 'user_id' => 99, 'meta' => null]);
        $storage = $this->makeStorage(null); // user not found

        $controller = $this->makeController(
            entityTypeManager: $this->makeEntityTypeManager($storage),
            tokenRepo: $tokenRepo,
        );

        $response = $controller($this->makeRequest([
            'token' => 'valid-token',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('user_not_found', $data['error']);
    }

    #[Test]
    public function returns_200_on_successful_password_reset(): void
    {
        $_SESSION = [];

        $user = new User(['uid' => 42, 'mail' => 'user@example.com', 'name' => 'User']);
        $storage = $this->makeStorage($user);

        $tokenRepo = $this->createMock(AuthTokenRepositoryInterface::class);
        $tokenRepo->method('validateToken')->willReturn(['id' => 7, 'user_id' => 42, 'meta' => null]);
        $tokenRepo->expects($this->once())->method('consumeToken')->with(7);
        $tokenRepo->expects($this->once())->method('revokeTokensForUser')->with(42, 'password_reset');

        $controller = $this->makeController(
            entityTypeManager: $this->makeEntityTypeManager($storage),
            tokenRepo: $tokenRepo,
        );

        $response = $controller($this->makeRequest([
            'token' => 'valid-token',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertTrue($data['ok']);
        $this->assertStringContainsString('Password has been reset', $data['message']);
    }
}
