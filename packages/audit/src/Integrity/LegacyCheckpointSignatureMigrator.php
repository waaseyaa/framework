<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Integrity;

use Waaseyaa\Database\DatabaseInterface;

/**
 * Explicitly upgrades an intact, wholly legacy checkpoint chain to active custody.
 *
 * The operator must establish trust in the current database/backup before
 * invoking this migration. Ordinary verification never performs this write.
 *
 * @api
 */
final class LegacyCheckpointSignatureMigrator
{
    private readonly AuditCheckpointCustody $custody;

    public function __construct(
        private readonly DatabaseInterface $database,
        #[\SensitiveParameter]
        ?string $hmacKey = null,
        ?AuditCheckpointCustody $custody = null,
    ) {
        if ($custody !== null && $hmacKey !== null && $hmacKey !== '') {
            throw new \InvalidArgumentException('Supply composed audit custody or a legacy HMAC key, not both.');
        }
        if ($custody === null && ($hmacKey === null || $hmacKey === '')) {
            throw new \InvalidArgumentException('Audit checkpoint custody is required.');
        }

        $this->custody = $custody ?? new AuditCheckpointCustody(legacyKey: $hmacKey);
    }

    public function migrate(): int
    {
        $transaction = $this->database->transaction('audit_checkpoint_signature_migration');

        try {
            $checkpoints = iterator_to_array(
                $this->database->select('audit_checkpoint')
                    ->fields('audit_checkpoint', [
                        'id', 'checkpoint_hash', 'signature', 'pruned', 'prune_authorization',
                    ])
                    ->orderBy('segment_end_id', 'ASC')
                    ->execute(),
                false,
            );

            if ($checkpoints === []) {
                $transaction->commit();

                return 0;
            }

            $legacyCount = 0;
            $currentCount = 0;
            $legacyHkdfCount = 0;
            $unauthenticatedLegacyCount = 0;
            foreach ($checkpoints as $checkpoint) {
                $signature = (string) ($checkpoint['signature'] ?? '');
                $isApplicationMaster = $this->custody->checkpointEnvelopeVersion($signature) !== null;
                $isLegacyHkdf = str_starts_with($signature, 'hmac-sha256.hkdf-v1:');
                if ($isApplicationMaster || ($this->custody->activeVersion() === null && $isLegacyHkdf)) {
                    ++$currentCount;
                } elseif ($signature === ''
                    || preg_match('/^[0-9a-f]{64}$/D', $signature) === 1
                    || ($this->custody->activeVersion() !== null && $isLegacyHkdf)) {
                    ++$legacyCount;
                    if ($isLegacyHkdf) {
                        ++$legacyHkdfCount;
                    } else {
                        ++$unauthenticatedLegacyCount;
                    }
                } else {
                    throw new \RuntimeException('Legacy checkpoint signature migration refused malformed history.');
                }
            }

            if ($currentCount > 0 && $legacyCount > 0) {
                throw new \RuntimeException('Legacy checkpoint signature migration refused mixed authenticated and legacy history.');
            }
            if ($legacyHkdfCount > 0 && $unauthenticatedLegacyCount > 0) {
                throw new \RuntimeException('Legacy checkpoint signature migration refused mixed authenticated and unauthenticated legacy history.');
            }

            if ($legacyCount > 0) {
                $preflight = new AuditChainVerifier(
                    $this->database,
                    custody: $legacyHkdfCount > 0 ? $this->custody : null,
                )->verify();
                if (!$preflight->ok) {
                    throw new \RuntimeException($legacyHkdfCount > 0
                        ? 'Legacy checkpoint signature migration refused history without verifiable compatibility custody.'
                        : 'Legacy checkpoint signature migration refused a broken hash chain.');
                }
            }

            foreach ($checkpoints as $checkpoint) {
                $oldSignature = (string) $checkpoint['signature'];
                $oldPruneAuthorization = (string) ($checkpoint['prune_authorization'] ?? '');
                $checkpointHash = (string) $checkpoint['checkpoint_hash'];
                $fields = [];
                if ($legacyCount > 0) {
                    $fields['signature'] = $this->custody->sealCheckpoint($checkpointHash);
                }
                if ((bool) ($checkpoint['pruned'] ?? false)) {
                    $fields['prune_authorization'] = $this->custody->sealPruneAuthorization($checkpointHash);
                }
                if ($fields === []) {
                    continue;
                }
                $updated = $this->database->update('audit_checkpoint')
                    ->fields($fields)
                    ->condition('id', (int) $checkpoint['id'])
                    ->condition('checkpoint_hash', $checkpointHash)
                    ->condition('signature', $oldSignature)
                    ->condition('prune_authorization', $oldPruneAuthorization)
                    ->execute();
                if ($updated !== 1) {
                    throw new \RuntimeException('Legacy checkpoint signature migration refused a concurrent checkpoint change.');
                }
            }

            $postflight = new AuditChainVerifier($this->database, custody: $this->custody)->verify();
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

    /** @return array{database: string, custody: string} */
    public function __debugInfo(): array
    {
        return ['database' => $this->database::class, 'custody' => '[NON_EXPORTING]'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Audit checkpoint signature migrators cannot be serialized.');
    }
}
