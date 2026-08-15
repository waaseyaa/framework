<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Tests\Integration\Key;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\DateTime\EntityClockInterface;
use Waaseyaa\Oidc\Key\SigningKeyEmergencyRevocationService;
use Waaseyaa\Oidc\Key\SigningKeyLifecycleConflictException;
use Waaseyaa\Oidc\Key\SigningKeyLifecyclePolicy;
use Waaseyaa\Oidc\Key\SigningKeyRepository;
use Waaseyaa\Oidc\Keys\SigningKeyState;
use Waaseyaa\Oidc\Tests\Support\OidcSchema;
use Waaseyaa\Oidc\Token\AccessTokenIssuer;
use Waaseyaa\Oidc\Token\RefreshTokenIssuer;

/** Retained-red proof for the separately authorized CFG-04 compromise path. */
final class SigningKeyEmergencyRevocationRetainedRedTest extends TestCase
{
    #[Test]
    public function compromise_revokes_trust_and_persisted_tokens_with_an_immutable_window_record(): void
    {
        [$database, $repository, $policy, $clock] = $this->lifecycle();
        $key = $repository->initialize();
        [$access, $refresh] = $this->issuePersistedTokens($database, $clock->now());
        $service = new SigningKeyEmergencyRevocationService($database, $repository, $policy, $clock);

        $record = $service->revoke(
            requestId: 'synthetic-compromise-0001',
            kid: $key->kid,
            actor: 'synthetic-test-operator',
            reason: 'synthetic compromise proof',
        );

        self::assertSame(1, $record->persistedAccessAffected);
        self::assertSame(hash('sha256', $access->jti), $record->persistedAccessJtiHash);
        self::assertSame(1, $record->persistedRefreshAffected);
        self::assertSame(hash('sha256', $refresh->jti), $record->persistedRefreshJtiHash);
        self::assertSame(1_000, $record->statelessWindowFrom);
        self::assertSame(1_103, $record->statelessWindowTo);
        self::assertNotNull(
            $database->getConnection()->fetchOne(
                'SELECT revoked_at FROM oidc_access_token WHERE jti = ?',
                [$access->jti],
            ),
        );
        self::assertNotNull(
            $database->getConnection()->fetchOne(
                'SELECT revoked_at FROM oidc_refresh_token WHERE jti = ?',
                [$refresh->jti],
            ),
        );
        self::assertSame(
            SigningKeyState::Revoked->value,
            $database->getConnection()->fetchOne(
                'SELECT state FROM oidc_signing_key WHERE kid = ?',
                [$key->kid],
            ),
        );
        self::assertSame(
            1,
            (int) $database->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM oidc_signing_key_revocation WHERE request_id = ?',
                [$record->requestId],
            ),
        );

        try {
            $repository->currentKey();
            self::fail('A revoked active key must immediately lose signing authority.');
        } catch (\RuntimeException) {
        }
    }

    #[Test]
    public function request_identity_is_idempotent_but_cannot_be_rebound(): void
    {
        [$database, $repository, $policy, $clock] = $this->lifecycle();
        $key = $repository->initialize();
        $service = new SigningKeyEmergencyRevocationService($database, $repository, $policy, $clock);
        $first = $service->revoke(
            'synthetic-compromise-0002',
            $key->kid,
            'synthetic-test-operator',
            'synthetic compromise proof',
        );
        $repeat = $service->revoke(
            'synthetic-compromise-0002',
            $key->kid,
            'synthetic-test-operator',
            'synthetic compromise proof',
        );

        self::assertEquals($first, $repeat);

        try {
            $service->revoke(
                'synthetic-compromise-0002',
                'different-kid',
                'synthetic-test-operator',
                'synthetic compromise proof',
            );
            self::fail('A revocation request identity cannot be rebound.');
        } catch (SigningKeyLifecycleConflictException) {
        }
    }

    /**
     * @return array{DBALDatabase, SigningKeyRepository, SigningKeyLifecyclePolicy, MutableEmergencyClock}
     */
    private function lifecycle(): array
    {
        $database = DBALDatabase::createSqlite();
        OidcSchema::installSigningKeys($database);
        OidcSchema::installTokenStorage($database);
        $policy = new SigningKeyLifecyclePolicy(
            maximumTokenLifetimeSeconds: 100,
            maximumClockSkewSeconds: 3,
            jwksCacheLifetimeSeconds: 10,
            propagationMarginSeconds: 5,
        );
        $clock = new MutableEmergencyClock(1_000);
        $repository = new SigningKeyRepository(
            $database,
            random_bytes(32),
            $policy,
            $clock,
        );

        return [$database, $repository, $policy, $clock];
    }

    /**
     * @return array{\Waaseyaa\Oidc\Token\AccessTokenPair, \Waaseyaa\Oidc\Token\RefreshTokenRecord}
     */
    private function issuePersistedTokens(DBALDatabase $database, DateTimeImmutable $now): array
    {
        $accessIssuer = new AccessTokenIssuer($database, random_bytes(32), random_bytes(32));
        $refreshIssuer = new RefreshTokenIssuer($database, random_bytes(32), random_bytes(32));
        $access = $accessIssuer->issue('client', 'account', ['openid'], $now);
        $refresh = $refreshIssuer->issue(
            $access->jti,
            'client',
            'account',
            ['openid'],
            $now->getTimestamp(),
            $now,
        );

        return [$access, $refresh];
    }
}

final class MutableEmergencyClock implements EntityClockInterface
{
    public function __construct(private int $timestamp) {}

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $this->timestamp);
    }
}
