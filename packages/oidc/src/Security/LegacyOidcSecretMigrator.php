<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Security;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\Schema\SchemaRequirement;
use Waaseyaa\Foundation\Security\ApplicationMasterKeyring;
use Waaseyaa\Foundation\Security\ApplicationSecret;

/** @api */
final class LegacyOidcSecretMigrator
{
    private readonly SecretBoxEnvelope $signingKeys;
    private readonly OpaqueTokenProtector $accessTokens;
    private readonly OpaqueTokenProtector $refreshTokens;

    public function __construct(
        private readonly DatabaseInterface $database,
        #[\SensitiveParameter]
        ?string $signingKeyEncryptionKey,
        #[\SensitiveParameter]
        ?string $accessTokenEncryptionKey,
        #[\SensitiveParameter]
        ?string $accessTokenLookupKey,
        #[\SensitiveParameter]
        ?string $refreshTokenEncryptionKey,
        #[\SensitiveParameter]
        ?string $refreshTokenLookupKey,
        private readonly ?ApplicationMasterKeyring $keyring = null,
    ) {
        $this->signingKeys = $keyring === null
            ? new SecretBoxEnvelope($signingKeyEncryptionKey)
            : SecretBoxEnvelope::fromApplicationMasterKeyring(
                $keyring,
                ApplicationSecret::PURPOSE_OIDC_SIGNING_KEY_ENCRYPTION,
                $signingKeyEncryptionKey,
            );
        $this->accessTokens = $keyring === null
            ? new OpaqueTokenProtector($accessTokenEncryptionKey, $accessTokenLookupKey)
            : OpaqueTokenProtector::fromApplicationMasterKeyring(
                $keyring,
                ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_ENCRYPTION,
                ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_LOOKUP,
                $accessTokenEncryptionKey,
                $accessTokenLookupKey,
            );
        $this->refreshTokens = $keyring === null
            ? new OpaqueTokenProtector($refreshTokenEncryptionKey, $refreshTokenLookupKey)
            : OpaqueTokenProtector::fromApplicationMasterKeyring(
                $keyring,
                ApplicationSecret::PURPOSE_OIDC_REFRESH_TOKEN_ENCRYPTION,
                ApplicationSecret::PURPOSE_OIDC_REFRESH_TOKEN_LOOKUP,
                $refreshTokenEncryptionKey,
                $refreshTokenLookupKey,
            );
    }

    /** @return array{signing_keys: int, access_tokens: int, refresh_tokens: int} */
    public function migrate(): array
    {
        $transaction = $this->database->transaction('oidc_secret_storage_migration');

        try {
            $counts = [
                'signing_keys' => $this->migrateSigningKeys(),
                'access_tokens' => $this->migrateTokens('oidc_access_token', $this->accessTokens),
                'refresh_tokens' => $this->migrateTokens('oidc_refresh_token', $this->refreshTokens),
            ];
            $transaction->commit();

            return $counts;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    private function migrateSigningKeys(): int
    {
        if (!$this->database->schema()->tableExists('oidc_signing_key')) {
            return 0;
        }
        SchemaRequirement::assertAvailable(
            $this->database,
            'oidc_signing_key',
            ['kid', 'private_key_pem'],
            'waaseyaa/oidc:2026_05_25_000003_oidc_signing_key_schema',
        );

        $count = 0;
        foreach ($this->database->select('oidc_signing_key')->fields('oidc_signing_key', ['kid', 'private_key_pem'])->execute() as $row) {
            $stored = (string) $row['private_key_pem'];
            $kid = (string) $row['kid'];
            $plaintext = $stored;
            $version = null;
            if (str_starts_with($stored, 'secretbox.hkdf-v1:') || str_starts_with($stored, '{')) {
                $plaintext = $this->signingKeys->open($stored, $kid);
                $version = $this->signingKeys->applicationMasterVersion($stored);
                if ($this->keyring === null || $version === $this->keyring->activeVersion()) {
                    continue;
                }
            } elseif (!str_starts_with($stored, '-----BEGIN ') || !str_contains($stored, 'PRIVATE KEY-----')) {
                throw new \RuntimeException('OIDC secret migration refused unrecognized signing-key material.');
            }

            $updated = $this->database->update('oidc_signing_key')
                ->fields(['private_key_pem' => $this->signingKeys->seal($plaintext, $kid)])
                ->condition('kid', $kid)
                ->condition('private_key_pem', $stored)
                ->execute();
            if ($updated !== 1) {
                throw new \RuntimeException('OIDC secret migration refused a concurrent signing-key change.');
            }
            ++$count;
        }

        return $count;
    }

    private function migrateTokens(string $table, OpaqueTokenProtector $protector): int
    {
        $schema = $this->database->schema();
        if (!$schema->tableExists($table)) {
            return 0;
        }
        SchemaRequirement::assertAvailable(
            $this->database,
            $table,
            ['jti', 'token', 'token_lookup', 'custody_sequence'],
            'waaseyaa/oidc:2026_08_15_000008_oidc_application_master_custody',
        );

        $count = 0;
        foreach ($this->database->select($table)->fields($table, ['jti', 'token', 'token_lookup'])->execute() as $row) {
            $stored = (string) $row['token'];
            $lookup = $row['token_lookup'] ?? null;
            $jti = (string) $row['jti'];
            $plaintext = $stored;
            $version = null;
            if (str_starts_with($stored, 'secretbox.hkdf-v1:') || str_starts_with($stored, '{')) {
                $plaintext = $protector->open($stored, $jti);
                $version = $protector->ciphertextVersion($stored);
            } elseif ($stored === '') {
                throw new \RuntimeException('OIDC secret migration refused inconsistent token material.');
            }
            if (is_string($lookup) && $lookup !== '') {
                if (!in_array($lookup, $protector->lookupCandidates($plaintext), true)) {
                    throw new \RuntimeException('OIDC secret migration refused a mismatched token lookup index.');
                }
                if ($this->keyring === null
                    || ($version === $this->keyring->activeVersion()
                        && $protector->lookupVersion($lookup) === $this->keyring->activeVersion())) {
                    continue;
                }
            }

            $update = $this->database->update($table)
                ->fields([
                    'token' => $protector->seal($plaintext, $jti),
                    'token_lookup' => $protector->lookup($plaintext),
                ])
                ->condition('jti', $jti)
                ->condition('token', $stored);
            $update = $lookup === null
                ? $update->condition('token_lookup', null, 'IS NULL')
                : $update->condition('token_lookup', $lookup);
            $updated = $update->execute();
            if ($updated !== 1) {
                throw new \RuntimeException('OIDC secret migration refused a concurrent token change.');
            }
            ++$count;
        }

        return $count;
    }

    /** @return array{database: string, keys: string} */
    public function __debugInfo(): array
    {
        return ['database' => $this->database::class, 'keys' => '[REDACTED]'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('OIDC secret migrators cannot be serialized.');
    }
}
