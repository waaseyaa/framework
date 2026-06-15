<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Token;

use DateTimeImmutable;
use RuntimeException;

/**
 * Mints RS256-signed ID tokens per OIDC Core §2 and RFC 7519.
 *
 * Uses KeyMaterialProviderInterface so WP01's file-backed provider and WP04's
 * DB-backed provider are interchangeable without changing call sites.
 * Accepts an optional authTime: when omitted, defaults to the current iat
 * (first-time issuance); when provided, the value is preserved across refresh
 * rotations (RFC 7519 §4.1 — auth_time must reflect original authentication).
 */
final class IdTokenMinter
{
    private const ALGORITHM = 'RS256';
    private const EXPIRY_SECONDS = 3600;

    public function __construct(private readonly KeyMaterialProviderInterface $keyProvider) {}

    public function mint(
        string $issuer,
        string $subject,
        string $audience,
        ?string $nonce,
        DateTimeImmutable $now,
        ?int $authTime = null,
    ): string {
        $key = $this->keyProvider->currentKey();

        $header = [
            'alg' => self::ALGORITHM,
            'typ' => 'JWT',
            'kid' => $key->kid,
        ];

        $iat = $now->getTimestamp();
        $claims = [
            'iss' => $issuer,
            'sub' => $subject,
            'aud' => $audience,
            'exp' => $iat + self::EXPIRY_SECONDS,
            'iat' => $iat,
            'auth_time' => $authTime ?? $iat,
        ];
        if ($nonce !== null) {
            $claims['nonce'] = $nonce;
        }

        $encodedHeader = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $encodedPayload = $this->base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));
        $signingInput = $encodedHeader . '.' . $encodedPayload;

        $privateKey = $key->privateKeyPem;
        if ($privateKey === null) {
            throw new RuntimeException("Signing key kid={$key->kid} has no private key material.");
        }

        $signature = '';
        if (!openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Failed to sign ID token: ' . openssl_error_string());
        }

        return $signingInput . '.' . $this->base64UrlEncode($signature);
    }

    /**
     * Verify a JWT signature and decode its claims using the active key set.
     *
     * This is JWT (ID-token) verification. Do NOT use it on opaque OIDC access
     * tokens — those are validated by lookup (see AccessTokenIssuer / the
     * /userinfo flow), not by signature (audit C-9).
     *
     * @return array<string, mixed>|null Claims array, or null if invalid/expired.
     *
     * @api
     */
    public function verifyAndDecode(string $jwt, string $expectedIssuer, string $expectedAudience): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        [$encodedHeader, $encodedPayload, $encodedSig] = $parts;

        $header = json_decode($this->base64UrlDecode($encodedHeader), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($header)) {
            return null;
        }

        $kid = isset($header['kid']) && is_string($header['kid']) ? $header['kid'] : null;

        // Find the matching key from the active set
        $matchedKey = null;
        foreach ($this->keyProvider->allActive() as $key) {
            if ($kid === null || $key->kid === $kid) {
                $matchedKey = $key;
                break;
            }
        }

        if ($matchedKey === null) {
            return null;
        }

        $signingInput = $encodedHeader . '.' . $encodedPayload;
        $sig = $this->base64UrlDecode($encodedSig);

        $resource = openssl_pkey_get_public($matchedKey->publicKeyPem);
        if ($resource === false) {
            return null;
        }

        if (openssl_verify($signingInput, $sig, $resource, OPENSSL_ALGO_SHA256) !== 1) {
            return null;
        }

        $claims = json_decode($this->base64UrlDecode($encodedPayload), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($claims)) {
            return null;
        }

        // Validate standard claims
        if (($claims['iss'] ?? null) !== $expectedIssuer) {
            return null;
        }

        $aud = $claims['aud'] ?? null;
        if ($aud !== $expectedAudience && (!is_array($aud) || !in_array($expectedAudience, $aud, true))) {
            return null;
        }

        if (!isset($claims['exp']) || !is_int($claims['exp']) || $claims['exp'] < time()) {
            return null;
        }

        return $claims;
    }

    private function base64UrlEncode(string $input): string
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $input): string
    {
        return (string) base64_decode(strtr($input, '-_', '+/'), true);
    }
}
