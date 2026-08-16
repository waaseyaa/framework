<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Manifest;

use Waaseyaa\Config\Schema\CanonicalConfigEncoder;

/** Closed-field, signature, manifest-binding, and replay preflight verifier. @api */
final class ConfigManifestEnvelopeVerifier
{
    public function __construct(private readonly CanonicalConfigEncoder $encoder = new CanonicalConfigEncoder()) {}

    public function verifySigned(
        SignedConfigManifestEnvelope $envelope,
        ConfigManifestSignatureVerifierInterface $signatureVerifier,
        ConfigReplayStateReaderInterface $replayState,
    ): VerifiedConfigManifest {
        $header = $envelope->protectedHeader;
        if (($header['algorithm'] ?? null) !== SignedConfigManifestEnvelope::ALGORITHM_V1) {
            throw new \InvalidArgumentException('Unsupported envelope algorithm.');
        }
        $manifest = $this->decodeAndBindManifest($envelope->manifestBytes, $header);
        $key = (string) $header['trust_key_reference'];
        if (!$signatureVerifier->verify($key, SignedConfigManifestEnvelope::ALGORITHM_V1, $envelope->preAuthenticatedBytes($this->encoder), $envelope->signature)) {
            throw new \RuntimeException('Configuration manifest signature verification failed.');
        }
        $scope = (string) $header['bundle_scope'];
        $sequence = (int) $header['bundle_sequence'];
        $last = $replayState->lastCommittedSequence($scope, $key);
        if ($last !== null && $sequence <= $last) {
            throw new \RuntimeException(sprintf('Configuration bundle sequence %d is not newer than committed sequence %d.', $sequence, $last));
        }

        return new VerifiedConfigManifest(
            manifest: $manifest,
            manifestHash: $manifest->manifestHash,
            bundleScope: $scope,
            bundleSequence: $sequence,
            trustKeyReference: $key,
            signed: true,
        );
    }

    public function verifyUnsigned(ConfigSyncBundleManifest $manifest, UnsignedConfigPolicy $policy): VerifiedConfigManifest
    {
        if (!$policy->allowsUnsigned || $policy->bootstrapSealHash === null) {
            throw new \RuntimeException('Unsigned configuration is refused by sealed bootstrap policy.');
        }

        return new VerifiedConfigManifest(
            manifest: $manifest,
            manifestHash: $manifest->manifestHash,
            bundleScope: (string) $manifest->document['bundle_scope'],
            bundleSequence: (int) $manifest->document['bundle_sequence'],
            trustKeyReference: 'unsigned-sealed-local:' . $policy->authorityId,
            signed: false,
        );
    }

    /** @param array<string, int|string> $header */
    private function decodeAndBindManifest(string $bytes, array $header): ConfigSyncBundleManifest
    {
        $manifest = ConfigSyncBundleManifest::fromCanonicalBytes($bytes, $this->encoder);
        if (($manifest->document['bundle_scope'] ?? null) !== $header['bundle_scope']
            || ($manifest->document['bundle_sequence'] ?? null) !== $header['bundle_sequence']
        ) {
            throw new \InvalidArgumentException('Envelope protected header does not bind its manifest scope and sequence.');
        }

        return $manifest;
    }
}
