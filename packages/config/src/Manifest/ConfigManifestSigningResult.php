<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Manifest;

/**
 * What one authoring-host signing run produced (#2430).
 *
 * Carries only public evidence an operator can review and paste into a change
 * record. No key material, no provider identifier, and no private bytes appear
 * here or in any surface derived from it.
 *
 * @api
 */
final readonly class ConfigManifestSigningResult
{
    /** @param array<string, int> $requiredPackageContracts */
    public function __construct(
        public string $envelopePath,
        public string $manifestHash,
        public string $bundleScope,
        public int $bundleSequence,
        public string $trustKeyReference,
        public int $entryCount,
        public array $requiredPackageContracts,
    ) {}
}
