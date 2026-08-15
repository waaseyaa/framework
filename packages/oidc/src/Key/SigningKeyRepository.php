<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Key;

use DateTimeImmutable;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\Schema\SchemaRequirement;
use Waaseyaa\Oidc\Keys\OpenSslKeyFactory;
use Waaseyaa\Oidc\Keys\RsaSigningKeySigner;
use Waaseyaa\Oidc\Keys\SigningKey;
use Waaseyaa\Oidc\Keys\SigningKeySignerInterface;
use Waaseyaa\Oidc\Security\SecretBoxEnvelope;

/**
 * DB-backed signing key repository.
 *
 * Read paths never initialize or rotate. Explicit lifecycle operations retain
 * every verification predecessor until a later policy-governed cleanup.
 *
 * Private-key PEM values are persisted only in versioned sodium secretbox
 * envelopes under the application-derived OIDC signing-key purpose.
 *
 * @api
 */
final class SigningKeyRepository
{
    private const TABLE = 'oidc_signing_key';
    private const ALGORITHM = 'RS256';

    private bool $schemaVerified = false;
    private readonly SecretBoxEnvelope $envelope;

    public function __construct(
        private readonly DatabaseInterface $database,
        #[\SensitiveParameter]
        string $encryptionKey,
    ) {
        $this->envelope = new SecretBoxEnvelope($encryptionKey);
    }

    /**
     * The current signing key metadata (rotated_out_at IS NULL).
     */
    public function currentKey(): SigningKey
    {
        $this->assertSchemaAvailable();

        $row = $this->fetchCurrent();
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
        $row = $this->fetchCurrent();
        if ($row === null) {
            throw new \RuntimeException(
                'OIDC signing-key custody has no active key; run the explicit authorized initialization command.',
            );
        }

        $key = $this->hydrate($row);
        $privateKeyPem = $this->envelope->open((string) $row['private_key_pem']);
        $signer = RsaSigningKeySigner::fromPrivatePem($key, $privateKeyPem);
        unset($privateKeyPem);

        return $signer;
    }

    /**
     * The most recently rotated-out key (for in-flight token verification), or null.
     */
    public function previousKey(): ?SigningKey
    {
        $this->assertSchemaAvailable();

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
        $this->assertSchemaAvailable();

        $this->currentKey();
        $keys = [];
        foreach (
            $this->database->query(
                'SELECT * FROM ' . self::TABLE . ' ORDER BY CASE WHEN rotated_out_at IS NULL THEN 0 ELSE 1 END, rotated_out_at DESC, created_at DESC, kid ASC',
            ) as $row
        ) {
            /** @var array<string, mixed> $row */
            $keys[] = $this->hydrate($row);
        }

        return $keys;
    }

    /**
     * Generate a new RS256 keypair, set it as current, rotate prior current to previous,
     * and prune any keys older than the new previous.
     *
     * Returns the new current SigningKey.
     */
    public function rotate(): SigningKey
    {
        $this->assertSchemaAvailable();

        $keyPair = new OpenSslKeyFactory()->generateRsaKeyPair();
        $privateKeyPem = $keyPair['private'];
        $publicKeyPem = $keyPair['public'];
        $kid = $this->uuid();
        $now = new DateTimeImmutable();
        $nowTs = $now->getTimestamp();
        $transaction = $this->database->transaction('oidc-signing-key-rotate');
        try {
            $this->database->update(self::TABLE)
                ->fields(['rotated_out_at' => $nowTs])
                ->condition('rotated_out_at', null, 'IS NULL')
                ->execute();
            $this->database->insert(self::TABLE)
                ->values([
                    'kid' => $kid,
                    'algorithm' => self::ALGORITHM,
                    'private_key_pem' => $this->envelope->seal($privateKeyPem),
                    'public_key_pem' => $publicKeyPem,
                    'created_at' => $nowTs,
                    'rotated_out_at' => null,
                ])
                ->execute();
            $transaction->commit();
        } catch (\Throwable $exception) {
            try {
                $transaction->rollBack();
            } catch (\Throwable) {
            }
            throw $exception;
        } finally {
            if (function_exists('sodium_memzero')) {
                sodium_memzero($privateKeyPem);
            }
        }

        return new SigningKey($kid, self::ALGORITHM, $publicKeyPem);
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

    private function assertSchemaAvailable(): void
    {
        if ($this->schemaVerified) {
            return;
        }

        SchemaRequirement::assertAvailable(
            $this->database,
            self::TABLE,
            ['kid', 'algorithm', 'private_key_pem', 'public_key_pem', 'created_at', 'rotated_out_at'],
            'waaseyaa/oidc:2026_05_25_000003_oidc_signing_key_schema',
        );

        $this->schemaVerified = true;
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
