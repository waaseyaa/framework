<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Security;

use Waaseyaa\Foundation\Security\ApplicationMasterAuthenticationTag;
use Waaseyaa\Foundation\Security\ApplicationMasterKeyring;
use Waaseyaa\Foundation\Security\SensitiveKey;

/** @api */
final class OpaqueTokenProtector
{
    private readonly SecretBoxEnvelope $envelope;
    private readonly ?SensitiveKey $legacyLookupKey;

    public function __construct(
        #[\SensitiveParameter]
        ?string $encryptionKey,
        #[\SensitiveParameter]
        ?string $lookupKey,
        private readonly ?ApplicationMasterKeyring $keyring = null,
        ?string $encryptionPurpose = null,
        private readonly ?string $lookupPurpose = null,
    ) {
        if ($lookupKey !== null && strlen($lookupKey) !== 32) {
            throw new \InvalidArgumentException('OIDC token lookup keys must be 32 bytes.');
        }
        if ($keyring === null && ($encryptionKey === null || $lookupKey === null)) {
            throw new \InvalidArgumentException('OIDC token protection requires complete legacy or application-master custody.');
        }
        if ($keyring !== null && ($encryptionPurpose === null || $lookupPurpose === null)) {
            throw new \InvalidArgumentException('OIDC application-master token custody requires exact purposes.');
        }

        $this->envelope = $keyring === null
            ? new SecretBoxEnvelope($encryptionKey)
            : SecretBoxEnvelope::fromApplicationMasterKeyring(
                $keyring,
                $encryptionPurpose ?? throw new \LogicException('OIDC token encryption purpose is unavailable.'),
                $encryptionKey,
            );
        $this->legacyLookupKey = $lookupKey === null ? null : new SensitiveKey($lookupKey);
    }

    public static function fromApplicationMasterKeyring(
        ApplicationMasterKeyring $keyring,
        string $encryptionPurpose,
        string $lookupPurpose,
        #[\SensitiveParameter]
        ?string $legacyEncryptionKey = null,
        #[\SensitiveParameter]
        ?string $legacyLookupKey = null,
    ): self {
        return new self(
            $legacyEncryptionKey,
            $legacyLookupKey,
            $keyring,
            $encryptionPurpose,
            $lookupPurpose,
        );
    }

    public function seal(#[\SensitiveParameter] string $token, ?string $recordIdentity = null): string
    {
        return $this->envelope->seal($token, $recordIdentity);
    }

    public function open(string $envelope, ?string $recordIdentity = null): string
    {
        return $this->envelope->open($envelope, $recordIdentity);
    }

    public function lookup(#[\SensitiveParameter] string $token): string
    {
        if ($this->keyring === null) {
            return $this->legacyLookup($token);
        }
        foreach ($this->keyring->lookupCandidates($this->requireLookupPurpose(), $token) as $tag) {
            if ($tag->masterVersion === $this->keyring->activeVersion()) {
                return self::encodeLookupTag($tag);
            }
        }

        throw new \LogicException('OIDC active lookup version is unavailable.');
    }

    /** @return list<string> */
    public function lookupCandidates(#[\SensitiveParameter] string $token): array
    {
        if ($this->keyring === null) {
            return [$this->legacyLookup($token)];
        }
        $candidates = array_map(
            self::encodeLookupTag(...),
            $this->keyring->lookupCandidates($this->requireLookupPurpose(), $token),
        );
        if ($this->legacyLookupKey !== null) {
            $candidates[] = $this->legacyLookup($token);
        }

        return $candidates;
    }

    public function ciphertextVersion(string $envelope): ?int
    {
        return $this->envelope->applicationMasterVersion($envelope);
    }

    public function lookupVersion(string $lookup): ?int
    {
        if (preg_match('/^v([1-9][0-9]*):([A-Za-z0-9_-]{43})$/D', $lookup, $matches) !== 1) {
            return null;
        }
        $version = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($version) || (string) $version !== $matches[1]) {
            return null;
        }
        $digest = self::decode($matches[2]);
        if ($digest === null || strlen($digest) !== 32) {
            return null;
        }

        return $version;
    }

    /** @return array{envelope: SecretBoxEnvelope, lookup_key: string|null, application_master: string|null, lookup_purpose: string|null} */
    public function __debugInfo(): array
    {
        return [
            'envelope' => $this->envelope,
            'lookup_key' => $this->legacyLookupKey === null ? null : '[REDACTED]',
            'application_master' => $this->keyring === null ? null : '[NON_EXPORTING]',
            'lookup_purpose' => $this->lookupPurpose,
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('OIDC token protectors cannot be serialized.');
    }

    private function legacyLookup(#[\SensitiveParameter] string $token): string
    {
        return hash_hmac('sha256', $token, $this->requireLegacyLookupKey()->bytes());
    }

    private function requireLegacyLookupKey(): SensitiveKey
    {
        return $this->legacyLookupKey
            ?? throw new \LogicException('Legacy OIDC lookup custody is unavailable.');
    }

    private function requireLookupPurpose(): string
    {
        return $this->lookupPurpose
            ?? throw new \LogicException('OIDC token lookup purpose is unavailable.');
    }

    private static function encodeLookupTag(ApplicationMasterAuthenticationTag $tag): string
    {
        return 'v' . $tag->masterVersion . ':' . self::encode($tag->digestBytes());
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
}
