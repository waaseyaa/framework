<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Security;

use Waaseyaa\Database\DatabaseInterface;

/** @api */
final class LegacyOidcSecretMigrator
{
    private readonly SecretBoxEnvelope $signingKeys;
    private readonly OpaqueTokenProtector $accessTokens;
    private readonly OpaqueTokenProtector $refreshTokens;

    public function __construct(
        private readonly DatabaseInterface $database,
        #[\SensitiveParameter]
        string $signingKeyEncryptionKey,
        #[\SensitiveParameter]
        string $accessTokenEncryptionKey,
        #[\SensitiveParameter]
        string $accessTokenLookupKey,
        #[\SensitiveParameter]
        string $refreshTokenEncryptionKey,
        #[\SensitiveParameter]
        string $refreshTokenLookupKey,
    ) {
        $this->signingKeys = new SecretBoxEnvelope($signingKeyEncryptionKey);
        $this->accessTokens = new OpaqueTokenProtector($accessTokenEncryptionKey, $accessTokenLookupKey);
        $this->refreshTokens = new OpaqueTokenProtector($refreshTokenEncryptionKey, $refreshTokenLookupKey);
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

        $count = 0;
        foreach ($this->database->select('oidc_signing_key')->fields('oidc_signing_key', ['kid', 'private_key_pem'])->execute() as $row) {
            $stored = (string) $row['private_key_pem'];
            if (str_starts_with($stored, 'secretbox.hkdf-v1:')) {
                $this->signingKeys->open($stored);
                continue;
            }
            if (!str_starts_with($stored, '-----BEGIN ') || !str_contains($stored, 'PRIVATE KEY-----')) {
                throw new \RuntimeException('OIDC secret migration refused unrecognized signing-key material.');
            }

            $updated = $this->database->update('oidc_signing_key')
                ->fields(['private_key_pem' => $this->signingKeys->seal($stored)])
                ->condition('kid', (string) $row['kid'])
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
        if (!$schema->fieldExists($table, 'token_lookup')) {
            $schema->addField($table, 'token_lookup', ['type' => 'varchar', 'length' => 64]);
            $schema->addUniqueKey($table, 'idx_' . $table . '_lookup', ['token_lookup']);
        }

        $count = 0;
        foreach ($this->database->select($table)->fields($table, ['jti', 'token', 'token_lookup'])->execute() as $row) {
            $stored = (string) $row['token'];
            $lookup = $row['token_lookup'] ?? null;
            if (is_string($lookup) && $lookup !== '') {
                $protector->open($stored);
                continue;
            }
            if ($stored === '' || str_starts_with($stored, 'secretbox.hkdf-v1:')) {
                throw new \RuntimeException('OIDC secret migration refused inconsistent token material.');
            }

            $updated = $this->database->update($table)
                ->fields([
                    'token' => $protector->seal($stored),
                    'token_lookup' => $protector->lookup($stored),
                ])
                ->condition('jti', (string) $row['jti'])
                ->condition('token', $stored)
                ->execute();
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
