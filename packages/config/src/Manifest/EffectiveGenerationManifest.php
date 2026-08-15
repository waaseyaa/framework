<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Manifest;

use Waaseyaa\Config\Schema\CanonicalConfigEncoder;
use Waaseyaa\Config\Sync\ConfigSyncBundleValidationResult;

/** Content-only effective generation manifest consumed by CFG-02. @api */
final readonly class EffectiveGenerationManifest
{
    public const FORMAT_V1 = 'waaseyaa.config-effective-generation/1';
    public const GENERATION_DOMAIN = 'waaseyaa.config-generation/1';

    /** @param array<string, mixed> $document */
    private function __construct(
        public array $document,
        public string $canonicalBytes,
        public string $generationId,
    ) {}

    public static function fromValidatedBundle(
        ConfigSyncBundleValidationResult $result,
        ?CanonicalConfigEncoder $encoder = null,
    ): self {
        $entryDocuments = [];
        foreach ($result->requireValidEntries() as $entry) {
            $entryDocuments[$entry->key()] = [
                'effective_entry_hash' => $entry->hashes->effectiveEntryHash,
                'owner_config_contract_version' => $entry->file->ownerConfigContractVersion,
                'owner_package' => $entry->file->ownerPackage,
                'schema_hash' => $entry->file->schemaHash,
                'schema_id' => $entry->file->schemaId,
                'schema_version' => $entry->file->schemaVersion,
            ];
        }
        ksort($entryDocuments, \SORT_STRING);
        $document = [
            'entries' => $entryDocuments,
            'format' => self::FORMAT_V1,
        ];
        $encoder ??= new CanonicalConfigEncoder();
        $bytes = $encoder->encode($document);

        return new self($document, $bytes, substr($encoder->digestBytes(self::GENERATION_DOMAIN, $bytes), 7));
    }
}
