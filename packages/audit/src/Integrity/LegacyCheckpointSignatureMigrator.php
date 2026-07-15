<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Integrity;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Security\SensitiveKey;

/**
 * Explicitly upgrades an intact, wholly legacy checkpoint chain to HKDF-v1.
 *
 * The operator must establish trust in the current database/backup before
 * invoking this migration. Ordinary verification never performs this write.
 *
 * @api
 */
final class LegacyCheckpointSignatureMigrator
{
    private readonly SensitiveKey $hmacKey;

    public function __construct(
        private readonly DatabaseInterface $database,
        #[\SensitiveParameter]
        string $hmacKey,
    ) {
        if ($hmacKey === '') {
            throw new \InvalidArgumentException('A derived audit HMAC key is required.');
        }

        $this->hmacKey = new SensitiveKey($hmacKey);
    }

    public function migrate(): int
    {
        $transaction = $this->database->transaction('audit_checkpoint_signature_migration');

        try {
            $checkpoints = iterator_to_array(
                $this->database->select('audit_checkpoint')
                    ->fields('audit_checkpoint', ['id', 'checkpoint_hash', 'signature'])
                    ->orderBy('segment_end_id', 'ASC')
                    ->execute(),
                false,
            );

            if ($checkpoints === []) {
                $transaction->commit();

                return 0;
            }

            $legacyCount = 0;
            $versionedCount = 0;
            foreach ($checkpoints as $checkpoint) {
                $signature = (string) ($checkpoint['signature'] ?? '');
                if (str_starts_with($signature, 'hmac-sha256.hkdf-v1:')) {
                    ++$versionedCount;
                } elseif ($signature === '' || preg_match('/^[0-9a-f]{64}$/D', $signature) === 1) {
                    ++$legacyCount;
                } else {
                    throw new \RuntimeException('Legacy checkpoint signature migration refused malformed history.');
                }
            }

            if ($versionedCount > 0 && $legacyCount > 0) {
                throw new \RuntimeException('Legacy checkpoint signature migration refused mixed authenticated and legacy history.');
            }

            if ($versionedCount > 0) {
                $result = new AuditChainVerifier($this->database, hmacKey: $this->hmacKey->bytes())->verify();
                if (!$result->ok) {
                    throw new \RuntimeException('Legacy checkpoint signature migration refused invalid authenticated history.');
                }
                $transaction->commit();

                return 0;
            }

            $preflight = new AuditChainVerifier($this->database)->verify();
            if (!$preflight->ok) {
                throw new \RuntimeException('Legacy checkpoint signature migration refused a broken hash chain.');
            }

            foreach ($checkpoints as $checkpoint) {
                $oldSignature = (string) $checkpoint['signature'];
                $checkpointHash = (string) $checkpoint['checkpoint_hash'];
                $updated = $this->database->update('audit_checkpoint')
                    ->fields(['signature' => 'hmac-sha256.hkdf-v1:' . hash_hmac('sha256', $checkpointHash, $this->hmacKey->bytes())])
                    ->condition('id', (int) $checkpoint['id'])
                    ->condition('checkpoint_hash', $checkpointHash)
                    ->condition('signature', $oldSignature)
                    ->execute();
                if ($updated !== 1) {
                    throw new \RuntimeException('Legacy checkpoint signature migration refused a concurrent checkpoint change.');
                }
            }

            $postflight = new AuditChainVerifier($this->database, hmacKey: $this->hmacKey->bytes())->verify();
            if (!$postflight->ok) {
                throw new \RuntimeException('Legacy checkpoint signature migration refused to commit an invalid authenticated chain.');
            }

            $transaction->commit();

            return $legacyCount;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /** @return array{database: string, hmac_key: string} */
    public function __debugInfo(): array
    {
        return ['database' => $this->database::class, 'hmac_key' => '[REDACTED]'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Audit checkpoint signature migrators cannot be serialized.');
    }
}
