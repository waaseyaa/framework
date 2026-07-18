<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Preflight;

use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Entity\Preflight\FieldAccessPreflightData;
use Waaseyaa\Entity\Preflight\FieldAccessPreflightResult;
use Waaseyaa\Field\FieldDefinitionInterface;
use Waaseyaa\Field\FieldReadMetadataResolver;

/** @api */
final readonly class FieldAccessPreflightScanner
{
    private const array STRUCTURAL_KEYS = ['id', 'uuid', 'bundle', 'revision', 'langcode', 'default_langcode'];

    public function __construct(private FieldReadMetadataResolver $metadata = new FieldReadMetadataResolver()) {}

    public function scan(EntityTypeManager $manager, FieldAccessLiveInventory $inventory): FieldAccessPreflightResult
    {
        $fields = [];
        $conflicts = [];
        $unclassified = [];
        $registry = $manager->getFieldRegistry();

        foreach ($manager->getDefinitions() as $entityType => $type) {
            $structuralNames = [];
            foreach (self::STRUCTURAL_KEYS as $logical) {
                $name = $type->getKeys()[$logical] ?? null;
                if (!is_string($name) || $name === '') {
                    continue;
                }
                $structuralNames[$name] = true;
                $fields[self::key($entityType, '*', $name)] = 'public:structural';
            }

            foreach ($registry->coreFieldsFor($entityType) as $name => $definition) {
                if (!$definition instanceof FieldDefinitionInterface) {
                    $unclassified[] = self::key($entityType, '*', $name);
                    continue;
                }
                $key = self::key($entityType, '*', $definition->getName());
                $this->classify($definition, $inventory, $key, isset($structuralNames[$definition->getName()]), $fields, $conflicts, $unclassified);
            }

            foreach ($registry->bundleNamesFor($entityType) as $bundle) {
                foreach ($registry->bundleFieldsFor($entityType, $bundle) as $name => $definition) {
                    if (!$definition instanceof FieldDefinitionInterface) {
                        $unclassified[] = self::key($entityType, $bundle, $name);
                        continue;
                    }
                    $key = self::key($entityType, $bundle, $definition->getName());
                    $this->classify($definition, $inventory, $key, false, $fields, $conflicts, $unclassified);
                }
            }
        }

        foreach ($inventory->liveKeys as $key) {
            [$entityType, $bundle, $field] = self::parseKey($key);
            $coreKey = self::key($entityType, '*', $field);
            if (!isset($fields[$key]) && !isset($fields[$coreKey]) && !in_array($key, $conflicts, true) && !in_array($coreKey, $conflicts, true)) {
                $unclassified[] = self::key($entityType, $bundle, $field);
            }
        }

        $data = new FieldAccessPreflightData(
            frameworkVersion: $inventory->frameworkVersion,
            schemaFingerprint: $inventory->schemaFingerprint,
            scannerVersion: $inventory->scannerVersion,
            fields: $fields,
            conflicts: array_values(array_unique($conflicts)),
            unclassifiedEntries: array_values(array_unique($unclassified)),
            v1Drivers: $inventory->v1Drivers,
            serializedEntities: $inventory->serializedEntities,
            legacyPayloads: $inventory->legacyPayloads,
        );

        return FieldAccessPreflightResult::fromData($data);
    }

    /**
     * @param array<string, string> $fields
     * @param list<string> $conflicts
     * @param list<string> $unclassified
     */
    private function classify(
        FieldDefinitionInterface $definition,
        FieldAccessLiveInventory $inventory,
        string $key,
        bool $structural,
        array &$fields,
        array &$conflicts,
        array &$unclassified,
    ): void {
        try {
            $metadata = $this->metadata->resolve($definition, $inventory->artifactLevels[$key] ?? null);
        } catch (\LogicException) {
            $conflicts[] = $key;
            return;
        }
        if ($metadata->level === null) {
            $unclassified[] = $key;
            return;
        }
        if ($structural && $metadata->level !== FieldReadLevel::Public) {
            $conflicts[] = $key;
            return;
        }
        $fields[$key] = $metadata->level->value . ':' . $metadata->source->value;
    }

    private static function key(string $entityType, string $bundle, string $field): string
    {
        return $entityType . '|' . $bundle . '|' . $field;
    }

    /** @return array{string, string, string} */
    private static function parseKey(string $key): array
    {
        $parts = explode('|', $key);
        if (count($parts) !== 3 || in_array('', $parts, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid canonical field inventory key "%s".', $key));
        }

        return [$parts[0], $parts[1], $parts[2]];
    }
}
