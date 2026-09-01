<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Auth\EmailVerificationTransaction;
use Waaseyaa\Auth\Tests\Support\AuthSchema;
use Waaseyaa\Auth\Tests\Support\MutableTestClock;
use Waaseyaa\Auth\Token\AuthTokenRepository;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Entity\Testing\StorageBackedStubRepository;
use Waaseyaa\Tests\Support\UserInternalFieldReaderFixture;
use Waaseyaa\User\User;

/**
 * Exercises the verification transaction against the real token repository.
 *
 * The controller's earlier validateToken() call is deliberately reproduced
 * here: these tests assert what happens when the facts it observed stop being
 * true before the transaction reaches its atomic consume.
 */
#[CoversClass(EmailVerificationTransaction::class)]
final class EmailVerificationTransactionTest extends TestCase
{
    private const string SECRET = 'abcdefghijklmnopqrstuvwxyz012345';

    #[Test]
    public function a_live_token_verifies_its_owner_and_is_consumed(): void
    {
        $database = DBALDatabase::createSqlite();
        AuthSchema::install($database);
        $tokens = new AuthTokenRepository($database, self::SECRET, new MutableTestClock());
        $plain = $tokens->createToken(42, 'email_verification', 3600);
        $validated = $tokens->validateToken($plain, 'email_verification');

        self::assertIsArray($validated);

        $user = new User(['uid' => 42, 'mail' => 'member@example.test']);
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->expects(self::once())->method('save')->with($user);

        $transaction = new EmailVerificationTransaction($database, $tokens);

        self::assertTrue($transaction->complete(
            new StorageBackedStubRepository($storage),
            $user,
            $validated['id'],
        ));
        self::assertTrue(new UserInternalFieldReaderFixture()->verification($user)->emailVerified);
    }

    #[Test]
    public function a_token_that_expires_between_validation_and_completion_is_refused(): void
    {
        $database = DBALDatabase::createSqlite();
        AuthSchema::install($database);
        $clock = new MutableTestClock();
        $tokens = new AuthTokenRepository($database, self::SECRET, $clock);
        $plain = $tokens->createToken(42, 'email_verification', 60);
        $validated = $tokens->validateToken($plain, 'email_verification');

        self::assertIsArray($validated);

        // Validation saw a live token. The clock then crosses expires_at before
        // the transaction runs — the exact race a pre-transaction expiry check
        // cannot close.
        $clock->advanceSeconds(61);

        $user = new User(['uid' => 42, 'mail' => 'member@example.test']);
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->expects(self::never())->method('save');

        $transaction = new EmailVerificationTransaction($database, $tokens);

        self::assertFalse($transaction->complete(
            new StorageBackedStubRepository($storage),
            $user,
            $validated['id'],
        ));
        self::assertFalse(new UserInternalFieldReaderFixture()->verification($user)->emailVerified);
        self::assertNull(self::consumedAt($database, $validated['id']));
    }

    #[Test]
    public function a_token_owned_by_another_user_cannot_verify_this_user(): void
    {
        $database = DBALDatabase::createSqlite();
        AuthSchema::install($database);
        $tokens = new AuthTokenRepository($database, self::SECRET, new MutableTestClock());
        $plain = $tokens->createToken(7, 'email_verification', 3600);
        $validated = $tokens->validateToken($plain, 'email_verification');

        self::assertIsArray($validated);

        // The consume is bound to the identity being mutated, so a token issued
        // to user 7 can never mark user 42 verified.
        $user = new User(['uid' => 42, 'mail' => 'other@example.test']);
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->expects(self::never())->method('save');

        $transaction = new EmailVerificationTransaction($database, $tokens);

        self::assertFalse($transaction->complete(
            new StorageBackedStubRepository($storage),
            $user,
            $validated['id'],
        ));
        self::assertFalse(new UserInternalFieldReaderFixture()->verification($user)->emailVerified);
        self::assertNull(self::consumedAt($database, $validated['id']));
    }

    #[Test]
    public function a_password_reset_token_cannot_be_spent_on_verification(): void
    {
        $database = DBALDatabase::createSqlite();
        AuthSchema::install($database);
        $tokens = new AuthTokenRepository($database, self::SECRET, new MutableTestClock());
        $plain = $tokens->createToken(42, 'password_reset', 3600);
        $validated = $tokens->validateToken($plain, 'password_reset');

        self::assertIsArray($validated);

        $user = new User(['uid' => 42, 'mail' => 'member@example.test']);
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->expects(self::never())->method('save');

        $transaction = new EmailVerificationTransaction($database, $tokens);

        self::assertFalse($transaction->complete(
            new StorageBackedStubRepository($storage),
            $user,
            $validated['id'],
        ));
        self::assertFalse(new UserInternalFieldReaderFixture()->verification($user)->emailVerified);
        self::assertNull(self::consumedAt($database, $validated['id']));
    }

    #[Test]
    public function only_the_first_completion_of_a_token_wins(): void
    {
        $database = DBALDatabase::createSqlite();
        AuthSchema::install($database);
        $tokens = new AuthTokenRepository($database, self::SECRET, new MutableTestClock());
        $plain = $tokens->createToken(42, 'email_verification', 3600);
        $validated = $tokens->validateToken($plain, 'email_verification');

        self::assertIsArray($validated);

        $winner = new User(['uid' => 42, 'mail' => 'member@example.test']);
        $loser = new User(['uid' => 42, 'mail' => 'member@example.test']);
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->expects(self::once())->method('save')->with($winner);
        $repository = new StorageBackedStubRepository($storage);

        $transaction = new EmailVerificationTransaction($database, $tokens);

        self::assertTrue($transaction->complete($repository, $winner, $validated['id']));
        self::assertFalse($transaction->complete($repository, $loser, $validated['id']));
        self::assertFalse(new UserInternalFieldReaderFixture()->verification($loser)->emailVerified);
    }

    private static function consumedAt(DBALDatabase $database, int $tokenId): ?int
    {
        $value = $database->getConnection()
            ->executeQuery('SELECT consumed_at FROM auth_tokens WHERE id = ?', [$tokenId])
            ->fetchOne();

        return $value === null || $value === false ? null : (int) $value;
    }
}
