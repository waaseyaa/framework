<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Jwks;

use RuntimeException;
use Waaseyaa\Oidc\Keys\SigningAlgorithmPolicy;
use Waaseyaa\Oidc\Keys\SigningKey;

/**
 * Builds a JWKS document (RFC 7517) from a list of signing keys.
 *
 * Pure function — no side effects, no I/O. Used by JwksController and tests.
 *
 * @api
 */
final class JwksDocumentBuilder
{
    private readonly SigningAlgorithmPolicy $algorithmPolicy;

    public function __construct(?SigningAlgorithmPolicy $algorithmPolicy = null)
    {
        $this->algorithmPolicy = $algorithmPolicy ?? new SigningAlgorithmPolicy();
    }

    /**
     * @param list<SigningKey> $keys
     * @return array<string, mixed>
     */
    public function build(array $keys): array
    {
        $keys = array_values(array_filter(
            $keys,
            static fn(SigningKey $key): bool => $key->state->canVerify(),
        ));

        return [
            'keys' => array_map(fn(SigningKey $key): array => $this->toJwk($key), $keys),
        ];
    }

    /**
     * @return array{kty: string, use: string, alg: string, kid: string, n: string, e: string}
     */
    private function toJwk(SigningKey $key): array
    {
        $this->algorithmPolicy->assertAllowed($key->algorithm);
        $resource = openssl_pkey_get_public($key->publicKeyPem);
        if ($resource === false) {
            throw new RuntimeException("Unable to parse public key PEM for kid={$key->kid}.");
        }

        $details = openssl_pkey_get_details($resource);
        if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new RuntimeException("Public key for kid={$key->kid} is not RSA.");
        }

        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => $key->algorithm,
            'kid' => $key->kid,
            'n' => $this->base64UrlEncode((string) $details['rsa']['n']),
            'e' => $this->base64UrlEncode((string) $details['rsa']['e']),
        ];
    }

    private function base64UrlEncode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
