<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Security;

use Waaseyaa\Foundation\Security\SensitiveKey;

/** @api */
final class SignedQueuePayload
{
    private const PREFIX = 'hmac-sha256.hkdf-v1:';

    private readonly SensitiveKey $key;

    public function __construct(#[\SensitiveParameter] string $key)
    {
        if (strlen($key) !== 32) {
            throw new \InvalidArgumentException('Queue payload HMAC keys must be 32 bytes.');
        }
        $this->key = new SensitiveKey($key);
    }

    public function seal(#[\SensitiveParameter] string $payload): string
    {
        $mac = hash_hmac('sha256', $payload, $this->key->bytes());
        $encoded = self::encode($payload);

        return self::PREFIX . $mac . ':' . $encoded;
    }

    public function open(string $envelope): string
    {
        if (preg_match('/^hmac-sha256\.hkdf-v1:([0-9a-f]{64}):([A-Za-z0-9_-]*)$/D', $envelope, $matches) !== 1) {
            throw self::invalid();
        }
        $payload = self::decode($matches[2]);
        if ($payload === null) {
            throw self::invalid();
        }
        $expected = hash_hmac('sha256', $payload, $this->key->bytes());
        if (!hash_equals($expected, $matches[1])) {
            throw self::invalid();
        }

        return $payload;
    }

    /** @return array{key: string} */
    public function __debugInfo(): array
    {
        return ['key' => '[REDACTED]'];
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
