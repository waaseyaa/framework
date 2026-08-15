<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Manifest;

/** Closed public-key trust and revocation policy for CFG-03 envelopes. @api */
final readonly class ConfigManifestTrustPolicy
{
    /** @var array<string, array{algorithm: string, public_key: string}> */
    private array $keys;

    /** @var array<string, true> */
    private array $revoked;

    /**
     * @param array<string, array<mixed>> $keys
     * @param list<string> $revoked
     */
    public function __construct(array $keys, array $revoked = [])
    {
        $decoded = [];
        foreach ($keys as $reference => $entry) {
            $this->assertReference($reference);
            $entryKeys = array_keys($entry);
            sort($entryKeys, SORT_STRING);
            if ($entryKeys !== ['algorithm', 'public_key']
                || !is_string($entry['algorithm'])
                || !is_string($entry['public_key'])
                || $entry['algorithm'] !== SignedConfigManifestEnvelope::ALGORITHM_V1) {
                throw new \InvalidArgumentException('CFG-04 manifest trust entry is malformed or unsupported.');
            }
            $publicKey = base64_decode($entry['public_key'], true);
            if (!is_string($publicKey)
                || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                || base64_encode($publicKey) !== $entry['public_key']) {
                throw new \InvalidArgumentException('CFG-04 manifest trust public key is not canonical Ed25519 material.');
            }
            $decoded[$reference] = [
                'algorithm' => $entry['algorithm'],
                'public_key' => $publicKey,
            ];
        }
        $revokedSet = [];
        foreach ($revoked as $reference) {
            $this->assertReference($reference);
            if (!isset($decoded[$reference])) {
                throw new \InvalidArgumentException('CFG-04 revoked manifest trust reference is unknown.');
            }
            $revokedSet[$reference] = true;
        }
        $this->keys = $decoded;
        $this->revoked = $revokedSet;
    }

    public function trustedPublicKey(string $reference, string $algorithm): ?string
    {
        if (isset($this->revoked[$reference])) {
            return null;
        }
        $entry = $this->keys[$reference] ?? null;
        if ($entry === null || $entry['algorithm'] !== $algorithm) {
            return null;
        }

        return $entry['public_key'];
    }

    private function assertReference(string $reference): void
    {
        if (preg_match('~^[a-zA-Z0-9][a-zA-Z0-9._:/-]*$~D', $reference) !== 1) {
            throw new \InvalidArgumentException('CFG-04 manifest trust-key reference is invalid.');
        }
    }
}
