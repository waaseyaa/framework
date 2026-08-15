<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Security;

use Waaseyaa\Foundation\Security\ApplicationMasterEnvelope;
use Waaseyaa\Foundation\Security\ApplicationMasterKeyring;
use Waaseyaa\Foundation\Security\SensitiveKey;

/** @api */
final class SecretBoxEnvelope
{
    private const PREFIX = 'secretbox.hkdf-v1:';
    private const SCHEMA_VERSION = 1;

    private readonly ?SensitiveKey $legacyKey;

    public function __construct(
        #[\SensitiveParameter]
        ?string $key,
        private readonly ?ApplicationMasterKeyring $keyring = null,
        private readonly ?string $purpose = null,
    ) {
        if ($key !== null && strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException('OIDC encryption keys must be 32 bytes.');
        }
        if ($keyring === null && $key === null) {
            throw new \InvalidArgumentException('OIDC encrypted material requires legacy or application-master custody.');
        }
        if (($keyring === null) !== ($purpose === null)) {
            throw new \InvalidArgumentException('OIDC application-master envelopes require an exact purpose.');
        }

        $this->legacyKey = $key === null ? null : new SensitiveKey($key);
    }

    public static function fromApplicationMasterKeyring(
        ApplicationMasterKeyring $keyring,
        string $purpose,
        #[\SensitiveParameter]
        ?string $legacyKey = null,
    ): self {
        return new self($legacyKey, $keyring, $purpose);
    }

    public function seal(#[\SensitiveParameter] string $plaintext, ?string $recordIdentity = null): string
    {
        if ($this->keyring !== null) {
            if ($recordIdentity === null) {
                throw new \InvalidArgumentException('OIDC application-master envelopes require a record identity.');
            }

            return json_encode(
                $this->keyring->seal(
                    $this->purpose ?? throw new \LogicException('OIDC envelope purpose is unavailable.'),
                    $recordIdentity,
                    self::SCHEMA_VERSION,
                    $plaintext,
                )->toArray(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->requireLegacyKey()->bytes());

        return self::PREFIX . sodium_bin2base64($nonce . $ciphertext, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    public function open(string $envelope, ?string $recordIdentity = null): string
    {
        if (!str_starts_with($envelope, self::PREFIX)) {
            return $this->openApplicationMasterEnvelope($envelope, $recordIdentity);
        }
        if ($this->legacyKey === null) {
            throw self::invalidEnvelope();
        }

        return $this->openLegacyEnvelope($envelope);
    }

    public function applicationMasterVersion(string $envelope): ?int
    {
        if (str_starts_with($envelope, self::PREFIX)) {
            return null;
        }
        try {
            return $this->decodeApplicationMasterEnvelope($envelope)->masterVersion;
        } catch (\Throwable) {
            throw self::invalidEnvelope();
        }
    }

    /** @return array{legacy_key: string|null, application_master: string|null, purpose: string|null} */
    public function __debugInfo(): array
    {
        return [
            'legacy_key' => $this->legacyKey === null ? null : '[REDACTED]',
            'application_master' => $this->keyring === null ? null : '[NON_EXPORTING]',
            'purpose' => $this->purpose,
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('OIDC secret envelopes cannot be serialized.');
    }

    private function openLegacyEnvelope(string $envelope): string
    {
        if (!str_starts_with($envelope, self::PREFIX)) {
            throw self::invalidEnvelope();
        }

        $encoded = substr($envelope, strlen(self::PREFIX));
        try {
            $payload = sodium_base642bin($encoded, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
        } catch (\SodiumException) {
            throw self::invalidEnvelope();
        }

        if (strlen($payload) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw self::invalidEnvelope();
        }

        $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->requireLegacyKey()->bytes());
        if ($plaintext === false) {
            throw self::invalidEnvelope();
        }

        return $plaintext;
    }

    private function openApplicationMasterEnvelope(string $encoded, ?string $recordIdentity): string
    {
        if ($this->keyring === null || $recordIdentity === null) {
            throw self::invalidEnvelope();
        }
        try {
            $envelope = $this->decodeApplicationMasterEnvelope($encoded);
            if ($envelope->purpose !== $this->purpose
                || $envelope->recordIdentity !== $recordIdentity
                || $envelope->schemaVersion !== self::SCHEMA_VERSION) {
                throw new \RuntimeException('OIDC envelope identity is not exact.');
            }

            return $this->keyring->open($envelope);
        } catch (\Throwable $failure) {
            if (str_contains($failure->getMessage(), 'identity')) {
                throw new \RuntimeException('OIDC encrypted material identity could not be authenticated.');
            }
            throw self::invalidEnvelope();
        }
    }

    private function decodeApplicationMasterEnvelope(string $encoded): ApplicationMasterEnvelope
    {
        $document = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($document)) {
            throw self::invalidEnvelope();
        }

        return ApplicationMasterEnvelope::fromArray($document);
    }

    private function requireLegacyKey(): SensitiveKey
    {
        return $this->legacyKey
            ?? throw new \LogicException('Legacy OIDC encryption custody is unavailable.');
    }

    private static function invalidEnvelope(): \RuntimeException
    {
        return new \RuntimeException('OIDC encrypted material could not be authenticated.');
    }
}
