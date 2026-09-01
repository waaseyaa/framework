<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Auth\Controller\VerifyEmailController;
use Waaseyaa\Auth\EmailVerificationTransaction;
use Waaseyaa\Auth\Token\AuthTokenRepositoryInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Entity\Testing\StorageBackedStubRepository;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\TransactionInterface;

#[CoversClass(VerifyEmailController::class)]
#[CoversClass(EmailVerificationTransaction::class)]
final class VerifyEmailControllerTest extends TestCase
{
    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeTokenRepo(?array $tokenData = null): AuthTokenRepositoryInterface
    {
        $repo = $this->createStub(AuthTokenRepositoryInterface::class);
        $repo->method('validateToken')->willReturn($tokenData);

        return $repo;
    }

    private function makeEntityTypeManager(?EntityStorageInterface $storage = null): EntityTypeManager
    {
        $manager = $this->createStub(EntityTypeManager::class);
        if ($storage !== null) {
            $manager->method('getStorage')->willReturn($storage);
            // C-22 WP3: read/write path now goes through the canonical repository.
            $manager->method('getRepository')->willReturn(new StorageBackedStubRepository($storage));
        }

        return $manager;
    }

    private function jsonRequest(array $body): Request
    {
        return Request::create('/', 'POST', [], [], [], [], json_encode($body));
    }

    private function database(): DatabaseInterface
    {
        $transaction = $this->createStub(TransactionInterface::class);
        $database = $this->createStub(DatabaseInterface::class);
        $database->method('transaction')->willReturn($transaction);
        return $database;
    }

    private function transactionService(
        AuthTokenRepositoryInterface $tokens,
        ?DatabaseInterface $database = null,
    ): EmailVerificationTransaction {
        return new EmailVerificationTransaction($database ?? $this->database(), $tokens);
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    #[Test]
    public function returns_422_for_empty_token(): void
    {
        $tokens = $this->makeTokenRepo();
        $controller = new VerifyEmailController($this->makeEntityTypeManager(), $tokens, $this->transactionService($tokens));

        $response = $controller($this->jsonRequest(['token' => '']));

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('token_required', $data['error']);
    }

    #[Test]
    public function returns_422_for_invalid_token(): void
    {
        // validateToken returns null → invalid
        $tokens = $this->makeTokenRepo(null);
        $controller = new VerifyEmailController($this->makeEntityTypeManager(), $tokens, $this->transactionService($tokens));

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

        $storage = $this->createStub(EntityStorageInterface::class);
        $storage->method('load')->willReturn(null);

        $controller = new VerifyEmailController(
            $this->makeEntityTypeManager($storage),
            $tokenRepo,
            $this->transactionService($tokenRepo),
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
        // The consume carries the token type and the identity of the User this
        // request is about to mark verified, not just the token id.
        $tokenRepo->expects(self::once())
            ->method('consumeTokenIfAvailable')
            ->with(5, 'email_verification', 42)
            ->willReturn(true);
        $tokenRepo->expects(self::once())->method('revokeTokensForUser')->with(42, 'email_verification');

        $user = new \Waaseyaa\User\User(['uid' => 42, 'name' => 'Test', 'mail' => 'test@example.com']);

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('load')->willReturn($user);
        $storage->expects(self::once())->method('save')->with($user);

        $controller = new VerifyEmailController(
            $this->makeEntityTypeManager($storage),
            $tokenRepo,
            $this->transactionService($tokenRepo),
        );

        $response = $controller($this->jsonRequest(['token' => 'valid-token']));

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertTrue($data['ok']);
        self::assertTrue(new \Waaseyaa\Tests\Support\UserInternalFieldReaderFixture()->verification($user)->emailVerified);
    }

    #[Test]
    public function token_failure_rolls_back_the_shared_verification_transaction(): void
    {
        $tokenRepo = $this->createStub(AuthTokenRepositoryInterface::class);
        $tokenRepo->method('validateToken')->willReturn(['id' => 5, 'user_id' => 42, 'meta' => null]);
        $tokenRepo->method('consumeTokenIfAvailable')->willThrowException(new \RuntimeException('write failed'));
        $user = new \Waaseyaa\User\User(['uid' => 42, 'mail' => 'test@example.com']);
        $storage = $this->createStub(EntityStorageInterface::class);
        $storage->method('load')->willReturn($user);

        $transaction = $this->createMock(TransactionInterface::class);
        $transaction->expects(self::never())->method('commit');
        $transaction->expects(self::once())->method('rollBack');
        $database = $this->createStub(DatabaseInterface::class);
        $database->method('transaction')->willReturn($transaction);
        $controller = new VerifyEmailController(
            $this->makeEntityTypeManager($storage),
            $tokenRepo,
            $this->transactionService($tokenRepo, $database),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('write failed');
        $controller($this->jsonRequest(['token' => 'valid-token']));
    }

    #[Test]
    public function concurrent_token_consumption_returns_the_normal_invalid_token_response(): void
    {
        $tokenRepo = $this->createMock(AuthTokenRepositoryInterface::class);
        $tokenRepo->method('validateToken')->willReturn(['id' => 5, 'user_id' => 42, 'meta' => null]);
        $tokenRepo->expects(self::once())
            ->method('consumeTokenIfAvailable')
            ->with(5, 'email_verification', 42)
            ->willReturn(false);
        $tokenRepo->expects(self::never())->method('revokeTokensForUser');

        $user = new \Waaseyaa\User\User(['uid' => 42, 'mail' => 'test@example.com']);
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('load')->willReturn($user);
        $storage->expects(self::never())->method('save');

        $transaction = $this->createMock(TransactionInterface::class);
        $transaction->expects(self::never())->method('commit');
        $transaction->expects(self::once())->method('rollBack');
        $database = $this->createStub(DatabaseInterface::class);
        $database->method('transaction')->willReturn($transaction);
        $controller = new VerifyEmailController(
            $this->makeEntityTypeManager($storage),
            $tokenRepo,
            $this->transactionService($tokenRepo, $database),
        );

        $response = $controller($this->jsonRequest(['token' => 'valid-token']));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['error' => 'invalid_token'], json_decode((string) $response->getContent(), true));
        self::assertFalse(new \Waaseyaa\Tests\Support\UserInternalFieldReaderFixture()->verification($user)->emailVerified);
    }
}
