<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Tests\Unit\Rekey;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeRegistry;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyCoordinator;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyConflictException;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyContext;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyRecord;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyRequest;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyState;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyStore;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Oidc\Key\SigningKeyRepository;
use Waaseyaa\Oidc\OidcServiceProvider;
use Waaseyaa\Oidc\Rekey\OidcAccessTokenRekeyAdapter;
use Waaseyaa\Oidc\Rekey\OidcRefreshTokenRekeyAdapter;
use Waaseyaa\Oidc\Rekey\OidcSigningKeyRekeyAdapter;
use Waaseyaa\Oidc\Security\OpaqueTokenProtector;
use Waaseyaa\Oidc\Tests\Support\OidcApplicationMasterKeyring;
use Waaseyaa\Oidc\Tests\Support\OidcSchema;
use Waaseyaa\Oidc\Token\AccessTokenIssuer;
use Waaseyaa\Oidc\Token\RefreshTokenIssuer;

/** Retained-red proof for all three database-authoritative OIDC purpose owners. */
final class OidcApplicationMasterRekeyAdaptersRetainedRedTest extends TestCase
{
    #[Test]
    public function access_batches_jointly_transition_ciphertext_and_lookup_and_preserve_revocation(): void
    {
        $database = $this->tokenDatabase();
        $encryptionKey = hash('sha256', 'legacy-access-encryption', true);
        $lookupKey = hash('sha256', 'legacy-access-lookup', true);
        $issuer = new AccessTokenIssuer($database, $encryptionKey, $lookupKey);
        $now = new DateTimeImmutable('@1700000000');
        $first = $issuer->issue('client', 'account', ['openid'], $now);
        $second = $issuer->issue('client', 'account', ['openid'], $now);
        $issuer->revoke($first->jti, $now);
        $adapter = new OidcAccessTokenRekeyAdapter($database, $encryptionKey, $lookupKey);
        $context = $this->forwardContext($database);

        $snapshot = $adapter->snapshot($context);
        $batch1 = $adapter->transitionBatch($context, $snapshot, null, 1);
        $batch2 = $adapter->transitionBatch($context, $snapshot, $batch1->nextCursor, 1);
        $verification = $adapter->verify($context, $snapshot);

        self::assertSame(2, $snapshot->totalRecords);
        self::assertSame('offset:1', $batch1->nextCursor);
        self::assertSame('offset:2', $batch2->nextCursor);
        self::assertSame([
            ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_ENCRYPTION => 1,
            ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_LOOKUP => 1,
        ], $batch1->purposeCountDeltas);
        self::assertSame(2, $verification[ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_ENCRYPTION]->verifiedRecords);
        self::assertSame(2, $verification[ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_LOOKUP]->verifiedRecords);

        $rows = $database->getConnection()->fetchAllAssociative(
            'SELECT jti, token, token_lookup, revoked_at FROM oidc_access_token ORDER BY custody_sequence',
        );
        foreach ($rows as $row) {
            self::assertSame(2, json_decode((string) $row['token'], true, 32, JSON_THROW_ON_ERROR)['master_version']);
            self::assertStringStartsWith('v2:', (string) $row['token_lookup']);
        }
        self::assertSame($now->getTimestamp(), (int) $rows[0]['revoked_at']);
        $strict = new AccessTokenIssuer($database, null, null, OidcApplicationMasterKeyring::create(2, [1]));
        self::assertSame($first->jti, $strict->findByOpaqueToken($first->token)['jti'] ?? null);
        self::assertSame($second->jti, $strict->findByOpaqueToken($second->token)['jti'] ?? null);
    }

    #[Test]
    public function refresh_transition_rolls_back_to_predecessor_without_resurrecting_revoked_rows(): void
    {
        $database = $this->tokenDatabase();
        $encryptionKey = hash('sha256', 'legacy-refresh-encryption', true);
        $lookupKey = hash('sha256', 'legacy-refresh-lookup', true);
        $access = new AccessTokenIssuer(
            $database,
            hash('sha256', 'legacy-access-encryption', true),
            hash('sha256', 'legacy-access-lookup', true),
        )->issue('client', 'account', ['openid'], new DateTimeImmutable('@1700000000'));
        $issuer = new RefreshTokenIssuer($database, $encryptionKey, $lookupKey);
        $token = $issuer->issue(
            $access->jti,
            'client',
            'account',
            ['openid'],
            1_700_000_000,
            new DateTimeImmutable('@1700000000'),
        );
        $issuer->revoke($token->jti, new DateTimeImmutable('@1700000100'));
        $adapter = new OidcRefreshTokenRekeyAdapter($database, $encryptionKey, $lookupKey);
        $forward = $this->forwardContext($database);
        $snapshot = $adapter->snapshot($forward);
        $adapter->transitionBatch($forward, $snapshot, null, 10);
        $adapter->verify($forward, $snapshot);

        $rollback = $this->rollbackContext($database);
        $rollbackSnapshot = $adapter->rollbackSnapshot($rollback);
        $adapter->rollbackBatch($rollback, $rollbackSnapshot, null, 10);
        $verification = $adapter->verifyRollback($rollback, $rollbackSnapshot);

        $row = $database->getConnection()->fetchAssociative(
            'SELECT token, token_lookup, revoked_at FROM oidc_refresh_token WHERE jti = ?',
            [$token->jti],
        );
        self::assertIsArray($row);
        self::assertSame(1, json_decode((string) $row['token'], true, 32, JSON_THROW_ON_ERROR)['master_version']);
        self::assertStringStartsWith('v1:', (string) $row['token_lookup']);
        self::assertSame(1_700_000_100, (int) $row['revoked_at']);
        self::assertSame(1, $verification[ApplicationSecret::PURPOSE_OIDC_REFRESH_TOKEN_ENCRYPTION]->verifiedRecords);
        self::assertSame(1, $verification[ApplicationSecret::PURPOSE_OIDC_REFRESH_TOKEN_LOOKUP]->verifiedRecords);
    }

    #[Test]
    public function signing_private_material_transitions_and_rolls_back_under_exact_row_identity(): void
    {
        $database = DBALDatabase::createSqlite();
        OidcSchema::installSigningKeys($database);
        $legacyKey = hash('sha256', 'legacy-signing-encryption', true);
        $signing = new SigningKeyRepository($database, $legacyKey);
        $key = $signing->initialize();
        $adapter = new OidcSigningKeyRekeyAdapter($database, $legacyKey);
        $forward = $this->forwardContext($database);
        $snapshot = $adapter->snapshot($forward);
        $adapter->transitionBatch($forward, $snapshot, null, 10);
        $verification = $adapter->verify($forward, $snapshot);

        $stored = (string) $database->getConnection()->fetchOne(
            'SELECT private_key_pem FROM oidc_signing_key WHERE kid = ?',
            [$key->kid],
        );
        self::assertSame(2, json_decode($stored, true, 32, JSON_THROW_ON_ERROR)['master_version']);
        self::assertSame(1, $verification[ApplicationSecret::PURPOSE_OIDC_SIGNING_KEY_ENCRYPTION]->verifiedRecords);

        $rollback = $this->rollbackContext($database);
        $rollbackSnapshot = $adapter->rollbackSnapshot($rollback);
        $adapter->rollbackBatch($rollback, $rollbackSnapshot, null, 10);
        $adapter->verifyRollback($rollback, $rollbackSnapshot);
        $restored = (string) $database->getConnection()->fetchOne(
            'SELECT private_key_pem FROM oidc_signing_key WHERE kid = ?',
            [$key->kid],
        );
        self::assertSame(1, json_decode($restored, true, 32, JSON_THROW_ON_ERROR)['master_version']);
    }

    #[Test]
    public function token_cas_conflict_leaves_ciphertext_and_concurrent_revocation_untouched(): void
    {
        $database = $this->tokenDatabase();
        $encryptionKey = hash('sha256', 'legacy-access-encryption', true);
        $lookupKey = hash('sha256', 'legacy-access-lookup', true);
        $token = new AccessTokenIssuer($database, $encryptionKey, $lookupKey)
            ->issue('client', 'account', ['openid'], new DateTimeImmutable('@1700000000'));
        $adapter = new OidcAccessTokenRekeyAdapter($database, $encryptionKey, $lookupKey);
        $context = $this->forwardContext($database);
        $snapshot = $adapter->snapshot($context);
        $before = $database->getConnection()->fetchAssociative(
            'SELECT token, token_lookup FROM oidc_access_token WHERE jti = ?',
            [$token->jti],
        );
        $database->getConnection()->executeStatement(
            'UPDATE oidc_access_token SET token_lookup = ?, revoked_at = ? WHERE jti = ?',
            [hash('sha256', 'competing-lookup'), 1_700_000_100, $token->jti],
        );

        try {
            $adapter->transitionBatch($context, $snapshot, null, 10);
            self::fail('A changed sibling lookup must fail the joint token CAS.');
        } catch (ApplicationMasterRekeyConflictException $failure) {
            self::assertStringContainsString('compare-and-swap', $failure->getMessage());
        }
        $after = $database->getConnection()->fetchAssociative(
            'SELECT token, token_lookup, revoked_at FROM oidc_access_token WHERE jti = ?',
            [$token->jti],
        );
        self::assertIsArray($before);
        self::assertIsArray($after);
        self::assertSame($before['token'], $after['token']);
        self::assertNotSame($before['token_lookup'], $after['token_lookup']);
        self::assertSame(1_700_000_100, (int) $after['revoked_at']);
    }

    #[Test]
    public function coordinator_rolls_back_token_pair_when_cursor_evidence_update_fails(): void
    {
        $database = $this->tokenDatabase();
        $predecessor = new AccessTokenIssuer(
            $database,
            null,
            null,
            OidcApplicationMasterKeyring::create(1),
        );
        $token = $predecessor->issue(
            'client',
            'account',
            ['openid'],
            new DateTimeImmutable('@1700000000'),
        );
        $before = $database->getConnection()->fetchAssociative(
            'SELECT token, token_lookup FROM oidc_access_token WHERE jti = ?',
            [$token->jti],
        );
        self::assertIsArray($before);

        $migration = require dirname(__DIR__, 4) . '/foundation/migrations/2026_08_15_000002_application_master_rekey.php';
        self::assertInstanceOf(Migration::class, $migration);
        $migration->up(new SchemaBuilder($database->getConnection()));

        $provider = new OidcServiceProvider();
        $provider->setKernelContext('', [], []);
        $provider->setKernelServices(new class ($database) implements KernelServicesInterface {
            public function __construct(private readonly DatabaseInterface $database) {}

            public function get(string $abstract): ?object
            {
                return $abstract === DatabaseInterface::class ? $this->database : null;
            }
        });
        $contributions = iterator_to_array($provider->applicationMasterRekeyContributions(), false);
        $purposes = new ApplicationMasterPurposeRegistry();
        $adapters = [];
        foreach ($contributions as $contribution) {
            $adapters[] = $contribution->adapter();
            foreach ($contribution->policies() as $policy) {
                $purposes->register($policy);
            }
        }
        $purposes->freeze();
        $keyring = OidcApplicationMasterKeyring::create(2, [1]);
        self::assertSame($purposes->checksum(), $keyring->purposeRegistryChecksum());
        $requestId = 'oidc-access-atomic-cursor-failure';
        $store = new ApplicationMasterRekeyStore($database, static fn(): int => 1_200);
        $store->prepare(new ApplicationMasterRekeyRequest(
            requestId: $requestId,
            fromVersion: 1,
            toVersion: 2,
            registryChecksum: $purposes->checksum(),
            authorizationDigest: hash('sha256', 'oidc-atomic-authorization'),
            actor: 'test-operator',
            rollbackDeadline: 2_000,
            retentionDeadline: 3_000,
            createdAt: 1_000,
        ), $purposes);
        $store->installActive(
            $requestId,
            1,
            $keyring->referenceFingerprint(1),
            $keyring->referenceFingerprint(2),
            1_100,
        );
        $coordinator = new ApplicationMasterRekeyCoordinator($store, $keyring, $purposes, $adapters);
        $coordinator->snapshotAdapter($requestId, 2, OidcAccessTokenRekeyAdapter::ID);
        $database->getConnection()->executeStatement(
            "CREATE TRIGGER refuse_oidc_cursor_evidence BEFORE UPDATE ON waaseyaa_application_master_rekey_adapter WHEN NEW.cursor IS NOT OLD.cursor BEGIN SELECT RAISE(ABORT, 'synthetic cursor refusal'); END",
        );

        try {
            $coordinator->transitionNextBatch($requestId, 3, OidcAccessTokenRekeyAdapter::ID, 1);
            self::fail('OIDC owner effects committed without durable cursor evidence.');
        } catch (\Throwable $failure) {
            self::assertStringContainsString('synthetic cursor refusal', $failure->getMessage());
        }

        $after = $database->getConnection()->fetchAssociative(
            'SELECT token, token_lookup FROM oidc_access_token WHERE jti = ?',
            [$token->jti],
        );
        self::assertSame($before, $after);
        $progress = $store->requireAdapter($requestId, OidcAccessTokenRekeyAdapter::ID);
        self::assertNull($progress->cursor);
        self::assertSame(0, $progress->transitionedRecords);
    }

    #[Test]
    public function successor_writes_after_snapshot_stay_outside_the_frozen_predecessor_inventory(): void
    {
        $database = $this->tokenDatabase();
        $oldIssuer = new AccessTokenIssuer(
            $database,
            null,
            null,
            OidcApplicationMasterKeyring::create(1),
        );
        $old = $oldIssuer->issue('client', 'account', ['openid'], new DateTimeImmutable('@1700000000'));
        $adapter = new OidcAccessTokenRekeyAdapter($database);
        $context = $this->forwardContext($database);
        $snapshot = $adapter->snapshot($context);
        $newIssuer = new AccessTokenIssuer(
            $database,
            null,
            null,
            OidcApplicationMasterKeyring::create(2, [1]),
        );
        $new = $newIssuer->issue('client', 'account', ['openid'], new DateTimeImmutable('@1700000001'));

        $adapter->transitionBatch($context, $snapshot, null, 10);
        $verification = $adapter->verify($context, $snapshot);

        self::assertSame(1, $snapshot->totalRecords);
        self::assertSame(1, $verification[ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_ENCRYPTION]->verifiedRecords);
        self::assertSame($old->jti, $newIssuer->findByOpaqueToken($old->token)['jti'] ?? null);
        self::assertSame($new->jti, $newIssuer->findByOpaqueToken($new->token)['jti'] ?? null);
        self::assertSame(2, (int) $database->getConnection()->fetchOne('SELECT COUNT(*) FROM oidc_access_token'));
    }

    private function tokenDatabase(): DBALDatabase
    {
        $database = DBALDatabase::createSqlite();
        OidcSchema::installTokenStorage($database);

        return $database;
    }

    private function forwardContext(DatabaseInterface $database): ApplicationMasterRekeyContext
    {
        return $this->context(
            $database,
            OidcApplicationMasterKeyring::create(2, [1]),
            ApplicationMasterRekeyState::TransitionBoundedBatches,
        );
    }

    private function rollbackContext(DatabaseInterface $database): ApplicationMasterRekeyContext
    {
        return $this->context(
            $database,
            OidcApplicationMasterKeyring::rollback(1, 2),
            ApplicationMasterRekeyState::RollingBack,
        );
    }

    private function context(
        DatabaseInterface $database,
        \Waaseyaa\Foundation\Security\ApplicationMasterKeyring $keyring,
        ApplicationMasterRekeyState $state,
    ): ApplicationMasterRekeyContext {
        return new ApplicationMasterRekeyContext(
            new ApplicationMasterRekeyRecord(
                requestId: 'oidc-rekey-test',
                requestDigest: hash('sha256', 'oidc-request'),
                fromVersion: 1,
                toVersion: 2,
                registryChecksum: $keyring->purposeRegistryChecksum(),
                authorizationDigest: hash('sha256', 'oidc-authorization'),
                actor: 'test-operator',
                rollbackDeadline: 2_000,
                retentionDeadline: 3_000,
                state: $state,
                revision: 1,
                unresolvedFailures: 0,
                createdAt: 1_000,
                updatedAt: 1_000,
            ),
            $keyring,
            $database,
        );
    }
}
