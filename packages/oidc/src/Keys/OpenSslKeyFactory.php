<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Keys;

use RuntimeException;

/**
 * Creates OpenSSL key material with a portable config fallback.
 *
 * Some Windows PHP builds point OpenSSL's default config at
 * `C:\Program Files\Common Files\SSL\openssl.cnf` even when the usable PHP
 * config lives beside the PHP binary. Key generation then fails unless the
 * config is passed explicitly.
 *
 * @api
 */
final class OpenSslKeyFactory
{
    /**
     * @return array{private: string, public: string}
     */
    public function generateRsaKeyPair(int $bits = 2048): array
    {
        [$resource, $config] = $this->newPrivateKey([
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $privateKeyPem = '';
        if (!openssl_pkey_export($resource, $privateKeyPem, null, $config)) {
            throw new RuntimeException('Failed to export private key PEM: ' . $this->drainOpenSslErrors());
        }

        $details = openssl_pkey_get_details($resource);
        if ($details === false) {
            throw new RuntimeException('Failed to get public key details: ' . $this->drainOpenSslErrors());
        }

        $publicKeyPem = (string) ($details['key'] ?? '');
        if ($publicKeyPem === '') {
            throw new RuntimeException('Empty public key PEM.');
        }

        return ['private' => $privateKeyPem, 'public' => $publicKeyPem];
    }

    public function generateEcPublicKey(string $curveName = 'prime256v1'): string
    {
        [$resource] = $this->newPrivateKey([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => $curveName,
        ]);

        $details = openssl_pkey_get_details($resource);
        if ($details === false) {
            throw new RuntimeException('Failed to get EC public key details: ' . $this->drainOpenSslErrors());
        }

        $publicKeyPem = (string) ($details['key'] ?? '');
        if ($publicKeyPem === '') {
            throw new RuntimeException('Empty EC public key PEM.');
        }

        return $publicKeyPem;
    }

    /**
     * @param array<string, mixed> $options
     * @return array{0: \OpenSSLAsymmetricKey, 1: array<string, mixed>}
     */
    private function newPrivateKey(array $options): array
    {
        foreach ($this->candidateConfigs() as $config) {
            $this->drainOpenSslErrors();
            $effectiveOptions = $config === null ? $options : $options + ['config' => $config];
            $resource = openssl_pkey_new($effectiveOptions);
            if ($resource instanceof \OpenSSLAsymmetricKey) {
                return [$resource, $config === null ? [] : ['config' => $config]];
            }
        }

        throw new RuntimeException('Failed to generate OpenSSL keypair: ' . $this->drainOpenSslErrors());
    }

    /**
     * @return list<string|null>
     */
    private function candidateConfigs(): array
    {
        $configs = [null];

        $env = getenv('OPENSSL_CONF');
        if (is_string($env) && $env !== '' && is_file($env)) {
            $configs[] = $env;
        }

        $phpDir = dirname(PHP_BINARY);
        foreach ([
            $phpDir . '/extras/ssl/openssl.cnf',
            $phpDir . '/../extras/ssl/openssl.cnf',
            'C:/tools/php85/extras/ssl/openssl.cnf',
            'C:/Program Files/Git/mingw64/etc/ssl/openssl.cnf',
            'C:/Program Files/Git/usr/ssl/openssl.cnf',
        ] as $candidate) {
            if (is_file($candidate)) {
                $configs[] = $candidate;
            }
        }

        return array_values(array_unique($configs, SORT_REGULAR));
    }

    private function drainOpenSslErrors(): string
    {
        $errors = [];
        while (($error = openssl_error_string()) !== false) {
            $errors[] = $error;
        }

        return $errors === [] ? 'unknown OpenSSL error' : implode('; ', $errors);
    }
}
