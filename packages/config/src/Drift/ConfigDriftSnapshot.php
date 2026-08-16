<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Drift;

use Waaseyaa\Config\Authority\ConfigurationActiveToken;
use Waaseyaa\Config\Sync\ConfigSyncFile;

/** One CFG-02-consistent active-generation and manifest observation. @api */
final readonly class ConfigDriftSnapshot
{
    /**
     * @param list<ConfigSyncFile> $files
     * @param array<string, string> $activeEntryHashes
     */
    public function __construct(
        public ConfigurationActiveToken $activeToken,
        public array $files,
        public array $activeEntryHashes,
        public string $generationSchemaVersion,
        public string $generationManifestHash,
        public string $authoredManifestHash,
        public string $authoredManifestBytes,
        public int $provenanceActivationSequence,
        public int $manifestBundleSequence,
        public string $manifestTrustKeyReference,
        public bool $replayEvidenceConsistent,
        public int $replayLastSequence,
    ) {
        if ($provenanceActivationSequence < 1
            || $provenanceActivationSequence > $activeToken->activationSequence
            || $manifestBundleSequence < 1
            || $replayLastSequence < $manifestBundleSequence
            || preg_match('/^sha256:[a-f0-9]{64}$/D', $authoredManifestHash) !== 1
        ) {
            throw new \InvalidArgumentException('Configuration drift snapshot sequencing is inconsistent.');
        }
    }
}
