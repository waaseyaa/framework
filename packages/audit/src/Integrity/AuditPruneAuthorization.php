<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Integrity;

/** Detached authorization proving that a checkpoint segment may be pruned. @api */
final class AuditPruneAuthorization
{
    private const ENVELOPE_PREFIX = 'hmac-sha256.audit-prune.hkdf-v1:';
    private const MESSAGE_PREFIX = "waaseyaa.audit.prune-authorization.v1\0";

    public static function sign(
        string $checkpointHash,
        #[\SensitiveParameter]
        string $hmacKey,
    ): string {
        self::assertCheckpointHash($checkpointHash);
        if ($hmacKey === '') {
            throw new \InvalidArgumentException('A derived audit HMAC key is required.');
        }

        return self::ENVELOPE_PREFIX . hash_hmac(
            'sha256',
            self::MESSAGE_PREFIX . $checkpointHash,
            $hmacKey,
        );
    }

    public static function verify(
        string $authorization,
        string $checkpointHash,
        #[\SensitiveParameter]
        string $hmacKey,
    ): bool {
        if ($hmacKey === '' || preg_match('/^[a-f0-9]{64}$/D', $checkpointHash) !== 1) {
            return false;
        }
        if (preg_match('/^hmac-sha256\.audit-prune\.hkdf-v1:[a-f0-9]{64}$/D', $authorization) !== 1) {
            return false;
        }

        return hash_equals(self::sign($checkpointHash, $hmacKey), $authorization);
    }

    private static function assertCheckpointHash(string $checkpointHash): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $checkpointHash) !== 1) {
            throw new \InvalidArgumentException('Audit prune authorization requires a lowercase SHA-256 checkpoint hash.');
        }
    }
}
