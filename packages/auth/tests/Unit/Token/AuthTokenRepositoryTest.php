<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit\Token;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Auth\Tests\Support\AuthSchema;
use Waaseyaa\Auth\Token\AuthTokenRepository;
use Waaseyaa\Database\DBALDatabase;

#[CoversClass(AuthTokenRepository::class)]
final class AuthTokenRepositoryTest extends TestCase
{
    #[Test]
    public function migrated_schema_is_verified_and_usable_end_to_end(): void
    {
        $database = DBALDatabase::createSqlite();
        AuthSchema::install($database);
        $repo = new AuthTokenRepository($database, 'secret');

        $before = $database->getConnection()->executeQuery(
            "SELECT sql FROM sqlite_master WHERE sql IS NOT NULL ORDER BY type, name",
        )->fetchFirstColumn();

        $repo->ensureSchema();
        $repo->ensureSchema();
        $token = $repo->createToken(7, 'reset', 3600);
        $validated = $repo->validateToken($token, 'reset');

        self::assertIsArray($validated);
        self::assertSame('7', $validated['user_id']);
        self::assertSame($before, $database->getConnection()->executeQuery(
            "SELECT sql FROM sqlite_master WHERE sql IS NOT NULL ORDER BY type, name",
        )->fetchFirstColumn());
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
}
