<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security;

/**
 * Kernel-scoped custody for the application master secret and HKDF derivation.
 *
 * Secret bytes live in a process-local WeakMap rather than an object property,
 * so ordinary object inspection cannot disclose them. Callers receive only a
 * purpose-bound 32-byte key and must never persist that key.
 *
 * @api
 */
final class ApplicationSecret
{
    public const string HKDF_SALT = 'waaseyaa.app-secret.hkdf.v1';
    public const string PURPOSE_AUDIT_CHECKPOINT_HMAC = 'waaseyaa.audit.checkpoint-hmac.v1';
    public const string PURPOSE_CACHE_PAYLOAD_HMAC = 'waaseyaa.cache.payload-hmac.v1';
    public const string PURPOSE_OIDC_SIGNING_KEY_ENCRYPTION = 'waaseyaa.oidc.signing-key-encryption.v1';
    public const string PURPOSE_OIDC_ACCESS_TOKEN_ENCRYPTION = 'waaseyaa.oidc.access-token-encryption.v1';
    public const string PURPOSE_OIDC_ACCESS_TOKEN_LOOKUP = 'waaseyaa.oidc.access-token-lookup.v1';
    public const string PURPOSE_OIDC_REFRESH_TOKEN_ENCRYPTION = 'waaseyaa.oidc.refresh-token-encryption.v1';
    public const string PURPOSE_OIDC_REFRESH_TOKEN_LOOKUP = 'waaseyaa.oidc.refresh-token-lookup.v1';

    /** @var \WeakMap<self, string>|null */
    private static ?\WeakMap $secrets = null;

    private function __construct(#[\SensitiveParameter] string $secret)
    {
        self::$secrets ??= new \WeakMap();
        self::$secrets[$this] = $secret;
    }

    /**
     * Resolve the canonical operator secret, or a per-kernel development key.
     *
     * Production-equivalent environments accept only `base64:` followed by
     * canonical RFC 4648 base64 encoding of exactly 32 bytes.
     */
    public static function fromEnvironmentValue(
        #[\SensitiveParameter]
        ?string $encodedSecret,
        string $environment,
    ): self {
        if ($encodedSecret === null || $encodedSecret === '') {
            if (self::isDevelopmentEnvironment($environment)) {
                return new self(random_bytes(32));
            }

            throw self::configurationException();
        }

        if ($encodedSecret === 'change-me' || !str_starts_with($encodedSecret, 'base64:')) {
            throw self::configurationException();
        }

        $encoded = substr($encodedSecret, strlen('base64:'));
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || strlen($decoded) !== 32 || base64_encode($decoded) !== $encoded) {
            throw self::configurationException();
        }

        return new self($decoded);
    }

    /** Return a raw 32-byte HKDF-SHA-256 key bound to a versioned purpose. */
    public function derive(string $purpose): string
    {
        if (!preg_match('/^waaseyaa\.[a-z0-9.-]+\.v[1-9][0-9]*$/D', $purpose)) {
            throw new \InvalidArgumentException('Application-secret purpose labels must be non-empty and versioned.');
        }

        $master = self::$secrets[$this] ?? null;
        if (!is_string($master)) {
            throw new \LogicException('Application-secret custody is unavailable.');
        }

        return hash_hkdf('sha256', $master, 32, $purpose, self::HKDF_SALT);
    }

    /** @return array{secret: string} */
    public function __debugInfo(): array
    {
        return ['secret' => '[REDACTED]'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Application secrets cannot be serialized.');
    }

    private function __clone() {}

    private static function isDevelopmentEnvironment(string $environment): bool
    {
        return in_array(strtolower($environment), ['local', 'dev', 'development', 'testing'], true);
    }

    private static function configurationException(): \RuntimeException
    {
        return new \RuntimeException(
            'WAASEYAA_APP_SECRET must be set to canonical base64: encoding of exactly 32 random bytes outside local development.',
        );
    }
}
