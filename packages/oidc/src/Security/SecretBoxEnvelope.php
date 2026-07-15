<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Security;

use Waaseyaa\Foundation\Security\SensitiveKey;

/** @api */
final class SecretBoxEnvelope
{
    private const PREFIX = 'secretbox.hkdf-v1:';

    private readonly SensitiveKey $key;

    public function __construct(#[\SensitiveParameter] string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException('OIDC encryption keys must be 32 bytes.');
        }

        $this->key = new SensitiveKey($key);
    }

    public function seal(#[\SensitiveParameter] string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key->bytes());

        return self::PREFIX . sodium_bin2base64($nonce . $ciphertext, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    public function open(string $envelope): string
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
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key->bytes());
        if ($plaintext === false) {
            throw self::invalidEnvelope();
        }

        return $plaintext;
    }

    /** @return array{key: string} */
    public function __debugInfo(): array
    {
        return ['key' => '[REDACTED]'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('OIDC secret envelopes cannot be serialized.');
    }

    private static function invalidEnvelope(): \RuntimeException
    {
        return new \RuntimeException('OIDC encrypted material could not be authenticated.');
    }
}
