<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Token;

use DateTimeImmutable;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\Schema\SchemaRequirement;
use Waaseyaa\Foundation\Security\ApplicationMasterKeyring;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Oidc\Security\OpaqueTokenProtector;

/**
 * Issues and persists OIDC refresh tokens.
 *
 * Refresh tokens are opaque values stored in oidc_refresh_token. Each token
 * belongs to a "chain" identified by chain_root_jti, which allows the
 * theft-detection cascade in RefreshTokenGrantHandler to revoke all tokens
 * in the chain when a replay is detected (RFC 6819 §5.2.2.3).
 *
 * @api
 */
final class RefreshTokenIssuer
{
    private const TABLE = 'oidc_refresh_token';
    public const EXPIRY_SECONDS = 7_776_000; // 90 days

    private bool $schemaVerified = false;
    private readonly OpaqueTokenProtector $protector;
    private readonly TokenCustodySequenceAllocator $custodySequences;

    public function __construct(
        private readonly DatabaseInterface $database,
        #[\SensitiveParameter]
        ?string $encryptionKey,
        #[\SensitiveParameter]
        ?string $lookupKey,
        ?ApplicationMasterKeyring $keyring = null,
    ) {
        $this->protector = $keyring === null
            ? new OpaqueTokenProtector($encryptionKey, $lookupKey)
            : OpaqueTokenProtector::fromApplicationMasterKeyring(
                $keyring,
                ApplicationSecret::PURPOSE_OIDC_REFRESH_TOKEN_ENCRYPTION,
                ApplicationSecret::PURPOSE_OIDC_REFRESH_TOKEN_LOOKUP,
                $encryptionKey,
                $lookupKey,
            );
        $this->custodySequences = new TokenCustodySequenceAllocator($database);
    }

    /**
     * Issue a new refresh token, optionally inheriting a chain from a prior token.
     *
     * @param list<string> $scopes
     */
    public function issue(
        string $accessTokenJti,
        string $clientId,
        string $accountId,
        array $scopes,
        int $authTime,
        DateTimeImmutable $now,
        ?string $chainRootJti = null,
    ): RefreshTokenRecord {
        $this->assertSchemaAvailable();

        $jti = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
        $token = $this->opaqueToken();
        $issuedAt = $now->getTimestamp();
        $expiresAt = $issuedAt + self::EXPIRY_SECONDS;
        $chainRootJti ??= $jti;

        $this->transactional(function () use (
            $jti,
            $token,
            $accessTokenJti,
            $clientId,
            $accountId,
            $scopes,
            $authTime,
            $chainRootJti,
            $issuedAt,
            $expiresAt,
        ): void {
            $this->database->insert(self::TABLE)
                ->values([
                    'jti' => $jti,
                    'token' => $this->protector->seal($token, $jti),
                    'token_lookup' => $this->protector->lookup($token),
                    'custody_sequence' => $this->custodySequences->allocate(TokenCustodySequenceAllocator::REFRESH),
                    'access_token_jti' => $accessTokenJti,
                    'client_id' => $clientId,
                    'account_id' => $accountId,
                    'scope' => implode(' ', $scopes),
                    'auth_time' => $authTime,
                    'chain_root_jti' => $chainRootJti,
                    'issued_at' => $issuedAt,
                    'expires_at' => $expiresAt,
                    'revoked_at' => null,
                ])
                ->execute();
        });

        return new RefreshTokenRecord(
            jti: $jti,
            token: $token,
            accessTokenJti: $accessTokenJti,
            clientId: $clientId,
            accountId: $accountId,
            scopes: $scopes,
            authTime: $authTime,
            chainRootJti: $chainRootJti,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
            revokedAt: null,
        );
    }

    /**
     * Find a refresh token record by its opaque token value.
     */
    public function findByToken(string $token): ?RefreshTokenRecord
    {
        $this->assertSchemaAvailable();

        foreach ($this->database->select(self::TABLE)
            ->condition('token_lookup', $this->protector->lookupCandidates($token), 'IN')
            ->execute() as $row) {
            $storedToken = $this->protector->open((string) $row['token'], (string) $row['jti']);
            if (hash_equals($storedToken, $token)) {
                $row['token'] = $storedToken;

                return $this->hydrate($row);
            }
        }

        return null;
    }

    /**
     * Find by primary key JTI.
     */
    public function findByJti(string $jti): ?RefreshTokenRecord
    {
        $this->assertSchemaAvailable();

        foreach ($this->database->select(self::TABLE)->condition('jti', $jti)->execute() as $row) {
            $row['token'] = $this->protector->open((string) $row['token'], (string) $row['jti']);

            return $this->hydrate($row);
        }

        return null;
    }

    /**
     * Revoke a single refresh token.
     */
    public function revoke(string $jti, DateTimeImmutable $now): void
    {
        $this->assertSchemaAvailable();

        $this->database->query(
            'UPDATE ' . self::TABLE . ' SET revoked_at = ? WHERE jti = ?',
            [$now->getTimestamp(), $jti],
        );
    }

    /**
     * Revoke all active tokens in a chain (theft-detection cascade).
     *
     * @return list<string> The access_token_jti values of all revoked refresh tokens.
     */
    public function revokeChain(string $chainRootJti, DateTimeImmutable $now): array
    {
        $this->assertSchemaAvailable();

        // Collect affected access_token_jtis before revoking
        $accessJtis = [];
        foreach (
            $this->database->select(self::TABLE)
                ->condition('chain_root_jti', $chainRootJti)
                ->execute() as $row
        ) {
            if (!isset($row['revoked_at'])) {
                $accessJtis[] = (string) $row['access_token_jti'];
            }
        }

        $this->database->query(
            'UPDATE ' . self::TABLE . ' SET revoked_at = ? WHERE chain_root_jti = ? AND revoked_at IS NULL',
            [$now->getTimestamp(), $chainRootJti],
        );

        return $accessJtis;
    }

    /**
     * Revoke the refresh token that has a given access_token_jti (used on access-token revocation).
     */
    public function revokeByAccessTokenJti(string $accessTokenJti, DateTimeImmutable $now): void
    {
        $this->assertSchemaAvailable();

        $this->database->query(
            'UPDATE ' . self::TABLE . ' SET revoked_at = ? WHERE access_token_jti = ? AND revoked_at IS NULL',
            [$now->getTimestamp(), $accessTokenJti],
        );
    }

    private function assertSchemaAvailable(): void
    {
        if ($this->schemaVerified) {
            return;
        }

        SchemaRequirement::assertAvailable(
            $this->database,
            self::TABLE,
            ['jti', 'token', 'token_lookup', 'custody_sequence', 'access_token_jti', 'client_id', 'account_id', 'scope', 'auth_time', 'chain_root_jti', 'issued_at', 'expires_at', 'revoked_at'],
            'waaseyaa/oidc:2026_08_15_000008_oidc_application_master_custody',
        );

        $this->schemaVerified = true;
    }

    private function opaqueToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    private function transactional(\Closure $operation): mixed
    {
        $transaction = $this->database->transaction('oidc-refresh-token-issue');
        try {
            $result = $operation();
            $transaction->commit();

            return $result;
        } catch (\Throwable $failure) {
            try {
                $transaction->rollBack();
            } catch (\Throwable) {
            }
            throw $failure;
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): RefreshTokenRecord
    {
        $scopes = array_filter(explode(' ', (string) ($row['scope'] ?? '')), static fn(string $s): bool => $s !== '');
        $revokedAt = isset($row['revoked_at']) ? (int) $row['revoked_at'] : null;

        return new RefreshTokenRecord(
            jti: (string) $row['jti'],
            token: (string) $row['token'],
            accessTokenJti: (string) $row['access_token_jti'],
            clientId: (string) $row['client_id'],
            accountId: (string) $row['account_id'],
            scopes: array_values($scopes),
            authTime: (int) $row['auth_time'],
            chainRootJti: (string) $row['chain_root_jti'],
            issuedAt: (int) $row['issued_at'],
            expiresAt: (int) $row['expires_at'],
            revokedAt: $revokedAt,
        );
    }
}
