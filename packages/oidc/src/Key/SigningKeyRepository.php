<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Key;

use DateTimeImmutable;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Oidc\Keys\OpenSslKeyFactory;
use Waaseyaa\Oidc\Keys\SigningKey;

/**
 * DB-backed signing key repository.
 *
 * Rotation policy: keep current (rotated_out_at IS NULL) + one previous.
 * Older keys are pruned atomically on each rotate() call.
 * Auto-bootstraps a first key on empty table so cold-start auth requests succeed.
 *
 * Secrets at rest: the RSA private key PEM is stored unencrypted in
 * `private_key_pem` and must remain plaintext at signing time (IdTokenMinter),
 * so confidentiality relies on the database trust boundary. KMS/app-key
 * encryption is tracked hardening (audit D-13) deferred because it requires
 * encryption-key bootstrap and rotation; see packages/oidc/README.md
 * "Secrets at rest".
 *
 * @api
 */
final class SigningKeyRepository
{
    private const TABLE = 'oidc_signing_key';
    private const ALGORITHM = 'RS256';

    private bool $tableEnsured = false;

    public function __construct(
        private readonly DatabaseInterface $database,
    ) {}

    /**
     * The current signing key (rotated_out_at IS NULL).
     * Auto-generates one if the table is empty.
     */
    public function currentKey(): SigningKey
    {
        $this->ensureTable();

        $row = $this->fetchCurrent();
        if ($row !== null) {
            return $this->hydrate($row);
        }

        // Auto-bootstrap first key on empty table
        return $this->rotate();
    }

    /**
     * The most recently rotated-out key (for in-flight token verification), or null.
     */
    public function previousKey(): ?SigningKey
    {
        $this->ensureTable();

        foreach (
            $this->database->query(
                'SELECT * FROM ' . self::TABLE . ' WHERE rotated_out_at IS NOT NULL ORDER BY rotated_out_at DESC LIMIT 1',
            ) as $row
        ) {
            /** @var array<string, mixed> $row */
            return $this->hydrate($row);
        }

        return null;
    }

    /**
     * All active keys: current + previous. Used by JWKS and bearer validation.
     *
     * @return list<SigningKey>
     */
    public function allActive(): array
    {
        $this->ensureTable();

        $current = $this->currentKey();
        $prev = $this->previousKey();

        if ($prev !== null) {
            return [$current, $prev];
        }

        return [$current];
    }

    /**
     * Generate a new RS256 keypair, set it as current, rotate prior current to previous,
     * and prune any keys older than the new previous.
     *
     * Returns the new current SigningKey.
     */
    public function rotate(): SigningKey
    {
        $this->ensureTable();

        $now = new DateTimeImmutable();
        $nowTs = $now->getTimestamp();

        // Mark current as rotated-out
        $this->database->query(
            'UPDATE ' . self::TABLE . ' SET rotated_out_at = ? WHERE rotated_out_at IS NULL',
            [$nowTs],
        );

        // Prune keys older than the one we just rotated out (keep only current + previous)
        $this->database->query(
            'DELETE FROM ' . self::TABLE . ' WHERE rotated_out_at IS NOT NULL AND rotated_out_at < ?',
            [$nowTs],
        );

        $keyPair = new OpenSslKeyFactory()->generateRsaKeyPair();
        $privateKeyPem = $keyPair['private'];
        $publicKeyPem = $keyPair['public'];

        $kid = $this->uuid();

        $this->database->insert(self::TABLE)
            ->values([
                'kid' => $kid,
                'algorithm' => self::ALGORITHM,
                'private_key_pem' => $privateKeyPem,
                'public_key_pem' => $publicKeyPem,
                'created_at' => $nowTs,
                'rotated_out_at' => null,
            ])
            ->execute();

        return new SigningKey(
            kid: $kid,
            algorithm: self::ALGORITHM,
            publicKeyPem: $publicKeyPem,
            privateKeyPem: $privateKeyPem,
        );
    }

    private function fetchCurrent(): ?array
    {
        foreach (
            $this->database->query(
                'SELECT * FROM ' . self::TABLE . ' WHERE rotated_out_at IS NULL LIMIT 1',
            ) as $row
        ) {
            /** @var array<string, mixed> $row */
            return $row;
        }

        return null;
    }

    private function ensureTable(): void
    {
        if ($this->tableEnsured) {
            return;
        }

        $this->database->query(<<<'SQL'
                CREATE TABLE IF NOT EXISTS oidc_signing_key (
                    kid VARCHAR(36) PRIMARY KEY NOT NULL,
                    algorithm VARCHAR(16) NOT NULL DEFAULT 'RS256',
                    private_key_pem TEXT NOT NULL,
                    public_key_pem TEXT NOT NULL,
                    created_at INTEGER NOT NULL,
                    rotated_out_at INTEGER
                )
            SQL);

        $this->tableEnsured = true;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): SigningKey
    {
        return new SigningKey(
            kid: (string) $row['kid'],
            algorithm: (string) $row['algorithm'],
            publicKeyPem: (string) $row['public_key_pem'],
            privateKeyPem: (string) $row['private_key_pem'],
        );
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
