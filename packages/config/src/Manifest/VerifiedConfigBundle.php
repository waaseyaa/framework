<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Manifest;

use Waaseyaa\Config\Schema\ConfigPackageCompatibility;
use Waaseyaa\Config\Schema\ConfigSchemaRegistry;
use Waaseyaa\Config\Sync\ConfigSyncBundleValidationResult;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ValidatedConfigSyncEntry;

/** Exact strict-v1 content authorized by one verified authored manifest. @api */
final readonly class VerifiedConfigBundle
{
    /** @var list<ConfigSyncFile> */
    private array $files;

    /** @var list<ValidatedConfigSyncEntry> */
    private array $entries;

    /** @param list<ValidatedConfigSyncEntry> $entries */
    private function __construct(
        array $entries,
        public VerifiedConfigManifest $verification,
        public EffectiveGenerationManifest $effectiveManifest,
    ) {
        $this->entries = $entries;
        $this->files = array_map(static fn(ValidatedConfigSyncEntry $entry): ConfigSyncFile => $entry->file, $entries);
    }

    public static function bind(
        ConfigSyncBundleValidationResult $result,
        ConfigSchemaRegistry $registry,
        VerifiedConfigManifest $verification,
        ConfigPackageCompatibility $compatibility,
    ): self {
        $document = $verification->manifest->document;
        /** @var array<string, string> $producerEvidence */
        $producerEvidence = $document['producer_evidence'];
        /** @var array<string, int> $requiredPackageContracts */
        $requiredPackageContracts = $document['required_package_contracts'];
        $compatibility->assertWritableCohort($requiredPackageContracts);
        foreach ($result->requireValidEntries() as $entry) {
            $compatibility->assertWritable($entry->file, $registry);
        }
        $recomputed = ConfigSyncBundleManifest::fromValidatedBundle(
            $result,
            $registry,
            (string) $document['bundle_scope'],
            (int) $document['bundle_sequence'],
            $producerEvidence,
            $requiredPackageContracts,
        );
        if (!hash_equals($verification->manifest->canonicalBytes, $recomputed->canonicalBytes)
            || !hash_equals($verification->manifestHash, $recomputed->manifestHash)
        ) {
            throw new \RuntimeException('Verified bundle manifest does not match the complete validated sync directory.');
        }
        $entries = $result->requireValidEntries();

        return new self(
            $entries,
            $verification,
            EffectiveGenerationManifest::fromValidatedBundle($result),
        );
    }

    /** @return list<ConfigSyncFile> */
    public function files(): array
    {
        return $this->files;
    }

    /** @return list<ValidatedConfigSyncEntry> */
    public function entries(): array
    {
        return $this->entries;
    }
}
