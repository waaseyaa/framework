<?php

declare(strict_types=1);

namespace Waaseyaa\Field;

use Waaseyaa\Entity\EntityReadLayoutGeneration;
use Waaseyaa\Entity\Field\BundleStorageUniqueKeyRegistryInterface;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Entity\Field\FieldReadLayoutGenerationSourceInterface;

/**
 * Default FieldDefinitionRegistry implementation.
 *
 * Stores FieldDefinition objects keyed by (entityTypeId, targetBundle).
 * Core fields may still be authored as metadata arrays during the alpha
 * transition; they are normalized to FieldDefinition objects at registration.
 * @api
 */
final class FieldDefinitionRegistry implements FieldDefinitionRegistryInterface, BundleStorageUniqueKeyRegistryInterface, FieldReadLayoutGenerationSourceInterface
{
    /** @var array<string, array<string, EntityReadLayoutGeneration>> */
    private array $fieldReadLayoutGenerations = [];

    /** @var array<string, array<string, FieldDefinitionInterface>> [entityTypeId][fieldName]. */
    private array $coreFields = [];

    /** @var array<string, array<string, array<string, FieldDefinitionInterface>>> [entityTypeId][bundle][fieldName]. */
    private array $bundleFields = [];

    /** @var array<string, array<string, list<array{name: string, fields: non-empty-list<string>}>>> */
    private array $bundleUniqueKeys = [];

    public function registerCoreFields(string $entityTypeId, array $fields): void
    {
        $byName = [];
        foreach ($fields as $name => $field) {
            if (!$field instanceof FieldDefinitionInterface) {
                if (!is_array($field)) {
                    throw new \InvalidArgumentException(\sprintf(
                        'Core field "%s" on entity type "%s" must implement FieldDefinitionInterface; got %s.',
                        $name,
                        $entityTypeId,
                        \get_debug_type($field),
                    ));
                }
                $field = self::synthesizeCoreField($name, $entityTypeId, $field);
            }
            if ($field->getTargetEntityTypeId() !== $entityTypeId) {
                throw new \InvalidArgumentException(\sprintf(
                    'Core field "%s" declares targetEntityTypeId "%s" but is being registered against entity type "%s".',
                    $field->getName(),
                    $field->getTargetEntityTypeId(),
                    $entityTypeId,
                ));
            }
            $byName[$field->getName()] = $field;
        }
        $this->coreFields[$entityTypeId] = $byName;
        foreach ($this->fieldReadLayoutGenerations[$entityTypeId] ?? [] as $bundle => $generation) {
            $generation->advance();
            $generation->replaceSemanticFingerprintProvider($this->semanticFingerprintProvider($entityTypeId, $bundle));
        }
    }

    /**
     * @param array<string, mixed> $meta
     */
    private static function synthesizeCoreField(string $name, string $entityTypeId, array $meta): FieldDefinition
    {
        $known = ['type', 'label', 'description', 'required', 'readOnly', 'read_only',
            'cardinality', 'translatable', 'revisionable', 'default', 'defaultValue',
            'settings', 'constraints', 'stored', 'read'];

        $settings = $meta['settings'] ?? [];
        if (!\is_array($settings)) {
            $settings = [];
        }
        foreach ($meta as $key => $value) {
            if (!\in_array($key, $known, true)) {
                $settings[$key] = $value;
            }
        }

        $stored = $meta['stored'] ?? FieldStorage::Column;
        if (\is_string($stored)) {
            $stored = FieldStorage::tryFrom($stored) ?? FieldStorage::Column;
        }
        if (!$stored instanceof FieldStorage) {
            $stored = FieldStorage::Column;
        }

        return new FieldDefinition(
            name: $name,
            type: (string) ($meta['type'] ?? 'string'),
            cardinality: (int) ($meta['cardinality'] ?? 1),
            settings: $settings,
            targetEntityTypeId: $entityTypeId,
            targetBundle: null,
            translatable: (bool) ($meta['translatable'] ?? false),
            revisionable: (bool) ($meta['revisionable'] ?? false),
            defaultValue: $meta['defaultValue'] ?? ($meta['default'] ?? null),
            label: (string) ($meta['label'] ?? ''),
            description: (string) ($meta['description'] ?? ''),
            required: (bool) ($meta['required'] ?? false),
            readOnly: (bool) ($meta['readOnly'] ?? $meta['read_only'] ?? false),
            constraints: \is_array($meta['constraints'] ?? null) ? $meta['constraints'] : [],
            stored: $stored,
            read: ($meta['read'] ?? null) instanceof \Waaseyaa\Entity\FieldReadLevel ? $meta['read'] : null,
        );
    }

    public function mergeCoreFields(string $entityTypeId, array $fields): void
    {
        $existing = $this->coreFields[$entityTypeId] ?? [];
        foreach ($fields as $name => $_meta) {
            if (isset($existing[$name])) {
                throw new \InvalidArgumentException(\sprintf(
                    'Cannot merge core field "%s" on entity type "%s": name already registered.',
                    $name,
                    $entityTypeId,
                ));
            }
        }

        $this->registerCoreFields($entityTypeId, $existing + $fields);
    }
    public function registerBundleFields(string $entityTypeId, string $bundle, array $fields): void
    {
        // Bundle identity is meaningful even when a bundle adds no fields of
        // its own. Schema consumers use bundleNamesFor() to validate a bundle
        // selected in an authoring request, so preserve the explicit empty
        // declaration instead of making it indistinguishable from unknown.
        if (!isset($this->bundleFields[$entityTypeId][$bundle])) {
            $this->bundleFields[$entityTypeId][$bundle] = [];
        }

        $byName = [];
        foreach ($fields as $key => $field) {
            if (!$field instanceof FieldDefinitionInterface) {
                throw new \InvalidArgumentException(\sprintf(
                    'Bundle field registration for entity type "%s" bundle "%s" expects FieldDefinitionInterface instances; got %s at key "%s".',
                    $entityTypeId,
                    $bundle,
                    \get_debug_type($field),
                    (string) $key,
                ));
            }

            if ($field->getTargetEntityTypeId() !== $entityTypeId) {
                throw new \InvalidArgumentException(\sprintf(
                    'FieldDefinition "%s" declares targetEntityTypeId "%s" but is being registered against entity type "%s".',
                    $field->getName(),
                    $field->getTargetEntityTypeId(),
                    $entityTypeId,
                ));
            }

            if ($field->getTargetBundle() !== $bundle) {
                throw new \InvalidArgumentException(\sprintf(
                    'FieldDefinition "%s" declares targetBundle "%s" but is being registered against entity type "%s" bundle "%s".',
                    $field->getName(),
                    $field->getTargetBundle() ?? '(null)',
                    $entityTypeId,
                    $bundle,
                ));
            }

            $name = $field->getName();
            if (isset($byName[$name])) {
                throw new \InvalidArgumentException(\sprintf(
                    'Duplicate bundle field "%s" in registration for entity type "%s" bundle "%s".',
                    $name,
                    $entityTypeId,
                    $bundle,
                ));
            }
            $byName[$name] = $field;
        }

        $coreNames = $this->coreFields[$entityTypeId] ?? [];
        foreach ($byName as $name => $_field) {
            if (\array_key_exists($name, $coreNames)) {
                throw new \InvalidArgumentException(\sprintf(
                    'Field "%s" on entity type "%s" bundle "%s" collides with core field "%s" on entity type "%s".',
                    $name,
                    $entityTypeId,
                    $bundle,
                    $name,
                    $entityTypeId,
                ));
            }
        }

        $existing = $this->bundleFields[$entityTypeId][$bundle];
        foreach ($byName as $name => $_field) {
            if (isset($existing[$name])) {
                throw new \InvalidArgumentException(\sprintf(
                    'Duplicate bundle field "%s" for entity type "%s" bundle "%s"; already registered.',
                    $name,
                    $entityTypeId,
                    $bundle,
                ));
            }
        }

        foreach ($byName as $name => $field) {
            $this->bundleFields[$entityTypeId][$bundle][$name] = $field;
        }
        $generation = $this->fieldReadLayoutGeneration($entityTypeId, $bundle);
        $generation->advance();
        $generation->replaceSemanticFingerprintProvider($this->semanticFingerprintProvider($entityTypeId, $bundle));
    }

    public function fieldReadLayoutGeneration(string $entityTypeId, string $bundle): EntityReadLayoutGeneration
    {
        return $this->fieldReadLayoutGenerations[$entityTypeId][$bundle] ??= new EntityReadLayoutGeneration(
            $this->semanticFingerprintProvider($entityTypeId, $bundle),
        );
    }

    /** @return (\Closure(): string)|null */
    private function semanticFingerprintProvider(string $entityTypeId, string $bundle): ?\Closure
    {
        $definitions = ($this->coreFields[$entityTypeId] ?? [])
            + ($this->bundleFields[$entityTypeId][$bundle] ?? []);
        foreach ($definitions as $definition) {
            if (!$definition instanceof FieldDefinition) {
                $registry = \WeakReference::create($this);

                return static function () use ($registry, $entityTypeId, $bundle): string {
                    $instance = $registry->get();
                    if (!$instance instanceof self) {
                        return 'registry-released';
                    }

                    return $instance->fieldReadSemanticFingerprint($entityTypeId, $bundle);
                };
            }
        }

        return null;
    }

    private function fieldReadSemanticFingerprint(string $entityTypeId, string $bundle): string
    {
        $definitions = ($this->coreFields[$entityTypeId] ?? [])
            + ($this->bundleFields[$entityTypeId][$bundle] ?? []);
        ksort($definitions);
        $resolver = new FieldReadMetadataResolver();
        $semantics = [];
        foreach ($definitions as $name => $definition) {
            $level = $resolver->resolve($definition)->level;
            $semantics[] = implode(':', [
                $name,
                $level === null ? 'unclassified' : $level->value,
                $definition->getSetting('authorizationInput') === true ? 'auth' : 'ordinary',
            ]);
        }

        return 'definitions:' . hash('xxh128', implode("\0", $semantics));
    }

    public function coreFieldsFor(string $entityTypeId): array
    {
        return $this->coreFields[$entityTypeId] ?? [];
    }

    public function bundleFieldsFor(string $entityTypeId, string $bundle): array
    {
        return $this->bundleFields[$entityTypeId][$bundle] ?? [];
    }

    public function bundleNamesFor(string $entityTypeId): array
    {
        return \array_keys($this->bundleFields[$entityTypeId] ?? []);
    }

    public function bundlesDefiningField(string $entityTypeId, string $fieldName): array
    {
        $bundles = [];
        foreach ($this->bundleFields[$entityTypeId] ?? [] as $bundle => $fields) {
            if (\array_key_exists($fieldName, $fields)) {
                $bundles[] = $bundle;
            }
        }

        return $bundles;
    }

    /** @param list<array<string, mixed>> $keys */
    public function registerBundleUniqueKeys(string $entityTypeId, string $bundle, array $keys): void
    {
        $fields = $this->bundleFields[$entityTypeId][$bundle] ?? null;
        if ($fields === null) {
            throw new \InvalidArgumentException(\sprintf(
                'Cannot register bundle unique keys for entity type "%s" bundle "%s" before its fields are registered.',
                $entityTypeId,
                $bundle,
            ));
        }

        $existing = $this->bundleUniqueKeys[$entityTypeId][$bundle] ?? [];
        $names = [];
        foreach ($existing as $key) {
            $names[$key['name']] = true;
        }

        foreach ($keys as $offset => $key) {
            $name = $key['name'] ?? null;
            $rawFields = $key['fields'] ?? null;
            if (!\is_string($name) || $name === '' || !\is_array($rawFields) || $rawFields === []) {
                throw new \InvalidArgumentException(\sprintf(
                    'Bundle unique key at offset %d for entity type "%s" bundle "%s" requires a non-empty name and field list.',
                    $offset,
                    $entityTypeId,
                    $bundle,
                ));
            }
            $keyFields = [];
            foreach ($rawFields as $fieldName) {
                if (!\is_string($fieldName) || $fieldName === '') {
                    throw new \InvalidArgumentException(\sprintf('Bundle unique key "%s" contains a non-string or empty field.', $name));
                }
                $keyFields[] = $fieldName;
            }
            if (\count(\array_unique($keyFields)) !== \count($keyFields)) {
                throw new \InvalidArgumentException(\sprintf('Bundle unique key "%s" contains duplicate or empty fields.', $name));
            }
            if (isset($names[$name])) {
                throw new \InvalidArgumentException(\sprintf(
                    'Duplicate bundle unique key name "%s" for entity type "%s" bundle "%s".',
                    $name,
                    $entityTypeId,
                    $bundle,
                ));
            }
            if (\strlen($name) > 63) {
                throw new \InvalidArgumentException(\sprintf(
                    'Bundle unique key name "%s" exceeds the portable 63-byte identifier limit.',
                    $name,
                ));
            }
            foreach ($keyFields as $fieldName) {
                if (!isset($fields[$fieldName])) {
                    throw new \InvalidArgumentException(\sprintf(
                        'Bundle unique key "%s" on entity type "%s" bundle "%s" names unknown field "%s".',
                        $name,
                        $entityTypeId,
                        $bundle,
                        $fieldName,
                    ));
                }
                $field = $fields[$fieldName];
                if ($field->getStored() === FieldStorage::Data) {
                    if (!$field instanceof FieldDefinition) {
                        throw new \InvalidArgumentException(\sprintf(
                            'Bundle unique key "%s" cannot promote custom Data-backed field "%s"; use FieldDefinition or declare column storage.',
                            $name,
                            $fieldName,
                        ));
                    }
                    $field = $field->withStorage(FieldStorage::Column);
                    $fields[$fieldName] = $field;
                    $this->bundleFields[$entityTypeId][$bundle][$fieldName] = $field;
                }
            }

            /** @var non-empty-list<non-empty-string> $keyFields */
            $existing[] = ['name' => $name, 'fields' => $keyFields];
            $names[$name] = true;
        }

        $this->bundleUniqueKeys[$entityTypeId][$bundle] = $existing;
    }

    public function bundleUniqueKeysFor(string $entityTypeId, string $bundle): array
    {
        return $this->bundleUniqueKeys[$entityTypeId][$bundle] ?? [];
    }
}
