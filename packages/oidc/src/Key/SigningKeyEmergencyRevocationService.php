<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Key;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\Schema\SchemaRequirement;
use Waaseyaa\Entity\DateTime\EntityClockInterface;
use Waaseyaa\Entity\DateTime\UtcEntityClock;
use Waaseyaa\Oidc\Keys\SigningKeyState;

/**
 * Separately authorized compromise path; never used by ordinary rotation.
 *
 * The operation revokes the key trust state, enumerates and invalidates every
 * currently live persisted OIDC token, and appends a non-secret evidence row
 * for the conservative stateless issuance window in one transaction.
 *
 * @api
 */
final readonly class SigningKeyEmergencyRevocationService
{
    private const KEY_TABLE = 'oidc_signing_key';
    private const LEDGER_TABLE = 'oidc_signing_key_revocation';
    private const ACCESS_TABLE = 'oidc_access_token';
    private const REFRESH_TABLE = 'oidc_refresh_token';

    private EntityClockInterface $clock;

    public function __construct(
        private DatabaseInterface $database,
        private SigningKeyRepository $repository,
        private SigningKeyLifecyclePolicy $policy,
        ?EntityClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new UtcEntityClock();
    }

    public function revoke(
        string $requestId,
        string $kid,
        string $actor,
        string $reason,
    ): SigningKeyRevocationRecord {
        $this->assertInput($requestId, $kid, $actor, $reason);
        $this->assertSchemaAvailable();

        $transaction = $this->database->transaction('oidc-signing-key-emergency-revocation');
        try {
            $existing = $this->fetchOne(
                'SELECT * FROM ' . self::LEDGER_TABLE . ' WHERE request_id = ? LIMIT 1',
                [$requestId],
            );
            if ($existing !== null) {
                $record = $this->hydrate($existing);
                if ($record->kid !== $kid || $record->actor !== $actor || $record->reason !== $reason) {
                    throw new SigningKeyLifecycleConflictException(
                        'Emergency revocation request identity is already bound to different evidence.',
                    );
                }
                $transaction->commit();

                return $record;
            }

            $key = $this->fetchOne(
                'SELECT * FROM ' . self::KEY_TABLE . ' WHERE kid = ? LIMIT 1',
                [$kid],
            );
            if ($key === null || (string) $key['state'] === SigningKeyState::Revoked->value) {
                throw new SigningKeyLifecycleConflictException(
                    'Emergency revocation target is absent or already revoked without this request identity.',
                );
            }

            $now = $this->clock->now();
            $timestamp = $now->getTimestamp();
            $accessJtis = $this->liveJtis(self::ACCESS_TABLE, $timestamp);
            $refreshJtis = $this->liveJtis(self::REFRESH_TABLE, $timestamp);
            $keyUpdated = $this->database->update(self::KEY_TABLE)
                ->fields([
                    'state' => SigningKeyState::Revoked->value,
                    'revoked_at' => $timestamp,
                    'revocation_reason' => $reason,
                    'retain_until' => null,
                ])
                ->condition('kid', $kid)
                ->condition('state', SigningKeyState::Revoked->value, '!=')
                ->execute();
            if ($keyUpdated !== 1) {
                throw new SigningKeyLifecycleConflictException(
                    'Emergency revocation target changed before the authority claim.',
                );
            }

            $accessAffected = $this->revokeLiveTokens(self::ACCESS_TABLE, $timestamp);
            $refreshAffected = $this->revokeLiveTokens(self::REFRESH_TABLE, $timestamp);
            if ($accessAffected !== count($accessJtis) || $refreshAffected !== count($refreshJtis)) {
                throw new SigningKeyLifecycleConflictException(
                    'Persisted token enumeration changed during emergency revocation.',
                );
            }

            $record = new SigningKeyRevocationRecord(
                requestId: $requestId,
                kid: $kid,
                keyVersion: (int) $key['key_version'],
                revokedAt: $timestamp,
                actor: $actor,
                reason: $reason,
                statelessWindowFrom: (int) ($key['activated_at'] ?? $key['created_at']),
                statelessWindowTo: $this->policy->statelessTokenWindowEnd($now),
                persistedAccessAffected: $accessAffected,
                persistedAccessJtiHash: $this->jtiHash($accessJtis),
                persistedRefreshAffected: $refreshAffected,
                persistedRefreshJtiHash: $this->jtiHash($refreshJtis),
            );
            $this->database->insert(self::LEDGER_TABLE)
                ->values([
                    'request_id' => $record->requestId,
                    'kid' => $record->kid,
                    'key_version' => $record->keyVersion,
                    'revoked_at' => $record->revokedAt,
                    'actor' => $record->actor,
                    'reason' => $record->reason,
                    'stateless_window_from' => $record->statelessWindowFrom,
                    'stateless_window_to' => $record->statelessWindowTo,
                    'persisted_access_affected' => $record->persistedAccessAffected,
                    'persisted_access_jti_hash' => $record->persistedAccessJtiHash,
                    'persisted_refresh_affected' => $record->persistedRefreshAffected,
                    'persisted_refresh_jti_hash' => $record->persistedRefreshJtiHash,
                ])
                ->execute();
            $transaction->commit();

            return $record;
        } catch (\Throwable $exception) {
            try {
                $transaction->rollBack();
            } catch (\Throwable) {
            }
            throw $exception;
        }
    }

    private function assertInput(string $requestId, string $kid, string $actor, string $reason): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{7,63}$/D', $requestId) !== 1) {
            throw new \InvalidArgumentException('Emergency revocation request id is invalid.');
        }
        foreach (['kid' => $kid, 'actor' => $actor, 'reason' => $reason] as $name => $value) {
            if ($value === '' || strlen($value) > 255 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1) {
                throw new \InvalidArgumentException(sprintf(
                    'Emergency revocation %s is invalid.',
                    $name,
                ));
            }
        }
    }

    private function assertSchemaAvailable(): void
    {
        // The repository verifies the lifecycle tables without mutating them.
        try {
            $this->repository->currentKey();
        } catch (\RuntimeException $exception) {
            if (str_contains($exception->getMessage(), 'S1-DB106')) {
                throw $exception;
            }
        }
        SchemaRequirement::assertAvailable(
            $this->database,
            self::ACCESS_TABLE,
            ['jti', 'expires_at', 'revoked_at'],
            'waaseyaa/oidc:2026_05_25_000002_oidc_token_schema',
        );
        SchemaRequirement::assertAvailable(
            $this->database,
            self::REFRESH_TABLE,
            ['jti', 'expires_at', 'revoked_at'],
            'waaseyaa/oidc:2026_05_25_000002_oidc_token_schema',
        );
    }

    /** @return list<string> */
    private function liveJtis(string $table, int $timestamp): array
    {
        $jtis = [];
        foreach (
            $this->database->query(
                'SELECT jti FROM ' . $table . '
                 WHERE revoked_at IS NULL AND expires_at > ? ORDER BY jti ASC',
                [$timestamp],
            ) as $row
        ) {
            /** @var array<string, mixed> $row */
            $jtis[] = (string) $row['jti'];
        }

        return $jtis;
    }

    private function revokeLiveTokens(string $table, int $timestamp): int
    {
        return $this->database->update($table)
            ->fields(['revoked_at' => $timestamp])
            ->condition('revoked_at', null, 'IS NULL')
            ->condition('expires_at', $timestamp, '>')
            ->execute();
    }

    /** @param list<string> $jtis */
    private function jtiHash(array $jtis): string
    {
        return hash('sha256', implode("\n", $jtis));
    }

    /** @param list<mixed> $arguments @return array<string, mixed>|null */
    private function fetchOne(string $sql, array $arguments): ?array
    {
        foreach ($this->database->query($sql, $arguments) as $row) {
            /** @var array<string, mixed> $row */
            return $row;
        }

        return null;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): SigningKeyRevocationRecord
    {
        return new SigningKeyRevocationRecord(
            requestId: (string) $row['request_id'],
            kid: (string) $row['kid'],
            keyVersion: (int) $row['key_version'],
            revokedAt: (int) $row['revoked_at'],
            actor: (string) $row['actor'],
            reason: (string) $row['reason'],
            statelessWindowFrom: (int) $row['stateless_window_from'],
            statelessWindowTo: (int) $row['stateless_window_to'],
            persistedAccessAffected: (int) $row['persisted_access_affected'],
            persistedAccessJtiHash: (string) $row['persisted_access_jti_hash'],
            persistedRefreshAffected: (int) $row['persisted_refresh_affected'],
            persistedRefreshJtiHash: (string) $row['persisted_refresh_jti_hash'],
        );
    }
}
