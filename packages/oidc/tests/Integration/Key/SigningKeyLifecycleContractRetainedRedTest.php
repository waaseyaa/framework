<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Tests\Integration\Key;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\DateTime\EntityClockInterface;
use Waaseyaa\Oidc\Key\RealKeyMaterialProvider;
use Waaseyaa\Oidc\Key\SigningKeyLifecycleConflictException;
use Waaseyaa\Oidc\Key\SigningKeyLifecyclePolicy;
use Waaseyaa\Oidc\Key\SigningKeyPropagationPendingException;
use Waaseyaa\Oidc\Key\SigningKeyRepository;
use Waaseyaa\Oidc\Keys\SigningKeyState;
use Waaseyaa\Oidc\Tests\Support\OidcSchema;
use Waaseyaa\Oidc\Token\IdTokenMinter;

/** Retained-red proof for the complete CFG-04 staged signing lifecycle. */
final class SigningKeyLifecycleContractRetainedRedTest extends TestCase
{
    #[Test]
    public function lifecycle_schema_is_additive_and_migration_owned(): void
    {
        $database = DBALDatabase::createSqlite();
        OidcSchema::installSigningKeys($database);
        $schema = $database->schema();

        foreach ([
            'key_version',
            'state',
            'staged_at',
            'activated_at',
            'retain_until',
            'propagation_observed_at',
            'propagation_evidence_hash',
            'revoked_at',
            'revocation_reason',
        ] as $field) {
            self::assertTrue($schema->fieldExists('oidc_signing_key', $field), $field);
        }
        self::assertTrue($schema->tableExists('oidc_signing_key_version_sequence'));
        self::assertTrue($schema->tableExists('oidc_signing_key_revocation'));
    }

    #[Test]
    public function retention_formula_uses_every_contract_term_exactly(): void
    {
        $policy = new SigningKeyLifecyclePolicy(
            maximumTokenLifetimeSeconds: 100,
            maximumClockSkewSeconds: 3,
            jwksCacheLifetimeSeconds: 10,
            propagationMarginSeconds: 5,
        );

        self::assertSame(118, $policy->retentionSeconds());
        self::assertSame(15, $policy->effectivePropagationHorizonSeconds());
        self::assertSame(1_118, $policy->retainUntil(new DateTimeImmutable('@1000')));
    }

    #[Test]
    public function successor_is_published_for_the_cache_horizon_before_it_signs(): void
    {
        [$repository, $clock, $policy] = $this->repository();
        $active = $repository->initialize();
        $successor = $repository->stageSuccessor();
        $repository->recordPropagation($successor->kid, hash('sha256', 'synthetic-propagation-evidence'));

        self::assertSame($active->kid, $repository->currentKey()->kid);
        self::assertSame(SigningKeyState::StagedVerifyOnly, $successor->state);
        self::assertSame(
            [$active->kid, $successor->kid],
            array_map(static fn($key): string => $key->kid, $repository->allActive()),
        );

        try {
            $repository->activateSuccessor($successor->kid, $active->version);
            self::fail('Activation before the published cache horizon must fail.');
        } catch (SigningKeyPropagationPendingException) {
        }

        $oldestAllowedCache = $repository->allActive();
        $clock->setTimestamp(1_000 + $policy->effectivePropagationHorizonSeconds());
        $repository->activateSuccessor($successor->kid, $active->version);

        $jwt = new IdTokenMinter(new RealKeyMaterialProvider($repository))->mint(
            'https://id.example',
            'subject',
            'client',
            null,
            $clock->now(),
        );
        [$encodedHeader, $encodedPayload, $encodedSignature] = explode('.', $jwt);
        $header = json_decode($this->base64UrlDecode($encodedHeader), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($header);
        self::assertSame($successor->kid, $header['kid'] ?? null);

        $cachedSuccessor = array_values(array_filter(
            $oldestAllowedCache,
            static fn($key): bool => $key->kid === $successor->kid,
        ));
        self::assertCount(1, $cachedSuccessor);
        self::assertSame(
            1,
            openssl_verify(
                $encodedHeader . '.' . $encodedPayload,
                $this->base64UrlDecode($encodedSignature),
                $cachedSuccessor[0]->publicKeyPem,
                OPENSSL_ALGO_SHA256,
            ),
        );
    }

    #[Test]
    public function competing_successors_conflict_and_versions_never_repeat(): void
    {
        [$repository, $clock, $policy] = $this->repository();
        $active = $repository->initialize();
        $successor = $repository->stageSuccessor();

        try {
            $repository->stageSuccessor();
            self::fail('A competing staged successor must conflict.');
        } catch (SigningKeyLifecycleConflictException) {
        }

        $repository->recordPropagation($successor->kid, hash('sha256', 'first-successor'));
        $clock->setTimestamp(1_000 + $policy->effectivePropagationHorizonSeconds());
        $repository->activateSuccessor($successor->kid, $active->version);
        $next = $repository->stageSuccessor();

        self::assertGreaterThan($successor->version, $next->version);
    }

    #[Test]
    public function cleanup_cannot_remove_a_live_verifier_before_the_full_margin(): void
    {
        [$repository, $clock, $policy] = $this->repository();
        $predecessor = $repository->initialize();
        $successor = $repository->stageSuccessor();
        $repository->recordPropagation($successor->kid, hash('sha256', 'cleanup-boundary'));
        $activationTimestamp = 1_000 + $policy->effectivePropagationHorizonSeconds();
        $clock->setTimestamp($activationTimestamp);
        $repository->activateSuccessor($successor->kid, $predecessor->version);

        $retireBoundary = $activationTimestamp + $policy->retentionSeconds();
        $clock->setTimestamp($retireBoundary - 1);
        self::assertSame(0, $repository->cleanupExpired());
        self::assertContains(
            $predecessor->kid,
            array_map(static fn($key): string => $key->kid, $repository->allActive()),
        );

        $clock->setTimestamp($retireBoundary);
        self::assertSame(1, $repository->cleanupExpired());
        self::assertNotContains(
            $predecessor->kid,
            array_map(static fn($key): string => $key->kid, $repository->allActive()),
        );
    }

    /**
     * @return array{SigningKeyRepository, MutableSigningKeyClock, SigningKeyLifecyclePolicy}
     */
    private function repository(): array
    {
        $database = DBALDatabase::createSqlite();
        OidcSchema::installSigningKeys($database);
        $clock = new MutableSigningKeyClock(1_000);
        $policy = new SigningKeyLifecyclePolicy(
            maximumTokenLifetimeSeconds: 100,
            maximumClockSkewSeconds: 3,
            jwksCacheLifetimeSeconds: 10,
            propagationMarginSeconds: 5,
        );

        return [
            new SigningKeyRepository(
                database: $database,
                encryptionKey: random_bytes(32),
                lifecyclePolicy: $policy,
                clock: $clock,
            ),
            $clock,
            $policy,
        ];
    }

    private function base64UrlDecode(string $encoded): string
    {
        $padded = str_pad($encoded, (int) (ceil(strlen($encoded) / 4) * 4), '=', STR_PAD_RIGHT);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        self::assertIsString($decoded);

        return $decoded;
    }
}

final class MutableSigningKeyClock implements EntityClockInterface
{
    public function __construct(private int $timestamp) {}

    public function setTimestamp(int $timestamp): void
    {
        $this->timestamp = $timestamp;
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $this->timestamp);
    }
}
