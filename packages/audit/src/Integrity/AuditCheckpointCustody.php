<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Integrity;

use Waaseyaa\Foundation\Security\ApplicationMasterAuthenticationTag;
use Waaseyaa\Foundation\Security\ApplicationMasterKeyring;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\Security\SensitiveKey;

/** Version-bound authentication boundary for audit checkpoint evidence. @api */
final class AuditCheckpointCustody
{
    public const string CHECKPOINT_PREFIX = 'hmac-sha256.application-master.audit-checkpoint.v1:';
    public const string PRUNE_PREFIX = 'hmac-sha256.application-master.audit-prune.v1:';
    public const string SUCCESSION_PREFIX = 'hmac-sha256.application-master.audit-succession.v1:';

    private const string LEGACY_CHECKPOINT_PREFIX = 'hmac-sha256.hkdf-v1:';
    private const string CHECKPOINT_DOMAIN = "waaseyaa.audit.checkpoint-signature.v1\0";
    private const string PRUNE_DOMAIN = "waaseyaa.audit.prune-authorization.v1\0";
    private const string SUCCESSION_DOMAIN = "waaseyaa.audit.checkpoint-succession.v1\0";

    private readonly ?SensitiveKey $legacyKey;

    public function __construct(
        private readonly ?ApplicationMasterKeyring $keyring = null,
        #[\SensitiveParameter]
        ?string $legacyKey = null,
    ) {
        if ($legacyKey === '') {
            throw new \InvalidArgumentException('Legacy audit checkpoint HMAC keys must be non-empty.');
        }
        if ($keyring === null && $legacyKey === null) {
            throw new \InvalidArgumentException('Audit checkpoint custody requires an application-master keyring or legacy key.');
        }
        $this->legacyKey = $legacyKey === null ? null : new SensitiveKey($legacyKey);
    }

    public function sealCheckpoint(string $checkpointHash): string
    {
        self::assertCheckpointHash($checkpointHash);
        if ($this->keyring !== null) {
            return $this->sealVersioned(self::CHECKPOINT_PREFIX, self::CHECKPOINT_DOMAIN, $checkpointHash);
        }

        return self::LEGACY_CHECKPOINT_PREFIX
            . hash_hmac('sha256', $checkpointHash, $this->requireLegacyKey()->bytes());
    }

    public function verifyCheckpoint(string $envelope, string $checkpointHash): bool
    {
        if (!self::isCheckpointHash($checkpointHash)) {
            return false;
        }
        if (str_starts_with($envelope, self::CHECKPOINT_PREFIX)) {
            return $this->verifyVersioned(
                $envelope,
                self::CHECKPOINT_PREFIX,
                self::CHECKPOINT_DOMAIN,
                $checkpointHash,
            );
        }
        if ($this->legacyKey === null
            || preg_match('/^hmac-sha256\.hkdf-v1:([0-9a-f]{64})$/D', $envelope, $matches) !== 1) {
            return false;
        }

        return hash_equals(
            hash_hmac('sha256', $checkpointHash, $this->legacyKey->bytes()),
            $matches[1],
        );
    }

    public function sealPruneAuthorization(string $checkpointHash): string
    {
        self::assertCheckpointHash($checkpointHash);
        if ($this->keyring !== null) {
            return $this->sealVersioned(self::PRUNE_PREFIX, self::PRUNE_DOMAIN, $checkpointHash);
        }

        return AuditPruneAuthorization::sign($checkpointHash, $this->requireLegacyKey()->bytes());
    }

    public function verifyPruneAuthorization(string $envelope, string $checkpointHash): bool
    {
        if (!self::isCheckpointHash($checkpointHash)) {
            return false;
        }
        if (str_starts_with($envelope, self::PRUNE_PREFIX)) {
            return $this->verifyVersioned(
                $envelope,
                self::PRUNE_PREFIX,
                self::PRUNE_DOMAIN,
                $checkpointHash,
            );
        }

        return $this->legacyKey !== null
            && AuditPruneAuthorization::verify($envelope, $checkpointHash, $this->legacyKey->bytes());
    }

    public function sealSuccessionAnchor(string $canonicalBody): string
    {
        if ($canonicalBody === '') {
            throw new \InvalidArgumentException('Audit succession anchor bodies cannot be empty.');
        }
        if ($this->keyring === null) {
            throw new \LogicException('Audit succession anchors require application-master custody.');
        }

        return $this->sealVersioned(self::SUCCESSION_PREFIX, self::SUCCESSION_DOMAIN, $canonicalBody);
    }

    public function verifySuccessionAnchor(string $envelope, string $canonicalBody): bool
    {
        return $canonicalBody !== '' && $this->verifyVersioned(
            $envelope,
            self::SUCCESSION_PREFIX,
            self::SUCCESSION_DOMAIN,
            $canonicalBody,
        );
    }

    public function activeVersion(): ?int
    {
        return $this->keyring?->activeVersion();
    }

    /** @return list<int> */
    public function readableVersions(): array
    {
        return $this->keyring?->readableVersions() ?? [];
    }

    public function referenceFingerprint(int $version): string
    {
        return $this->keyring?->referenceFingerprint($version)
            ?? throw new \RuntimeException('Application-master audit custody is unavailable.');
    }

    public function checkpointEnvelopeVersion(string $envelope): ?int
    {
        return self::versionFromEnvelope($envelope, self::CHECKPOINT_PREFIX);
    }

    public function pruneEnvelopeVersion(string $envelope): ?int
    {
        return self::versionFromEnvelope($envelope, self::PRUNE_PREFIX);
    }

    public function successionEnvelopeVersion(string $envelope): ?int
    {
        return self::versionFromEnvelope($envelope, self::SUCCESSION_PREFIX);
    }

    /** @return array{legacy_key: string|null, application_master: string|null} */
    public function __debugInfo(): array
    {
        return [
            'legacy_key' => $this->legacyKey === null ? null : '[REDACTED]',
            'application_master' => $this->keyring === null ? null : '[NON_EXPORTING]',
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Audit checkpoint custody cannot be serialized.');
    }

    private function sealVersioned(string $prefix, string $domain, string $message): string
    {
        $keyring = $this->keyring
            ?? throw new \LogicException('Application-master audit custody is unavailable.');
        $tag = $keyring->authenticate(
            ApplicationSecret::PURPOSE_AUDIT_CHECKPOINT_HMAC,
            $domain . $message,
        );

        return $prefix . $tag->masterVersion . ':' . self::encode($tag->digestBytes());
    }

    private function verifyVersioned(
        string $envelope,
        string $prefix,
        string $domain,
        string $message,
    ): bool {
        if ($this->keyring === null) {
            return false;
        }
        $version = self::versionFromEnvelope($envelope, $prefix);
        if ($version === null) {
            return false;
        }
        $encodedDigest = substr($envelope, strlen($prefix . $version . ':'));
        $digest = self::decode($encodedDigest);
        if ($digest === null || strlen($digest) !== 32) {
            return false;
        }

        try {
            return $this->keyring->verify(
                ApplicationMasterAuthenticationTag::seal(
                    $version,
                    ApplicationSecret::PURPOSE_AUDIT_CHECKPOINT_HMAC,
                    $digest,
                ),
                $domain . $message,
            );
        } catch (\Throwable) {
            return false;
        }
    }

    private static function versionFromEnvelope(string $envelope, string $prefix): ?int
    {
        if (preg_match(
            '/^' . preg_quote($prefix, '/') . '([1-9][0-9]*):([A-Za-z0-9_-]{43})$/D',
            $envelope,
            $matches,
        ) !== 1) {
            return null;
        }
        $version = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($version) && (string) $version === $matches[1] ? $version : null;
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode(string $encoded): ?string
    {
        $padding = (4 - strlen($encoded) % 4) % 4;
        $bytes = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', $padding), true);

        return is_string($bytes) && self::encode($bytes) === $encoded ? $bytes : null;
    }

    private function requireLegacyKey(): SensitiveKey
    {
        return $this->legacyKey
            ?? throw new \LogicException('Legacy audit checkpoint custody is unavailable.');
    }

    private static function assertCheckpointHash(string $checkpointHash): void
    {
        if (!self::isCheckpointHash($checkpointHash)) {
            throw new \InvalidArgumentException('Audit checkpoint hashes must be 64 lowercase hexadecimal characters.');
        }
    }

    private static function isCheckpointHash(string $checkpointHash): bool
    {
        return preg_match('/^[0-9a-f]{64}$/D', $checkpointHash) === 1;
    }
}
