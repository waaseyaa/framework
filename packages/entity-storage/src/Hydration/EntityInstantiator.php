<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Hydration;

use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInitializationBoundary;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityReadLayout;
use Waaseyaa\Entity\EntityStructure;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityValueReadGuardInterface;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Entity\Hydration\HydratableFromStorageInterface;
use Waaseyaa\Entity\Hydration\HydrationContext;

/**
 * Centralizes entity construction from storage-normalized value bags.
 *
 * Used by {@see \Waaseyaa\EntityStorage\EntityRepository} and
 * {@see \Waaseyaa\EntityStorage\SqlEntityStorage} so hydration behavior stays
 * consistent.
 *
 * @internal Not part of the semver public API; disposition in docs/public-surface-map.php.
 * @api Activation-ready atomic sealed-hydration boundary.
 */
final class EntityInstantiator
{
    public function __construct(
        private readonly EntityTypeInterface $entityType,
        private readonly ?FieldDefinitionRegistryInterface $fieldRegistry = null,
    ) {}

    /**
     * @param class-string $class
     * @param array<string, mixed> $values
     */
    public function instantiate(string $class, array $values): EntityInterface
    {
        if (!is_a($class, EntityInterface::class, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Entity class "%s" must implement %s.',
                $class,
                EntityInterface::class,
            ));
        }

        if (!is_subclass_of($class, HydratableFromStorageInterface::class)) {
            throw new \RuntimeException(sprintf(
                'Entity class "%s" must implement %s for storage hydration.',
                $class,
                HydratableFromStorageInterface::class,
            ));
        }

        $context = new HydrationContext(
            entityTypeId: $this->entityType->id(),
            entityKeys: $this->entityType->getKeys(),
        );

        $entity = $class::fromStorage($values, $context);
        if ($entity instanceof EntityBase) {
            $keys = $this->entityType->getKeys();
            $bundleKey = $keys['bundle'] ?? null;
            $idKey = $keys['id'] ?? 'id';
            $uuidKey = $keys['uuid'] ?? 'uuid';
            $langcodeKey = $keys['langcode'] ?? 'langcode';
            $revisionKey = $keys['revision'] ?? 'revision_id';
            $langcode = (string) ($values[$langcodeKey] ?? 'en');
            $defaultLangcode = (string) ($values['default_langcode'] ?? $langcode);
            $knownTranslationIds = array_values(array_unique([$defaultLangcode, $langcode]));
            sort($knownTranslationIds);
            $bundle = $bundleKey === null ? '' : (string) ($values[$bundleKey] ?? '');
            $fieldNames = array_values(array_unique(array_merge(
                array_values($keys),
                array_keys($this->entityType->getFieldDefinitions()),
                array_keys($this->fieldRegistry?->coreFieldsFor($this->entityType->id()) ?? []),
                array_keys($this->fieldRegistry?->bundleFieldsFor($this->entityType->id(), $bundle !== '' ? $bundle : $this->entityType->id()) ?? []),
            )));
            sort($fieldNames);
            $entity->_attachEntityStructure(new EntityStructure(
                entityTypeId: $this->entityType->id(),
                bundleId: $bundle !== '' ? $bundle : $this->entityType->id(),
                id: $values[$idKey] ?? null,
                uuid: $entity->uuid() !== ''
                    ? $entity->uuid()
                    : (isset($values[$uuidKey]) ? (string) $values[$uuidKey] : null),
                activeLanguageId: $langcode,
                defaultLanguageId: $defaultLangcode,
                knownTranslationIds: $knownTranslationIds,
                revisionId: $values[$revisionKey] ?? null,
                revisionTip: (bool) ($values['is_latest_revision'] ?? true),
                defaultRevision: (bool) ($values['is_default_revision'] ?? true),
                fieldNames: $fieldNames,
            ));
        }

        return $entity;
    }

    /**
     * Atomic V2 hydration: compile/seal has completed before the entity object
     * exists, and neither its constructor nor legacy fromStorage callback sees
     * repository values.
     *
     * @param class-string $class
     * @param array<string, mixed> $values
     */
    public function instantiateSealed(
        string $class,
        array $values,
        EntityReadLayout $layout,
        ?EntityValueReadGuardInterface $guard = null,
    ): EntityInterface {
        if (!is_a($class, EntityBase::class, true)) {
            throw new \RuntimeException(sprintf(
                'Entity class "%s" must extend %s for sealed V2 hydration.',
                $class,
                EntityBase::class,
            ));
        }

        $keys = $this->entityType->getKeys();
        $structure = $this->structureFor($values);
        $boundary = new EntityInitializationBoundary();
        $initialization = $boundary->factory()->seal(
            values: $values,
            layout: $layout,
            structure: $structure,
            entityTypeId: $this->entityType->id(),
            entityKeys: $keys,
            guard: $guard,
        );

        return $boundary->installer()->instantiate($class, $initialization);
    }

    /** @param array<string, mixed> $values */
    private function structureFor(array $values): EntityStructure
    {
        $keys = $this->entityType->getKeys();
        $bundleKey = $keys['bundle'] ?? null;
        $idKey = $keys['id'] ?? 'id';
        $uuidKey = $keys['uuid'] ?? 'uuid';
        $langcodeKey = $keys['langcode'] ?? 'langcode';
        $revisionKey = $keys['revision'] ?? 'revision_id';
        $langcode = (string) ($values[$langcodeKey] ?? 'en');
        $defaultLangcode = (string) ($values['default_langcode'] ?? $langcode);
        $knownTranslationIds = array_values(array_unique([$defaultLangcode, $langcode]));
        sort($knownTranslationIds);
        $bundle = $bundleKey === null ? '' : (string) ($values[$bundleKey] ?? '');
        $fieldNames = array_values(array_unique(array_merge(
            array_values($keys),
            array_keys($values),
            array_keys($this->entityType->getFieldDefinitions()),
            array_keys($this->fieldRegistry?->coreFieldsFor($this->entityType->id()) ?? []),
            array_keys($this->fieldRegistry?->bundleFieldsFor($this->entityType->id(), $bundle !== '' ? $bundle : $this->entityType->id()) ?? []),
        )));
        sort($fieldNames);

        return new EntityStructure(
            entityTypeId: $this->entityType->id(),
            bundleId: $bundle !== '' ? $bundle : $this->entityType->id(),
            id: $values[$idKey] ?? null,
            uuid: isset($values[$uuidKey]) ? (string) $values[$uuidKey] : null,
            activeLanguageId: $langcode,
            defaultLanguageId: $defaultLangcode,
            knownTranslationIds: $knownTranslationIds,
            revisionId: $values[$revisionKey] ?? null,
            revisionTip: (bool) ($values['is_latest_revision'] ?? true),
            defaultRevision: (bool) ($values['is_default_revision'] ?? true),
            fieldNames: $fieldNames,
        );
    }

    /**
     * Fills missing keys from registered field definitions before hydration.
     * Shared by {@see \Waaseyaa\EntityStorage\EntityRepository::create()} and
     * {@see \Waaseyaa\EntityStorage\SqlEntityStorage::create()} so a fresh
     * entity gets the SAME field defaults regardless of which engine built it.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function applyFieldDefinitionDefaults(array $values): array
    {
        foreach ($this->entityType->getFieldDefinitions() as $name => $def) {
            if (array_key_exists($name, $values)) {
                continue;
            }
            $defaultValue = $def->getDefaultValue();
            if ($defaultValue !== null) {
                $values[$name] = $defaultValue;
            }
        }

        return $values;
    }
}
