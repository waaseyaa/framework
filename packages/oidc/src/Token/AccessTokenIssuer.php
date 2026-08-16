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
    public const EXPIRY_SECONDS = 3600;

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
                ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_ENCRYPTION,
                ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_LOOKUP,
                $encryptionKey,
                $lookupKey,
            );
        $this->custodySequences = new TokenCustodySequenceAllocator($database);
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
        $this->assertSchemaAvailable();

        $jti = $this->uuid();
        $token = $this->opaqueToken();
        $issuedAt = $now->getTimestamp();
        $expiresAt = $issuedAt + self::EXPIRY_SECONDS;

        $this->transactional(function () use ($jti, $token, $clientId, $accountId, $scopes, $issuedAt, $expiresAt): void {
            $this->database->insert(self::TABLE)
                ->values([
                    'jti' => $jti,
                    'token' => $this->protector->seal($token, $jti),
                    'token_lookup' => $this->protector->lookup($token),
                    'custody_sequence' => $this->custodySequences->allocate(TokenCustodySequenceAllocator::ACCESS),
                    'client_id' => $clientId,
                    'account_id' => $accountId,
                    'scope' => implode(' ', $scopes),
                    'issued_at' => $issuedAt,
                    'expires_at' => $expiresAt,
                    'revoked_at' => null,
                ])
                ->execute();
        });

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
        $this->assertSchemaAvailable();

        foreach ($this->database->select(self::TABLE)->condition('jti', $jti)->execute() as $row) {
            $row['token'] = $this->protector->open((string) $row['token'], (string) $row['jti']);

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
        $this->assertSchemaAvailable();

        foreach ($this->database->select(self::TABLE)
            ->condition('token_lookup', $this->protector->lookupCandidates($token), 'IN')
            ->execute() as $row) {
            $storedToken = $this->protector->open((string) $row['token'], (string) $row['jti']);
            if (hash_equals($storedToken, $token)) {
                $row['token'] = $storedToken;

                return $row;
            }
        }

        return null;
    }

    public function revoke(string $jti, DateTimeImmutable $now): void
    {
        $this->assertSchemaAvailable();

        $this->database->query(
            'UPDATE ' . self::TABLE . ' SET revoked_at = ? WHERE jti = ?',
            [$now->getTimestamp(), $jti],
        );
    }

    public function revokeByAccountAndClient(string $accountId, string $clientId, DateTimeImmutable $now): void
    {
        $this->assertSchemaAvailable();

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

        $this->assertSchemaAvailable();

        $placeholders = implode(', ', array_fill(0, count($jtis), '?'));
        $params = array_merge([$now->getTimestamp()], $jtis);

        $this->database->query(
            'UPDATE ' . self::TABLE . ' SET revoked_at = ? WHERE jti IN (' . $placeholders . ') AND revoked_at IS NULL',
            $params,
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
            ['jti', 'token', 'token_lookup', 'custody_sequence', 'client_id', 'account_id', 'scope', 'issued_at', 'expires_at', 'revoked_at'],
            'waaseyaa/oidc:2026_08_15_000008_oidc_application_master_custody',
        );

        $this->schemaVerified = true;
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
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    private function transactional(\Closure $operation): mixed
    {
        $transaction = $this->database->transaction('oidc-access-token-issue');
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
}
