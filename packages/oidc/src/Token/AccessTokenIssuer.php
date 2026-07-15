<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Token;

use DateTimeImmutable;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Oidc\Security\OpaqueTokenProtector;

/**
 * Issues and persists opaque OIDC access tokens.
 *
 * Access tokens are stored in oidc_access_token so the userinfo endpoint can
 * verify them and detect revocation without round-tripping to the IdP JWK set.
 * The token value itself is a 32-byte URL-safe random string (opaque to clients).
 *
 * The bearer value is persisted in a versioned secretbox envelope. Exact lookup
 * uses a separate application-derived HMAC key and authenticates the envelope
 * before returning a record.
 *
 * @api
 */
final class AccessTokenIssuer
{
    private const TABLE = 'oidc_access_token';
    private const EXPIRY_SECONDS = 3600;

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
     * Issues a new access token and persists it.
     *
     * @param list<string> $scopes
     */
    public function issue(
        string $clientId,
        string $accountId,
        array $scopes,
        DateTimeImmutable $now,
    ): AccessTokenPair {
        $this->ensureTable();

        $jti = $this->uuid();
        $token = $this->opaqueToken();
        $issuedAt = $now->getTimestamp();
        $expiresAt = $issuedAt + self::EXPIRY_SECONDS;

        $this->database->insert(self::TABLE)
            ->values([
                'jti' => $jti,
                'token' => $this->protector->seal($token),
                'token_lookup' => $this->protector->lookup($token),
                'client_id' => $clientId,
                'account_id' => $accountId,
                'scope' => implode(' ', $scopes),
                'issued_at' => $issuedAt,
                'expires_at' => $expiresAt,
                'revoked_at' => null,
            ])
            ->execute();

        return new AccessTokenPair(
            jti: $jti,
            token: $token,
            expiresIn: self::EXPIRY_SECONDS,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByJti(string $jti): ?array
    {
        $this->ensureTable();

        foreach ($this->database->select(self::TABLE)->condition('jti', $jti)->execute() as $row) {
            $row['token'] = $this->protector->open((string) $row['token']);

            return $row;
        }

        return null;
    }

    /**
     * Find an access token through its purpose-bound keyed lookup value.
     *
     * @return array<string, mixed>|null
     */
    public function findByOpaqueToken(string $token): ?array
    {
        $this->ensureTable();

        foreach ($this->database->select(self::TABLE)->condition('token_lookup', $this->protector->lookup($token))->execute() as $row) {
            $storedToken = $this->protector->open((string) $row['token']);
            if (hash_equals($storedToken, $token)) {
                $row['token'] = $storedToken;

                return $row;
            }
        }

        return null;
    }

    public function revoke(string $jti, DateTimeImmutable $now): void
    {
        $this->ensureTable();

        $this->database->query(
            'UPDATE ' . self::TABLE . ' SET revoked_at = ? WHERE jti = ?',
            [$now->getTimestamp(), $jti],
        );
    }

    public function revokeByAccountAndClient(string $accountId, string $clientId, DateTimeImmutable $now): void
    {
        $this->ensureTable();

        $this->database->query(
            'UPDATE ' . self::TABLE . ' SET revoked_at = ? WHERE account_id = ? AND client_id = ? AND revoked_at IS NULL',
            [$now->getTimestamp(), $accountId, $clientId],
        );
    }

    /**
     * Revoke a set of access tokens by their JTIs (used in refresh-chain cascade).
     *
     * @param list<string> $jtis
     */
    public function revokeByJtis(array $jtis, DateTimeImmutable $now): void
    {
        if ($jtis === []) {
            return;
        }

        $this->ensureTable();

        $placeholders = implode(', ', array_fill(0, count($jtis), '?'));
        $params = array_merge([$now->getTimestamp()], $jtis);

        $this->database->query(
            'UPDATE ' . self::TABLE . ' SET revoked_at = ? WHERE jti IN (' . $placeholders . ') AND revoked_at IS NULL',
            $params,
        );
    }

    private function ensureTable(): void
    {
        if ($this->tableEnsured) {
            return;
        }

        $this->database->query(<<<'SQL'
                CREATE TABLE IF NOT EXISTS oidc_access_token (
                    jti VARCHAR(128) PRIMARY KEY NOT NULL,
                    token TEXT NOT NULL UNIQUE,
                    token_lookup CHAR(64) NOT NULL UNIQUE,
                    client_id VARCHAR(255) NOT NULL,
                    account_id VARCHAR(255) NOT NULL,
                    scope TEXT NOT NULL,
                    issued_at INTEGER NOT NULL,
                    expires_at INTEGER NOT NULL,
                    revoked_at INTEGER
                )
            SQL);

        $schema = $this->database->schema();
        if (!$schema->tableExists(self::TABLE)) {
            throw new \RuntimeException('OIDC access-token schema is unavailable.');
        }
        if (!$schema->fieldExists(self::TABLE, 'token_lookup')) {
            $schema->addField(self::TABLE, 'token_lookup', ['type' => 'varchar', 'length' => 64]);
            $schema->addUniqueKey(self::TABLE, 'idx_oidc_access_token_lookup', ['token_lookup']);
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
}
