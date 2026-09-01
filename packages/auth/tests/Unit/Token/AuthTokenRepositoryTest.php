<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit\Token;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Auth\Tests\Support\AuthSchema;
use Waaseyaa\Auth\Tests\Support\MutableTestClock;
use Waaseyaa\Auth\Token\AuthTokenRepository;
use Waaseyaa\Database\DBALDatabase;

#[CoversClass(AuthTokenRepository::class)]
final class AuthTokenRepositoryTest extends TestCase
{
    private const string SECRET = 'abcdefghijklmnopqrstuvwxyz012345';

    #[Test]
    public function migrated_schema_is_verified_and_usable_end_to_end(): void
    {
        $database = DBALDatabase::createSqlite();
        AuthSchema::install($database);
        $secret = 'abcdefghijklmnopqrstuvwxyz012345';
        $repo = new AuthTokenRepository($database, $secret);

        $before = $database->getConnection()->executeQuery(
            'SELECT sql FROM sqlite_master WHERE sql IS NOT NULL ORDER BY type, name',
        )->fetchFirstColumn();

        $repo->ensureSchema();
        $repo->ensureSchema();
        $token = $repo->createToken(7, 'reset', 3600);
        $validated = $repo->validateToken($token, 'reset');

        self::assertIsArray($validated);
        self::assertSame('7', $validated['user_id']);
        self::assertSame($before, $database->getConnection()->executeQuery(
            'SELECT sql FROM sqlite_master WHERE sql IS NOT NULL ORDER BY type, name',
        )->fetchFirstColumn());
        self::assertStringNotContainsString($secret, print_r($repo, true));

        try {
            serialize($repo);
            self::fail('Auth token HMAC keys were serialized.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('cannot be serialized', $exception->getMessage());
        }
    }

    #[Test]
    public function incomplete_schema_is_refused_without_repair(): void
    {
        $database = DBALDatabase::createSqlite();
        $database->getConnection()->executeStatement(
            'CREATE TABLE auth_tokens (id INTEGER PRIMARY KEY, token_hash TEXT NOT NULL)',
        );
        $repo = new AuthTokenRepository($database, 'secret');

        try {
            $repo->ensureSchema();
            self::fail('An incomplete auth token schema was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('S1-DB106', $exception->getMessage());
            self::assertStringContainsString('user_id', $exception->getMessage());
        }

        self::assertFalse($database->schema()->fieldExists('auth_tokens', 'user_id'));
    }

    #[Test]
    public function available_token_can_be_consumed_exactly_once(): void
    {
        $database = DBALDatabase::createSqlite();
        AuthSchema::install($database);
        $repo = new AuthTokenRepository($database, self::SECRET);
        $token = $repo->createToken(7, 'email_verification', 3600);
        $tokenId = $repo->validateToken($token, 'email_verification')['id'];

        // Single-winner: the conditional write matches exactly one row, once.
        self::assertTrue($repo->consumeTokenIfAvailable($tokenId, 'email_verification', 7));
        self::assertFalse($repo->consumeTokenIfAvailable($tokenId, 'email_verification', 7));
    }

    #[Test]
    public function consumption_stamps_the_instant_that_proved_the_token_live(): void
    {
        $database = DBALDatabase::createSqlite();
        AuthSchema::install($database);
        $clock = new MutableTestClock();
        $repo = new AuthTokenRepository($database, self::SECRET, $clock);
        $token = $repo->createToken(7, 'email_verification', 3600);
        $tokenId = $repo->validateToken($token, 'email_verification')['id'];

        $clock->advanceSeconds(120);

        self::assertTrue($repo->consumeTokenIfAvailable($tokenId, 'email_verification', 7));
        // One clock read serves the whole operation: the instant that satisfied
        // the expiry predicate is the instant written to consumed_at, so the
        // row can never be stamped consumed later than its own liveness check.
        self::assertSame($clock->now()->getTimestamp(), self::consumedAt($database, $tokenId));
    }

    #[Test]
    public function a_token_that_expires_after_validation_is_refused_and_left_unconsumed(): void
    {
        $database = DBALDatabase::createSqlite();
        AuthSchema::install($database);
        $clock = new MutableTestClock();
        $repo = new AuthTokenRepository($database, self::SECRET, $clock);
        $token = $repo->createToken(7, 'email_verification', 60);
        $validated = $repo->validateToken($token, 'email_verification');

        self::assertIsArray($validated);
        $tokenId = $validated['id'];

        // The token was live when it was validated; the clock then crosses
        // expires_at before anything reaches the consume. That window is what
        // a pre-transaction expiry check leaves open.
        $clock->advanceSeconds(61);

        self::assertNull($repo->validateToken($token, 'email_verification'));
        self::assertFalse($repo->consumeTokenIfAvailable($tokenId, 'email_verification', 7));
        self::assertNull(self::consumedAt($database, $tokenId));
    }

    #[Test]
    public function validation_and_consumption_share_one_expiry_boundary(): void
    {
        $database = DBALDatabase::createSqlite();
        AuthSchema::install($database);
        $clock = new MutableTestClock();
        $repo = new AuthTokenRepository($database, self::SECRET, $clock);
        $token = $repo->createToken(7, 'email_verification', 60);
        $tokenId = $repo->validateToken($token, 'email_verification')['id'];

        // The exact instant validateToken() stops accepting a token is the
        // instant the atomic consume stops accepting it — the consume never
        // holds a boundary the rest of the flow does not agree with.
        $clock->advanceSeconds(60);
        self::assertNull($repo->validateToken($token, 'email_verification'));
        self::assertFalse($repo->consumeTokenIfAvailable($tokenId, 'email_verification', 7));

        $clock->advanceSeconds(-1);
        self::assertIsArray($repo->validateToken($token, 'email_verification'));
        self::assertTrue($repo->consumeTokenIfAvailable($tokenId, 'email_verification', 7));
    }

    #[Test]
    public function a_token_of_another_type_cannot_be_consumed(): void
    {
        $database = DBALDatabase::createSqlite();
        AuthSchema::install($database);
        $repo = new AuthTokenRepository($database, self::SECRET);
        $token = $repo->createToken(7, 'password_reset', 3600);
        $tokenId = $repo->validateToken($token, 'password_reset')['id'];

        self::assertFalse($repo->consumeTokenIfAvailable($tokenId, 'email_verification', 7));
        self::assertNull(self::consumedAt($database, $tokenId));
    }

    #[Test]
    public function a_token_owned_by_another_identity_cannot_be_consumed(): void
    {
        $database = DBALDatabase::createSqlite();
        AuthSchema::install($database);
        $repo = new AuthTokenRepository($database, self::SECRET);
        $token = $repo->createToken(7, 'email_verification', 3600);
        $tokenId = $repo->validateToken($token, 'email_verification')['id'];

        self::assertFalse($repo->consumeTokenIfAvailable($tokenId, 'email_verification', 8));
        // NULL is the unowned-invite identity, not a wildcard.
        self::assertFalse($repo->consumeTokenIfAvailable($tokenId, 'email_verification', null));
        self::assertNull(self::consumedAt($database, $tokenId));

        // The owning identity still matches, in either scalar form, because the
        // predicate compares the same string createToken() persisted.
        self::assertTrue($repo->consumeTokenIfAvailable($tokenId, 'email_verification', '7'));
    }

    #[Test]
    public function an_unowned_invite_token_is_consumable_only_by_the_null_identity(): void
    {
        $database = DBALDatabase::createSqlite();
        AuthSchema::install($database);
        $repo = new AuthTokenRepository($database, self::SECRET);
        $token = $repo->createToken(null, 'invite', 3600);
        $tokenId = $repo->validateToken($token, 'invite')['id'];

        self::assertFalse($repo->consumeTokenIfAvailable($tokenId, 'invite', 7));
        self::assertNull(self::consumedAt($database, $tokenId));
        self::assertTrue($repo->consumeTokenIfAvailable($tokenId, 'invite', null));
    }

    private static function consumedAt(DBALDatabase $database, int $tokenId): ?int
    {
        $value = $database->getConnection()
            ->executeQuery('SELECT consumed_at FROM auth_tokens WHERE id = ?', [$tokenId])
            ->fetchOne();

        return $value === null || $value === false ? null : (int) $value;
    }
}
