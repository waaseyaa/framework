<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Tests\Integration\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Oidc\Key\SigningKeyRepository;
use Waaseyaa\Oidc\Security\LegacyOidcSecretMigrator;
use Waaseyaa\Oidc\Security\SecretBoxEnvelope;
use Waaseyaa\Oidc\Token\AccessTokenIssuer;
use Waaseyaa\Oidc\Token\RefreshTokenIssuer;

final class OidcSecretStorageTest extends TestCase
{
    #[Test]
    public function signing_private_keys_are_stored_in_a_versioned_encrypted_envelope(): void
    {
        $db = DBALDatabase::createSqlite();
        $repository = new SigningKeyRepository($db, random_bytes(32));

        $key = $repository->rotate();
        $stored = (string) $db->getConnection()->fetchOne('SELECT private_key_pem FROM oidc_signing_key');

        self::assertStringStartsWith('secretbox.hkdf-v1:', $stored);
        self::assertStringNotContainsString((string) $key->privateKeyPem, $stored);
        self::assertSame($key->privateKeyPem, $repository->currentKey()->privateKeyPem);
    }

    #[Test]
    public function opaque_tokens_use_encrypted_storage_and_purpose_bound_lookup_values(): void
    {
        $db = DBALDatabase::createSqlite();
        $access = new AccessTokenIssuer($db, random_bytes(32), random_bytes(32));
        $refresh = new RefreshTokenIssuer($db, random_bytes(32), random_bytes(32));
        $now = new \DateTimeImmutable('@1700000000');

        $accessPair = $access->issue('client', 'account', ['openid'], $now);
        $refreshRecord = $refresh->issue(
            $accessPair->jti,
            'client',
            'account',
            ['openid'],
            $now->getTimestamp(),
            $now,
        );

        $accessRow = $db->getConnection()->fetchAssociative('SELECT token, token_lookup FROM oidc_access_token');
        $refreshRow = $db->getConnection()->fetchAssociative('SELECT token, token_lookup FROM oidc_refresh_token');
        self::assertIsArray($accessRow);
        self::assertIsArray($refreshRow);
        self::assertStringStartsWith('secretbox.hkdf-v1:', (string) $accessRow['token']);
        self::assertStringStartsWith('secretbox.hkdf-v1:', (string) $refreshRow['token']);
        self::assertStringNotContainsString($accessPair->token, (string) $accessRow['token']);
        self::assertStringNotContainsString($refreshRecord->token, (string) $refreshRow['token']);
        self::assertNotSame($accessRow['token_lookup'], $refreshRow['token_lookup']);
        self::assertSame($accessPair->jti, $access->findByOpaqueToken($accessPair->token)['jti'] ?? null);
        self::assertSame($refreshRecord->jti, $refresh->findByToken($refreshRecord->token)?->jti);
    }

    #[Test]
    public function invalid_envelopes_fail_loudly(): void
    {
        $db = DBALDatabase::createSqlite();
        $repository = new SigningKeyRepository($db, random_bytes(32));
        $repository->rotate();
        $db->getConnection()->executeStatement(
            "UPDATE oidc_signing_key SET private_key_pem = 'secretbox.hkdf-v1:invalid'",
        );

        $this->expectException(\RuntimeException::class);
        $repository->currentKey();
    }

    #[Test]
    public function explicit_migration_converts_existing_secret_rows_transactionally(): void
    {
        $db = DBALDatabase::createSqlite();
        $connection = $db->getConnection();
        $connection->executeStatement('CREATE TABLE oidc_signing_key (kid VARCHAR(36) PRIMARY KEY, algorithm VARCHAR(16), private_key_pem TEXT, public_key_pem TEXT, created_at INTEGER, rotated_out_at INTEGER)');
        $connection->executeStatement('CREATE TABLE oidc_access_token (jti VARCHAR(128) PRIMARY KEY, token VARCHAR(128), client_id VARCHAR(255), account_id VARCHAR(255), scope TEXT, issued_at INTEGER, expires_at INTEGER, revoked_at INTEGER)');
        $connection->executeStatement('CREATE TABLE oidc_refresh_token (jti VARCHAR(128) PRIMARY KEY, token VARCHAR(128), access_token_jti VARCHAR(128), client_id VARCHAR(255), account_id VARCHAR(255), scope TEXT, auth_time INTEGER, chain_root_jti VARCHAR(128), issued_at INTEGER, expires_at INTEGER, revoked_at INTEGER)');

        $keyPair = new \Waaseyaa\Oidc\Keys\OpenSslKeyFactory()->generateRsaKeyPair();
        $connection->insert('oidc_signing_key', ['kid' => 'legacy', 'algorithm' => 'RS256', 'private_key_pem' => $keyPair['private'], 'public_key_pem' => $keyPair['public'], 'created_at' => 1, 'rotated_out_at' => null]);
        $connection->insert('oidc_access_token', ['jti' => 'access', 'token' => 'legacy-access', 'client_id' => 'client', 'account_id' => 'account', 'scope' => 'openid', 'issued_at' => 1, 'expires_at' => 2, 'revoked_at' => null]);
        $connection->insert('oidc_refresh_token', ['jti' => 'refresh', 'token' => 'legacy-refresh', 'access_token_jti' => 'access', 'client_id' => 'client', 'account_id' => 'account', 'scope' => 'openid', 'auth_time' => 1, 'chain_root_jti' => 'refresh', 'issued_at' => 1, 'expires_at' => 2, 'revoked_at' => null]);

        $keys = [random_bytes(32), random_bytes(32), random_bytes(32), random_bytes(32), random_bytes(32)];
        $counts = new LegacyOidcSecretMigrator($db, ...$keys)->migrate();

        self::assertSame(['signing_keys' => 1, 'access_tokens' => 1, 'refresh_tokens' => 1], $counts);
        self::assertSame($keyPair['private'], (new SigningKeyRepository($db, $keys[0]))->currentKey()->privateKeyPem);
        self::assertSame('access', (new AccessTokenIssuer($db, $keys[1], $keys[2]))->findByOpaqueToken('legacy-access')['jti'] ?? null);
        self::assertSame('refresh', (new RefreshTokenIssuer($db, $keys[3], $keys[4]))->findByToken('legacy-refresh')?->jti);
    }

    #[Test]
    public function key_and_plaintext_material_are_absent_from_object_and_failure_surfaces(): void
    {
        $key = random_bytes(32);
        $plaintext = 'private-material-' . bin2hex(random_bytes(8));
        $envelope = new SecretBoxEnvelope($key);
        $sealed = $envelope->seal($plaintext);

        ob_start();
        var_dump($envelope);
        $debug = (string) ob_get_clean() . var_export($envelope, true);
        self::assertStringNotContainsString($key, $debug);
        self::assertStringNotContainsString($plaintext, $sealed);

        try {
            $envelope->open('secretbox.hkdf-v1:invalid');
            self::fail('Invalid encrypted material must be rejected.');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString($key, $e->getMessage());
            self::assertStringNotContainsString($plaintext, $e->getMessage());
        }

        $this->expectException(\LogicException::class);
        serialize($envelope);
    }
}
