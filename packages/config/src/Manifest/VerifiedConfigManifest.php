<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Manifest;

/** Verification result handed to activation; it performs no replay mutation. @api */
final readonly class VerifiedConfigManifest
{
    public function __construct(
        public ConfigSyncBundleManifest $manifest,
        public string $manifestHash,
        public string $bundleScope,
        public int $bundleSequence,
        public string $trustKeyReference,
        public bool $signed,
    ) {
        if (!hash_equals($manifest->manifestHash, $manifestHash)) {
            throw new \InvalidArgumentException('Verified manifest result does not bind its canonical manifest bytes.');
        }
        if (($manifest->document['bundle_scope'] ?? null) !== $bundleScope
            || ($manifest->document['bundle_sequence'] ?? null) !== $bundleSequence
        ) {
            throw new \InvalidArgumentException('Verified manifest result does not bind manifest scope and sequence.');
        }
    }
}
