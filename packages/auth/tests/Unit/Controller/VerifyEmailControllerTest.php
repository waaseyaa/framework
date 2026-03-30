<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Auth\Controller\VerifyEmailController;
use Waaseyaa\Auth\Token\AuthTokenRepositoryInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

#[CoversClass(VerifyEmailController::class)]
final class VerifyEmailControllerTest extends TestCase
{
    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeTokenRepo(?array $tokenData = null): AuthTokenRepositoryInterface
    {
        $repo = $this->createMock(AuthTokenRepositoryInterface::class);
        $repo->method('validateToken')->willReturn($tokenData);

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

    private function jsonRequest(array $body): Request
    {
        return Request::create('/', 'POST', [], [], [], [], json_encode($body));
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    #[Test]
    public function returns_422_for_empty_token(): void
    {
        $controller = new VerifyEmailController(
            $this->makeEntityTypeManager(),
            $this->makeTokenRepo(),
        );

        $response = $controller($this->jsonRequest(['token' => '']));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('token_required', $data['error']);
    }

    #[Test]
    public function returns_422_for_invalid_token(): void
    {
        // validateToken returns null → invalid
        $controller = new VerifyEmailController(
            $this->makeEntityTypeManager(),
            $this->makeTokenRepo(null),
        );

        $response = $controller($this->jsonRequest(['token' => 'bad-token']));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('invalid_token', $data['error']);
    }

    #[Test]
    public function returns_422_when_user_not_found(): void
    {
        $tokenData = ['id' => 1, 'user_id' => 99, 'meta' => null];
        $tokenRepo = $this->makeTokenRepo($tokenData);

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('load')->willReturn(null);

        $controller = new VerifyEmailController(
            $this->makeEntityTypeManager($storage),
            $tokenRepo,
        );

        $response = $controller($this->jsonRequest(['token' => 'some-valid-token']));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('user_not_found', $data['error']);
    }

    #[Test]
    public function returns_200_and_marks_email_verified(): void
    {
        $tokenData = ['id' => 5, 'user_id' => 42, 'meta' => null];

        $tokenRepo = $this->createMock(AuthTokenRepositoryInterface::class);
        $tokenRepo->method('validateToken')->willReturn($tokenData);
        $tokenRepo->expects(self::once())->method('consumeToken')->with(5);
        $tokenRepo->expects(self::once())->method('revokeTokensForUser')->with(42, 'email_verification');

        $user = new \Waaseyaa\User\User(['uid' => 42, 'name' => 'Test', 'mail' => 'test@example.com']);

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('load')->willReturn($user);
        $storage->expects(self::once())->method('save')->with($user);

        $controller = new VerifyEmailController(
            $this->makeEntityTypeManager($storage),
            $tokenRepo,
        );

        $response = $controller($this->jsonRequest(['token' => 'valid-token']));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertTrue($data['ok']);
        self::assertTrue($user->isEmailVerified());
    }
}
