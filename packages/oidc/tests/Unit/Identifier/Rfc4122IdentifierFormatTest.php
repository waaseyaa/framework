<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Tests\Unit\Identifier;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Oidc\Key\SigningKeyRepository;
use Waaseyaa\Oidc\Tests\Support\OidcSchema;
use Waaseyaa\Oidc\Token\AccessTokenIssuer;
use Waaseyaa\Oidc\Token\RefreshTokenIssuer;

/**
 * Locks the RFC 4122 version-4 shape of the identifiers OIDC mints.
 *
 * Access-token jti, refresh-token jti, and signing-key kid values were
 * produced by three hand-rolled `random_bytes` generators before #2492
 * replaced them with `Symfony\Component\Uid\Uuid::v4()->toRfc4122()`. The
 * two implementations are format-identical, and these assertions are what
 * makes that claim checkable rather than asserted.
 */
#[CoversClass(AccessTokenIssuer::class)]
#[CoversClass(RefreshTokenIssuer::class)]
#[CoversClass(SigningKeyRepository::class)]
final class Rfc4122IdentifierFormatTest extends TestCase
{
    #[Test]
    public function access_token_jti_is_an_rfc_4122_version_4_uuid(): void
    {
        $database = $this->tokenDatabase();
        $issuer = new AccessTokenIssuer($database, random_bytes(32), random_bytes(32));

        $pair = $issuer->issue('client', 'account', ['openid'], new DateTimeImmutable('@1700000000'));

        $this->assertRfc4122V4($pair->jti);
    }

    #[Test]
    public function refresh_token_jti_is_an_rfc_4122_version_4_uuid(): void
    {
        $database = $this->tokenDatabase();
        $issuer = new RefreshTokenIssuer($database, random_bytes(32), random_bytes(32));

        $record = $issuer->issue(
            'access-jti',
            'client',
            'account',
            ['openid'],
            1700000000,
            new DateTimeImmutable('@1700000000'),
        );

        $this->assertRfc4122V4($record->jti);
        $this->assertRfc4122V4($record->chainRootJti);
    }

    #[Test]
    public function signing_key_kid_is_an_rfc_4122_version_4_uuid(): void
    {
        $database = DBALDatabase::createSqlite();
        OidcSchema::installSigningKeys($database);
        $repository = new SigningKeyRepository($database, random_bytes(32));

        $key = $repository->initialize();

        $this->assertRfc4122V4($key->kid);
    }

    #[Test]
    public function successive_identifiers_are_distinct(): void
    {
        $database = $this->tokenDatabase();
        $issuer = new AccessTokenIssuer($database, random_bytes(32), random_bytes(32));
        $now = new DateTimeImmutable('@1700000000');

        $first = $issuer->issue('client', 'account', ['openid'], $now);
        $second = $issuer->issue('client', 'account', ['openid'], $now);

        self::assertNotSame($first->jti, $second->jti);
    }

    private function assertRfc4122V4(string $value): void
    {
        self::assertSame(36, strlen($value), 'RFC 4122 canonical form is 36 characters.');
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $value,
            'RFC 4122 canonical form is lowercase hex in 8-4-4-4-12 groups.',
        );
        self::assertSame('4', $value[14], 'Version nibble must be 4.');
        self::assertContains(
            $value[19],
            ['8', '9', 'a', 'b'],
            'Variant bits must be 10x (leading nibble 8, 9, a, or b).',
        );
    }

    private function tokenDatabase(): DBALDatabase
    {
        $database = DBALDatabase::createSqlite();
        OidcSchema::installTokenStorage($database);

        return $database;
    }
}
