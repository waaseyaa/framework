<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Keys;

use RuntimeException;

final class PemFileKeyLoader implements OidcKeyLoaderInterface
{
    /**
     * @param array<string, array{algorithm?: string, public_key_path: string, private_key_path?: string}> $entries
     */
    private function __construct(private readonly array $entries) {}

    /**
     * Build a loader from `config['oidc']['signing_keys']`.
     *
     * Config shape: `[kid => ['algorithm' => 'RS256', 'public_key_path' => '...', 'private_key_path' => '...']]`.
     *
     * @param array<string, array{algorithm?: string, public_key_path: string, private_key_path?: string}> $config
     */
    public static function fromConfig(array $config): self
    {
        if ($config === []) {
            throw new RuntimeException('No OIDC signing keys configured. Set config[oidc][signing_keys] or OIDC_SIGNING_KEY_DIR.');
        }

        ksort($config);

        return new self($config);
    }

    /**
     * Scan a directory for `<kid>.pub.pem` files, optionally pairing with `<kid>.key.pem`.
     */
    public static function fromDirectory(string $directory): self
    {
        if (!is_dir($directory)) {
            throw new RuntimeException("OIDC signing key directory does not exist: {$directory}");
        }

        $publicFiles = glob(rtrim($directory, '/') . '/*.pub.pem');
        if ($publicFiles === false || $publicFiles === []) {
            throw new RuntimeException("No OIDC signing keys found in directory: {$directory}");
        }

        sort($publicFiles);

        $entries = [];
        foreach ($publicFiles as $publicFile) {
            $kid = basename($publicFile, '.pub.pem');
            $privateFile = dirname($publicFile) . '/' . $kid . '.key.pem';

            $entry = [
                'algorithm' => 'RS256',
                'public_key_path' => $publicFile,
            ];
            if (is_file($privateFile)) {
                $entry['private_key_path'] = $privateFile;
            }

            $entries[$kid] = $entry;
        }

        return new self($entries);
    }

    public function loadSigningKeys(): array
    {
        $keys = [];
        foreach ($this->entries as $kid => $entry) {
            $keys[] = new SigningKey(
                kid: $kid,
                algorithm: $entry['algorithm'] ?? 'RS256',
                publicKeyPem: $this->readPem($entry['public_key_path']),
            );
        }

        return $keys;
    }

    public function loadCurrentSigner(): SigningKeySignerInterface
    {
        foreach ($this->entries as $kid => $entry) {
            if (($entry['algorithm'] ?? 'RS256') !== 'RS256' || !isset($entry['private_key_path'])) {
                continue;
            }
            $key = new SigningKey(
                kid: $kid,
                algorithm: 'RS256',
                publicKeyPem: $this->readPem($entry['public_key_path']),
            );
            $privateKeyPem = $this->readPem($entry['private_key_path']);
            $signer = RsaSigningKeySigner::fromPrivatePem($key, $privateKeyPem);
            unset($privateKeyPem);

            return $signer;
        }

        throw new RuntimeException('No configured RS256 signing handle is available.');
    }

    private function readPem(string $pemPath): string
    {
        if (!is_file($pemPath)) {
            throw new RuntimeException("OIDC signing key file not found: {$pemPath}");
        }

        $contents = file_get_contents($pemPath);
        if ($contents === false || $contents === '') {
            throw new RuntimeException("Unable to read OIDC signing key file: {$pemPath}");
        }

        return $contents;
    }
}
