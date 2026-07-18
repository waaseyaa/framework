<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Preflight;

/**
 * Read-only input collected by the future restricted preflight bootstrap.
 *
 * @api
 */
final readonly class FieldAccessPreflightData
{
    /**
     * @param array<string, string> $fields
     * @param list<string> $conflicts
     * @param list<string> $unclassifiedEntries
     * @param list<string> $v1Drivers
     * @param list<string> $serializedEntities
     * @param list<string> $legacyPayloads
     */
    public function __construct(
        public string $frameworkVersion,
        public string $schemaFingerprint,
        public int $scannerVersion,
        public array $fields,
        public array $conflicts,
        public array $unclassifiedEntries,
        public array $v1Drivers,
        public array $serializedEntities,
        public array $legacyPayloads,
    ) {
        if ($frameworkVersion === '' || $schemaFingerprint === '' || $scannerVersion < 1) {
            throw new \InvalidArgumentException('Preflight data requires versioned framework and schema fingerprints.');
        }
    }

    /** @return array<string, mixed> */
    public function canonicalData(): array
    {
        $fields = $this->fields;
        ksort($fields);
        $conflicts = $this->conflicts;
        sort($conflicts);
        $unclassifiedEntries = $this->unclassifiedEntries;
        sort($unclassifiedEntries);
        $v1Drivers = $this->v1Drivers;
        sort($v1Drivers);
        $serializedEntities = $this->serializedEntities;
        sort($serializedEntities);
        $legacyPayloads = $this->legacyPayloads;
        sort($legacyPayloads);

        return [
            'framework_version' => $this->frameworkVersion,
            'schema_fingerprint' => $this->schemaFingerprint,
            'scanner_version' => $this->scannerVersion,
            'fields' => $fields,
            'conflicts' => $conflicts,
            'unclassified_entries' => $unclassifiedEntries,
            'unclassified_count' => count($unclassifiedEntries),
            'v1_drivers' => $v1Drivers,
            'v1_driver_count' => count($v1Drivers),
            'serialized_entities' => $serializedEntities,
            'serialized_entity_count' => count($serializedEntities),
            'legacy_payloads' => $legacyPayloads,
            'legacy_payload_count' => count($legacyPayloads),
        ];
    }
}
