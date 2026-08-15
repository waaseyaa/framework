<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Security;

use Waaseyaa\Foundation\Security\ApplicationMasterAuthenticationTag;
use Waaseyaa\Foundation\Security\ApplicationMasterKeyring;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\Security\SensitiveKey;

/** @api */
final class SignedQueuePayload
{
    private const string LEGACY_PREFIX = 'hmac-sha256.hkdf-v1:';
    private const string APPLICATION_MASTER_PREFIX = 'hmac-sha256.application-master.v1:';

    private readonly ?SensitiveKey $legacyKey;

    public function __construct(
        #[\SensitiveParameter]
        ?string $key,
        private readonly ?ApplicationMasterKeyring $keyring = null,
    ) {
        if ($key !== null && strlen($key) !== 32) {
            throw new \InvalidArgumentException('Queue payload HMAC keys must be 32 bytes.');
        }
        if ($key === null && $keyring === null) {
            throw new \InvalidArgumentException('Queue payload authentication requires legacy or application-master custody.');
        }
        $this->legacyKey = $key === null ? null : new SensitiveKey($key);
    }

    public static function fromApplicationMasterKeyring(
        ApplicationMasterKeyring $keyring,
        #[\SensitiveParameter]
        ?string $legacyKey = null,
    ): self {
        return new self($legacyKey, $keyring);
    }

    public function seal(#[\SensitiveParameter] string $payload): string
    {
        if ($this->keyring !== null) {
            $tag = $this->keyring->authenticate(ApplicationSecret::PURPOSE_QUEUE_PAYLOAD_HMAC, $payload);

            return self::APPLICATION_MASTER_PREFIX
                . $tag->masterVersion . ':'
                . self::encode($tag->digestBytes()) . ':'
                . self::encode($payload);
        }

        $mac = hash_hmac('sha256', $payload, $this->requireLegacyKey()->bytes());
        $encoded = self::encode($payload);

        return self::LEGACY_PREFIX . $mac . ':' . $encoded;
    }

    public function open(string $envelope): string
    {
        if (str_starts_with($envelope, self::APPLICATION_MASTER_PREFIX)) {
            return $this->openApplicationMasterEnvelope($envelope);
        }
        if (preg_match('/^hmac-sha256\.hkdf-v1:([0-9a-f]{64}):([A-Za-z0-9_-]*)$/D', $envelope, $matches) !== 1) {
            throw self::invalid();
        }
        if ($this->legacyKey === null) {
            throw self::invalid();
        }
        $payload = self::decode($matches[2]);
        if ($payload === null) {
            throw self::invalid();
        }
        $expected = hash_hmac('sha256', $payload, $this->legacyKey->bytes());
        if (!hash_equals($expected, $matches[1])) {
            throw self::invalid();
        }

        return $payload;
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
        throw new \LogicException('Queue payload authenticators cannot be serialized.');
    }

    private static function invalid(): \RuntimeException
    {
        return new \RuntimeException('Queue payload authentication failed.');
    }

    private function openApplicationMasterEnvelope(string $envelope): string
    {
        if ($this->keyring === null
            || preg_match(
                '/^hmac-sha256\.application-master\.v1:([1-9][0-9]*):([A-Za-z0-9_-]{43}):([A-Za-z0-9_-]*)$/D',
                $envelope,
                $matches,
            ) !== 1) {
            throw self::invalid();
        }
        $masterVersion = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $digest = self::decode($matches[2]);
        $payload = self::decode($matches[3]);
        if (!is_int($masterVersion)
            || (string) $masterVersion !== $matches[1]
            || $digest === null
            || strlen($digest) !== 32
            || $payload === null) {
            throw self::invalid();
        }
        try {
            $tag = ApplicationMasterAuthenticationTag::seal(
                $masterVersion,
                ApplicationSecret::PURPOSE_QUEUE_PAYLOAD_HMAC,
                $digest,
            );
            $verified = $this->keyring->verify($tag, $payload);
        } catch (\Throwable) {
            throw self::invalid();
        }
        if (!$verified) {
            throw self::invalid();
        }

        return $payload;
    }

    private function requireLegacyKey(): SensitiveKey
    {
        return $this->legacyKey ?? throw new \LogicException('Legacy queue payload custody is unavailable.');
    }

    private static function encode(string $payload): string
    {
        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    private static function decode(string $encoded): ?string
    {
        $padding = (4 - strlen($encoded) % 4) % 4;
        $payload = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', $padding), true);

        return is_string($payload) && self::encode($payload) === $encoded ? $payload : null;
    }
}
