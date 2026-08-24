<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Key;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\Schema\SchemaRequirement;
use Waaseyaa\Entity\DateTime\EntityClockInterface;
use Waaseyaa\Entity\DateTime\UtcEntityClock;
use Waaseyaa\Foundation\Security\ApplicationMasterKeyring;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Oidc\Keys\OpenSslKeyFactory;
use Waaseyaa\Oidc\Keys\RsaSigningKeySigner;
use Waaseyaa\Oidc\Keys\SigningKey;
use Waaseyaa\Oidc\Keys\SigningKeySignerInterface;
use Waaseyaa\Oidc\Keys\SigningKeyState;
use Waaseyaa\Oidc\Security\SecretBoxEnvelope;

/**
 * Migration-backed, staged OIDC signing-key lifecycle authority.
 *
 * Reads perform no generation or DDL. Successor publication, evidenced
 * propagation, transactional activation and retention cleanup are separate
 * explicit operations.
 *
 * @api
 */
final class SigningKeyRepository
{
    private const TABLE = 'oidc_signing_key';
    private const VERSION_TABLE = 'oidc_signing_key_version_sequence';
    private const REVOCATION_TABLE = 'oidc_signing_key_revocation';
    private const ALGORITHM = 'RS256';

    private bool $schemaVerified = false;
    private readonly SecretBoxEnvelope $envelope;
    private readonly SigningKeyLifecyclePolicy $lifecyclePolicy;
    private readonly EntityClockInterface $clock;

    public function __construct(
        private readonly DatabaseInterface $database,
        #[\SensitiveParameter]
        ?string $encryptionKey,
        ?SigningKeyLifecyclePolicy $lifecyclePolicy = null,
        ?EntityClockInterface $clock = null,
        ?ApplicationMasterKeyring $keyring = null,
    ) {
        $this->envelope = $keyring === null
            ? new SecretBoxEnvelope($encryptionKey)
            : SecretBoxEnvelope::fromApplicationMasterKeyring(
                $keyring,
                ApplicationSecret::PURPOSE_OIDC_SIGNING_KEY_ENCRYPTION,
                $encryptionKey,
            );
        $this->lifecyclePolicy = $lifecyclePolicy ?? new SigningKeyLifecyclePolicy();
        $this->clock = $clock ?? new UtcEntityClock();
    }

    public function lifecyclePolicy(): SigningKeyLifecyclePolicy
    {
        return $this->lifecyclePolicy;
    }

    public function currentKey(): SigningKey
    {
        $this->assertSchemaAvailable();
        $row = $this->fetchActive();
        if ($row !== null) {
            return $this->hydrate($row);
        }

        throw new \RuntimeException(
            'OIDC signing-key custody has no active key; run the explicit authorized initialization command.',
        );
    }

    public function currentSigner(): SigningKeySignerInterface
    {
        $this->assertSchemaAvailable();
        $row = $this->fetchActive();
        if ($row === null) {
            throw new \RuntimeException(
                'OIDC signing-key custody has no active key; run the explicit authorized initialization command.',
            );
        }

        $key = $this->hydrate($row);
        $privateKeyPem = $this->envelope->open((string) $row['private_key_pem'], (string) $row['kid']);
        $signer = RsaSigningKeySigner::fromPrivatePem($key, $privateKeyPem);
        unset($privateKeyPem);

        return $signer;
    }

    public function previousKey(): ?SigningKey
    {
        $this->assertSchemaAvailable();
        $now = $this->clock->now()->getTimestamp();
        $row = $this->fetchOne(
            'SELECT * FROM ' . self::TABLE . '
             WHERE state = ? AND retain_until > ?
             ORDER BY rotated_out_at DESC, key_version DESC LIMIT 1',
            [SigningKeyState::RetiredVerifyOnly->value, $now],
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Every public key currently allowed to verify: staged, active, and each
     * retained predecessor. Revoked and expired keys are never published.
     *
     * @return list<SigningKey>
     */
    public function allActive(): array
    {
        $this->assertSchemaAvailable();
        $this->currentKey();
        $now = $this->clock->now()->getTimestamp();
        $keys = [];
        foreach (
            $this->database->query(
                'SELECT * FROM ' . self::TABLE . '
                 WHERE state IN (?, ?)
                    OR (state = ? AND retain_until > ?)
                 ORDER BY CASE state
                    WHEN ? THEN 0
                    WHEN ? THEN 1
                    ELSE 2
                 END, key_version ASC',
                [
                    SigningKeyState::ActiveSignAndVerify->value,
                    SigningKeyState::StagedVerifyOnly->value,
                    SigningKeyState::RetiredVerifyOnly->value,
                    $now,
                    SigningKeyState::ActiveSignAndVerify->value,
                    SigningKeyState::StagedVerifyOnly->value,
                ],
            ) as $row
        ) {
            /** @var array<string, mixed> $row */
            $keys[] = $this->hydrate($row);
        }

        return $keys;
    }

    public function initialize(): SigningKey
    {
        $this->assertSchemaAvailable();
        $keyPair = new OpenSslKeyFactory()->generateRsaKeyPair();
        $privateKeyPem = $keyPair['private'];
        $publicKeyPem = $keyPair['public'];
        $kid = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
        $now = $this->clock->now();

        try {
            $version = $this->transactional(function () use ($kid, $privateKeyPem, $publicKeyPem, $now): int {
                if ($this->countKeys() !== 0) {
                    throw new SigningKeyLifecycleConflictException(
                        'Signing-key initialization requires an empty lifecycle.',
                    );
                }
                $version = $this->allocateVersion();
                $this->insertKey(
                    kid: $kid,
                    version: $version,
                    state: SigningKeyState::ActiveSignAndVerify,
                    privateKeyPem: $privateKeyPem,
                    publicKeyPem: $publicKeyPem,
                    now: $now,
                    activatedAt: $now,
                );

                return $version;
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw new SigningKeyLifecycleConflictException(
                'Signing-key initialization conflicted with another authority.',
                previous: $exception,
            );
        } finally {
            $this->zeroize($privateKeyPem);
        }

        return new SigningKey(
            kid: $kid,
            algorithm: self::ALGORITHM,
            publicKeyPem: $publicKeyPem,
            version: $version,
            state: SigningKeyState::ActiveSignAndVerify,
            stagedAt: $now->getTimestamp(),
            activatedAt: $now->getTimestamp(),
        );
    }

    public function stageSuccessor(): SigningKey
    {
        $this->assertSchemaAvailable();
        $keyPair = new OpenSslKeyFactory()->generateRsaKeyPair();
        $privateKeyPem = $keyPair['private'];
        $publicKeyPem = $keyPair['public'];
        $kid = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
        $now = $this->clock->now();

        try {
            $version = $this->transactional(function () use ($kid, $privateKeyPem, $publicKeyPem, $now): int {
                if ($this->fetchActive() === null) {
                    throw new SigningKeyLifecycleConflictException(
                        'A successor cannot be staged without an active signing key.',
                    );
                }
                if ($this->fetchByState(SigningKeyState::StagedVerifyOnly) !== null) {
                    throw new SigningKeyLifecycleConflictException(
                        'A staged signing-key successor already exists.',
                    );
                }
                $version = $this->allocateVersion();
                $this->insertKey(
                    kid: $kid,
                    version: $version,
                    state: SigningKeyState::StagedVerifyOnly,
                    privateKeyPem: $privateKeyPem,
                    publicKeyPem: $publicKeyPem,
                    now: $now,
                );

                return $version;
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw new SigningKeyLifecycleConflictException(
                'Signing-key successor staging conflicted with another authority.',
                previous: $exception,
            );
        } finally {
            $this->zeroize($privateKeyPem);
        }

        return new SigningKey(
            kid: $kid,
            algorithm: self::ALGORITHM,
            publicKeyPem: $publicKeyPem,
            version: $version,
            state: SigningKeyState::StagedVerifyOnly,
            stagedAt: $now->getTimestamp(),
        );
    }

    public function recordPropagation(string $kid, string $evidenceHash): SigningKey
    {
        $this->assertSchemaAvailable();
        $normalizedHash = strtolower($evidenceHash);
        if (preg_match('/^[a-f0-9]{64}$/D', $normalizedHash) !== 1) {
            throw new \InvalidArgumentException('Propagation evidence must be a SHA-256 hash.');
        }
        $observedAt = $this->clock->now()->getTimestamp();

        $this->transactional(function () use ($kid, $normalizedHash, $observedAt): void {
            $updated = $this->database->update(self::TABLE)
                ->fields([
                    'propagation_observed_at' => $observedAt,
                    'propagation_evidence_hash' => $normalizedHash,
                ])
                ->condition('kid', $kid)
                ->condition('state', SigningKeyState::StagedVerifyOnly->value)
                ->execute();
            if ($updated !== 1) {
                throw new SigningKeyLifecycleConflictException(
                    'Propagation evidence target is no longer the staged successor.',
                );
            }
        });

        $row = $this->fetchByKid($kid);
        if ($row === null) {
            throw new SigningKeyLifecycleConflictException('Staged successor disappeared after evidence recording.');
        }

        return $this->hydrate($row);
    }

    public function activateSuccessor(string $kid, int $expectedActiveVersion): SigningKey
    {
        $this->assertSchemaAvailable();
        $now = $this->clock->now();
        $activated = $this->transactional(function () use ($kid, $expectedActiveVersion, $now): array {
            $active = $this->fetchActive();
            $successor = $this->fetchByKid($kid);
            if ($active === null
                || (int) $active['key_version'] !== $expectedActiveVersion
                || $successor === null
                || (string) $successor['state'] !== SigningKeyState::StagedVerifyOnly->value) {
                throw new SigningKeyLifecycleConflictException(
                    'Signing-key activation authority is stale or the successor is unavailable.',
                );
            }
            if ($successor['propagation_observed_at'] === null
                || !is_string($successor['propagation_evidence_hash'])
                || preg_match('/^[a-f0-9]{64}$/D', $successor['propagation_evidence_hash']) !== 1) {
                throw new SigningKeyPropagationPendingException(
                    'Signing-key successor has no valid observable propagation evidence.',
                );
            }
            $eligibleAt = (int) $successor['staged_at']
                + $this->lifecyclePolicy->effectivePropagationHorizonSeconds();
            if ($now->getTimestamp() < $eligibleAt) {
                throw new SigningKeyPropagationPendingException(sprintf(
                    'Signing-key successor is not activation-eligible until timestamp %d.',
                    $eligibleAt,
                ));
            }

            $retired = $this->database->update(self::TABLE)
                ->fields([
                    'state' => SigningKeyState::RetiredVerifyOnly->value,
                    'rotated_out_at' => $now->getTimestamp(),
                    'retain_until' => $this->lifecyclePolicy->retainUntil($now),
                ])
                ->condition('kid', (string) $active['kid'])
                ->condition('key_version', $expectedActiveVersion)
                ->condition('state', SigningKeyState::ActiveSignAndVerify->value)
                ->execute();
            if ($retired !== 1) {
                throw new SigningKeyLifecycleConflictException(
                    'The active signing-key authority changed before retirement.',
                );
            }

            $promoted = $this->database->update(self::TABLE)
                ->fields([
                    'state' => SigningKeyState::ActiveSignAndVerify->value,
                    'activated_at' => $now->getTimestamp(),
                    'rotated_out_at' => null,
                    'retain_until' => null,
                ])
                ->condition('kid', $kid)
                ->condition('state', SigningKeyState::StagedVerifyOnly->value)
                ->execute();
            if ($promoted !== 1) {
                throw new SigningKeyLifecycleConflictException(
                    'The staged successor changed before activation.',
                );
            }

            $successor['state'] = SigningKeyState::ActiveSignAndVerify->value;
            $successor['activated_at'] = $now->getTimestamp();

            return $successor;
        });

        return $this->hydrate($activated);
    }

    public function cleanupExpired(): int
    {
        $this->assertSchemaAvailable();
        $now = $this->clock->now()->getTimestamp();

        return $this->transactional(
            fn(): int => $this->database->delete(self::TABLE)
                ->condition('state', SigningKeyState::RetiredVerifyOnly->value)
                ->condition('retain_until', $now, '<=')
                ->execute(),
        );
    }

    public function rotate(): SigningKey
    {
        $this->assertSchemaAvailable();
        if ($this->countKeys() === 0) {
            return $this->initialize();
        }

        throw new \LogicException(
            'Immediate signing-key rotation is disabled; stage, evidence, and activate a successor explicitly.',
        );
    }

    private function fetchActive(): ?array
    {
        return $this->fetchByState(SigningKeyState::ActiveSignAndVerify);
    }

    private function fetchByState(SigningKeyState $state): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM ' . self::TABLE . ' WHERE state = ? LIMIT 1',
            [$state->value],
        );
    }

    private function fetchByKid(string $kid): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM ' . self::TABLE . ' WHERE kid = ? LIMIT 1',
            [$kid],
        );
    }

    /** @param list<mixed> $arguments */
    private function fetchOne(string $sql, array $arguments): ?array
    {
        foreach ($this->database->query($sql, $arguments) as $row) {
            /** @var array<string, mixed> $row */
            return $row;
        }

        return null;
    }

    private function countKeys(): int
    {
        $row = $this->fetchOne('SELECT COUNT(*) AS total FROM ' . self::TABLE, []);

        return (int) ($row['total'] ?? 0);
    }

    private function allocateVersion(): int
    {
        $row = $this->fetchOne(
            'SELECT next_version FROM ' . self::VERSION_TABLE . ' WHERE singleton_id = 1',
            [],
        );
        if ($row === null) {
            throw new \RuntimeException('Signing-key version authority is missing.');
        }
        $version = (int) $row['next_version'];
        $claimed = $this->database->update(self::VERSION_TABLE)
            ->fields(['next_version' => $version + 1])
            ->condition('singleton_id', 1)
            ->condition('next_version', $version)
            ->execute();
        if ($claimed !== 1) {
            throw new SigningKeyLifecycleConflictException(
                'Signing-key version allocation conflicted with another authority.',
            );
        }

        return $version;
    }

    private function insertKey(
        string $kid,
        int $version,
        SigningKeyState $state,
        #[\SensitiveParameter]
        string $privateKeyPem,
        string $publicKeyPem,
        DateTimeImmutable $now,
        ?DateTimeImmutable $activatedAt = null,
    ): void {
        $this->database->insert(self::TABLE)
            ->values([
                'kid' => $kid,
                'key_version' => $version,
                'algorithm' => self::ALGORITHM,
                'state' => $state->value,
                'private_key_pem' => $this->envelope->seal($privateKeyPem, $kid),
                'public_key_pem' => $publicKeyPem,
                'created_at' => $now->getTimestamp(),
                'staged_at' => $now->getTimestamp(),
                'activated_at' => $activatedAt?->getTimestamp(),
                'rotated_out_at' => null,
                'retain_until' => null,
                'propagation_observed_at' => null,
                'propagation_evidence_hash' => null,
                'revoked_at' => null,
                'revocation_reason' => null,
            ])
            ->execute();
    }

    private function assertSchemaAvailable(): void
    {
        if ($this->schemaVerified) {
            return;
        }

        SchemaRequirement::assertAvailable(
            $this->database,
            self::TABLE,
            [
                'kid', 'key_version', 'algorithm', 'state', 'private_key_pem',
                'public_key_pem', 'created_at', 'staged_at', 'activated_at',
                'rotated_out_at', 'retain_until', 'propagation_observed_at',
                'propagation_evidence_hash', 'revoked_at', 'revocation_reason',
            ],
            'waaseyaa/oidc:2026_08_15_000007_oidc_signing_key_lifecycle',
        );
        SchemaRequirement::assertAvailable(
            $this->database,
            self::VERSION_TABLE,
            ['singleton_id', 'next_version'],
            'waaseyaa/oidc:2026_08_15_000007_oidc_signing_key_lifecycle',
        );
        SchemaRequirement::assertAvailable(
            $this->database,
            self::REVOCATION_TABLE,
            [
                'request_id', 'kid', 'key_version', 'revoked_at', 'actor', 'reason',
                'stateless_window_from', 'stateless_window_to',
                'persisted_access_affected', 'persisted_access_jti_hash',
                'persisted_refresh_affected', 'persisted_refresh_jti_hash',
            ],
            'waaseyaa/oidc:2026_08_15_000007_oidc_signing_key_lifecycle',
        );

        $this->schemaVerified = true;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): SigningKey
    {
        return new SigningKey(
            kid: (string) $row['kid'],
            algorithm: (string) $row['algorithm'],
            publicKeyPem: (string) $row['public_key_pem'],
            version: (int) $row['key_version'],
            state: SigningKeyState::from((string) $row['state']),
            stagedAt: $row['staged_at'] === null ? null : (int) $row['staged_at'],
            activatedAt: $row['activated_at'] === null ? null : (int) $row['activated_at'],
            rotatedAt: $row['rotated_out_at'] === null ? null : (int) $row['rotated_out_at'],
            retainUntil: $row['retain_until'] === null ? null : (int) $row['retain_until'],
            revokedAt: $row['revoked_at'] === null ? null : (int) $row['revoked_at'],
        );
    }

    /**
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    private function transactional(\Closure $operation): mixed
    {
        $transaction = $this->database->transaction('oidc-signing-key-lifecycle');
        try {
            $result = $operation();
            $transaction->commit();

            return $result;
        } catch (\Throwable $exception) {
            try {
                $transaction->rollBack();
            } catch (\Throwable) {
            }
            throw $exception;
        }
    }

    /** @param-out null $bytes */
    private function zeroize(?string &$bytes): void
    {
        if (is_string($bytes) && function_exists('sodium_memzero')) {
            sodium_memzero($bytes);
        }
        $bytes = null;
    }
}
