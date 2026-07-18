<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Preflight;

use Waaseyaa\Entity\FieldReadLevel;

/**
 * Names-only output of the restricted read-only site scanners.
 *
 * Values and predicates have no representation in this inventory.
 *
 * @api
 */
final readonly class FieldAccessLiveInventory
{
    /**
     * @param list<string> $liveKeys Canonical entity-type|bundle-or-*|field keys.
     * @param array<string, FieldReadLevel> $artifactLevels
     * @param list<string> $v1Drivers
     * @param list<string> $serializedEntities
     * @param list<string> $legacyPayloads
     */
    public function __construct(
        public string $frameworkVersion,
        public string $schemaFingerprint,
        public array $liveKeys = [],
        public array $artifactLevels = [],
        public array $v1Drivers = [],
        public array $serializedEntities = [],
        public array $legacyPayloads = [],
        public int $scannerVersion = 1,
    ) {
        if ($frameworkVersion === '' || $schemaFingerprint === '' || $scannerVersion < 1) {
            throw new \InvalidArgumentException('Live inventory requires versioned framework, schema, and scanner identities.');
        }
    }
}
