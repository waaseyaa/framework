<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Tests\Unit\Security;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Oidc\Security\OpaqueTokenProtector;
use Waaseyaa\Oidc\Security\SecretBoxEnvelope;
use Waaseyaa\Oidc\Tests\Support\OidcApplicationMasterKeyring;
use Waaseyaa\Oidc\Tests\Support\OidcSchema;
use Waaseyaa\Oidc\Token\AccessTokenIssuer;
use Waaseyaa\Oidc\Token\RefreshTokenIssuer;
use Waaseyaa\Oidc\Token\TokenCustodySequenceAllocator;

#[CoversClass(SecretBoxEnvelope::class)]
#[CoversClass(OpaqueTokenProtector::class)]
#[CoversClass(AccessTokenIssuer::class)]
#[CoversClass(RefreshTokenIssuer::class)]
#[CoversClass(TokenCustodySequenceAllocator::class)]
final class OidcApplicationMasterCustodyRetainedRedTest extends TestCase
{
    #[Test]
    public function signing_envelopes_write_the_active_version_and_bind_the_row_identity(): void
    {
        $predecessor = SecretBoxEnvelope::fromApplicationMasterKeyring(
            OidcApplicationMasterKeyring::create(1),
            ApplicationSecret::PURPOSE_OIDC_SIGNING_KEY_ENCRYPTION,
        );
        $predecessorEnvelope = $predecessor->seal('synthetic-private-pem', 'kid-1');
        $rotated = SecretBoxEnvelope::fromApplicationMasterKeyring(
            OidcApplicationMasterKeyring::create(2, [1]),
            ApplicationSecret::PURPOSE_OIDC_SIGNING_KEY_ENCRYPTION,
        );
        $successorEnvelope = $rotated->seal('synthetic-successor-pem', 'kid-2');

        self::assertSame(1, $rotated->applicationMasterVersion($predecessorEnvelope));
        self::assertSame(2, $rotated->applicationMasterVersion($successorEnvelope));
        self::assertSame('synthetic-private-pem', $rotated->open($predecessorEnvelope, 'kid-1'));
        self::assertSame('synthetic-successor-pem', $rotated->open($successorEnvelope, 'kid-2'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('identity');
        $rotated->open($predecessorEnvelope, 'kid-substituted');
    }

    #[Test]
    public function token_ciphertext_and_lookup_share_the_active_version_and_read_only_declared_versions(): void
    {
        $predecessor = OpaqueTokenProtector::fromApplicationMasterKeyring(
            OidcApplicationMasterKeyring::create(1),
            ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_ENCRYPTION,
            ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_LOOKUP,
        );
        $oldEnvelope = $predecessor->seal('synthetic-old-token', 'access-1');
        $oldLookup = $predecessor->lookup('synthetic-old-token');
        $rotated = OpaqueTokenProtector::fromApplicationMasterKeyring(
            OidcApplicationMasterKeyring::create(2, [1]),
            ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_ENCRYPTION,
            ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_LOOKUP,
        );
        $newEnvelope = $rotated->seal('synthetic-new-token', 'access-2');
        $newLookup = $rotated->lookup('synthetic-new-token');

        self::assertSame(1, $rotated->ciphertextVersion($oldEnvelope));
        self::assertSame(1, $rotated->lookupVersion($oldLookup));
        self::assertSame(2, $rotated->ciphertextVersion($newEnvelope));
        self::assertSame(2, $rotated->lookupVersion($newLookup));
        self::assertSame('synthetic-old-token', $rotated->open($oldEnvelope, 'access-1'));
        self::assertContains($oldLookup, $rotated->lookupCandidates('synthetic-old-token'));
        self::assertSame([$newLookup], array_values(array_filter(
            $rotated->lookupCandidates('synthetic-new-token'),
            static fn(string $candidate): bool => str_starts_with($candidate, 'v2:'),
        )));
    }

    #[Test]
    public function application_master_custody_requires_an_explicit_legacy_bridge(): void
    {
        $legacyEncryptionKey = hash('sha256', 'legacy-encryption', true);
        $legacyLookupKey = hash('sha256', 'legacy-lookup', true);
        $legacy = new OpaqueTokenProtector($legacyEncryptionKey, $legacyLookupKey);
        $legacyEnvelope = $legacy->seal('synthetic-legacy-token');
        $legacyLookup = $legacy->lookup('synthetic-legacy-token');
        $strict = OpaqueTokenProtector::fromApplicationMasterKeyring(
            OidcApplicationMasterKeyring::create(2, [1]),
            ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_ENCRYPTION,
            ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_LOOKUP,
        );

        try {
            $strict->open($legacyEnvelope, 'access-legacy');
            self::fail('Legacy ciphertext must not be accepted implicitly.');
        } catch (\RuntimeException $failure) {
            self::assertSame('OIDC encrypted material could not be authenticated.', $failure->getMessage());
        }
        self::assertNotContains($legacyLookup, $strict->lookupCandidates('synthetic-legacy-token'));

        $compatibility = OpaqueTokenProtector::fromApplicationMasterKeyring(
            OidcApplicationMasterKeyring::create(2, [1]),
            ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_ENCRYPTION,
            ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_LOOKUP,
            $legacyEncryptionKey,
            $legacyLookupKey,
        );
        self::assertSame('synthetic-legacy-token', $compatibility->open($legacyEnvelope, 'access-legacy'));
        self::assertContains($legacyLookup, $compatibility->lookupCandidates('synthetic-legacy-token'));
    }

    #[Test]
    public function custody_diagnostics_and_serialization_do_not_export_legacy_keys(): void
    {
        $legacyEncryptionKey = hash('sha256', 'legacy-encryption-canary', true);
        $legacyLookupKey = hash('sha256', 'legacy-lookup-canary', true);
        $custody = OpaqueTokenProtector::fromApplicationMasterKeyring(
            OidcApplicationMasterKeyring::create(2, [1]),
            ApplicationSecret::PURPOSE_OIDC_REFRESH_TOKEN_ENCRYPTION,
            ApplicationSecret::PURPOSE_OIDC_REFRESH_TOKEN_LOOKUP,
            $legacyEncryptionKey,
            $legacyLookupKey,
        );
        $diagnostic = print_r($custody, true);

        self::assertStringContainsString('[NON_EXPORTING]', $diagnostic);
        self::assertStringContainsString('[REDACTED]', $diagnostic);
        self::assertStringNotContainsString($legacyEncryptionKey, $diagnostic);
        self::assertStringNotContainsString($legacyLookupKey, $diagnostic);

        $this->expectException(\LogicException::class);
        serialize($custody);
    }

    #[Test]
    public function token_issuers_write_successor_only_and_read_a_declared_predecessor(): void
    {
        $database = DBALDatabase::createSqlite();
        OidcSchema::installTokenStorage($database);
        $now = new DateTimeImmutable('@1700000000');
        $predecessor = new AccessTokenIssuer(
            $database,
            null,
            null,
            OidcApplicationMasterKeyring::create(1),
        );
        $old = $predecessor->issue('client', 'account', ['openid'], $now);
        $successor = new AccessTokenIssuer(
            $database,
            null,
            null,
            OidcApplicationMasterKeyring::create(2, [1]),
        );
        $new = $successor->issue('client', 'account', ['openid'], $now);
        $refresh = new RefreshTokenIssuer(
            $database,
            null,
            null,
            OidcApplicationMasterKeyring::create(2, [1]),
        );
        $refreshRecord = $refresh->issue(
            $new->jti,
            'client',
            'account',
            ['openid'],
            $now->getTimestamp(),
            $now,
        );

        $rows = $database->getConnection()->fetchAllAssociative(
            'SELECT jti, token, token_lookup, custody_sequence FROM oidc_access_token ORDER BY custody_sequence',
        );
        self::assertSame([1, 2], array_map(static fn(array $row): int => (int) $row['custody_sequence'], $rows));
        self::assertSame(1, json_decode((string) $rows[0]['token'], true, 32, JSON_THROW_ON_ERROR)['master_version']);
        self::assertSame(2, json_decode((string) $rows[1]['token'], true, 32, JSON_THROW_ON_ERROR)['master_version']);
        self::assertStringStartsWith('v1:', (string) $rows[0]['token_lookup']);
        self::assertStringStartsWith('v2:', (string) $rows[1]['token_lookup']);
        self::assertSame($old->jti, $successor->findByOpaqueToken($old->token)['jti'] ?? null);
        self::assertSame($new->jti, $successor->findByOpaqueToken($new->token)['jti'] ?? null);
        self::assertSame($refreshRecord->jti, $refresh->findByToken($refreshRecord->token)?->jti);
    }

    #[Test]
    public function refused_token_insert_rolls_back_its_sequence_allocation(): void
    {
        $database = DBALDatabase::createSqlite();
        OidcSchema::installTokenStorage($database);
        $database->getConnection()->executeStatement(
            "CREATE TRIGGER refuse_access_token BEFORE INSERT ON oidc_access_token BEGIN SELECT RAISE(ABORT, 'synthetic refusal'); END",
        );
        $issuer = new AccessTokenIssuer(
            $database,
            null,
            null,
            OidcApplicationMasterKeyring::create(2, [1]),
        );

        try {
            $issuer->issue('client', 'account', ['openid'], new DateTimeImmutable('@1700000000'));
            self::fail('The synthetic insert refusal must escape the issue transaction.');
        } catch (\Throwable $failure) {
            self::assertStringContainsString('synthetic refusal', $failure->getMessage());
        }

        self::assertSame(1, (int) $database->getConnection()->fetchOne(
            "SELECT next_sequence FROM oidc_token_custody_sequence WHERE token_kind = 'access'",
        ));
        self::assertSame(0, (int) $database->getConnection()->fetchOne('SELECT COUNT(*) FROM oidc_access_token'));
    }
}
