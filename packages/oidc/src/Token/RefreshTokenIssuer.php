<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Token;

use DateTimeImmutable;
use Waaseyaa\Database\DatabaseInterface;
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
    private const EXPIRY_SECONDS = 7_776_000; // 90 days

    private bool $tableEnsured = false;
    private readonly OpaqueTokenProtector $protector;

    public function __construct(
        private readonly DatabaseInterface $database,
        #[\SensitiveParameter]
        string $encryptionKey,
        #[\SensitiveParameter]
        string $lookupKey,
    ) {
        $this->protector = new OpaqueTokenProtector($encryptionKey, $lookupKey);
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
        $this->ensureTable();

        $jti = $this->uuid();
        $token = $this->opaqueToken();
        $issuedAt = $now->getTimestamp();
        $expiresAt = $issuedAt + self::EXPIRY_SECONDS;
        $chainRootJti ??= $jti;

        $this->database->insert(self::TABLE)
            ->values([
                'jti' => $jti,
                'token' => $this->protector->seal($token),
                'token_lookup' => $this->protector->lookup($token),
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
        $this->ensureTable();

        foreach ($this->database->select(self::TABLE)->condition('token_lookup', $this->protector->lookup($token))->execute() as $row) {
            $storedToken = $this->protector->open((string) $row['token']);
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
        $this->ensureTable();

        foreach ($this->database->select(self::TABLE)->condition('jti', $jti)->execute() as $row) {
            $row['token'] = $this->protector->open((string) $row['token']);

            return $this->hydrate($row);
        }

        return null;
    }

    /**
     * Revoke a single refresh token.
     */
    public function revoke(string $jti, DateTimeImmutable $now): void
    {
        $this->ensureTable();

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
        $this->ensureTable();

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
        $this->ensureTable();

        $this->database->query(
            'UPDATE ' . self::TABLE . ' SET revoked_at = ? WHERE access_token_jti = ? AND revoked_at IS NULL',
            [$now->getTimestamp(), $accessTokenJti],
        );
    }

    private function ensureTable(): void
    {
        if ($this->tableEnsured) {
            return;
        }

        $this->database->query(<<<'SQL'
                CREATE TABLE IF NOT EXISTS oidc_refresh_token (
                    jti VARCHAR(128) PRIMARY KEY NOT NULL,
                    token TEXT NOT NULL UNIQUE,
                    token_lookup CHAR(64) NOT NULL UNIQUE,
                    access_token_jti VARCHAR(128) NOT NULL,
                    client_id VARCHAR(255) NOT NULL,
                    account_id VARCHAR(255) NOT NULL,
                    scope TEXT NOT NULL,
                    auth_time INTEGER NOT NULL,
                    chain_root_jti VARCHAR(128) NOT NULL,
                    issued_at INTEGER NOT NULL,
                    expires_at INTEGER NOT NULL,
                    revoked_at INTEGER
                )
            SQL);

        $schema = $this->database->schema();
        if (!$schema->tableExists(self::TABLE)) {
            throw new \RuntimeException('OIDC refresh-token schema is unavailable.');
        }
        if (!$schema->fieldExists(self::TABLE, 'token_lookup')) {
            $schema->addField(self::TABLE, 'token_lookup', ['type' => 'varchar', 'length' => 64]);
            $schema->addUniqueKey(self::TABLE, 'idx_oidc_refresh_token_lookup', ['token_lookup']);
        }

        $this->tableEnsured = true;
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function opaqueToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
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
