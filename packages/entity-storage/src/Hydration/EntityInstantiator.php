<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Hydration;

use Symfony\Component\Uid\Uuid;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInitializationBoundary;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityReadLayout;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityValueReadGuardInterface;
use Waaseyaa\Entity\Exception\StaleEntityReadLayout;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Entity\Field\FieldReadLayoutGenerationSourceInterface;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;

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
    /** @var array<string, array{layout: EntityReadLayout, registryGeneration: int}> */
    private array $compiledLayouts = [];

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

        if (is_a($class, EntityBase::class, true)) {
            $layoutCacheKey = $this->layoutCacheKey($class, $values);
            $cached = $this->compiledLayouts[$layoutCacheKey] ?? null;
            $layout = $cached['layout'] ?? null;
            $registryGeneration = $this->registryGeneration($values);
            if ($layout !== null && $cached['registryGeneration'] !== $registryGeneration) {
                unset($this->compiledLayouts[$layoutCacheKey]);
                $layout = null;
            }
            if ($layout !== null) {
                try {
                    $layout->assertCurrent();
                } catch (StaleEntityReadLayout) {
                    unset($this->compiledLayouts[$layoutCacheKey]);
                    $layout = null;
                }
            }
            if ($layout === null) {
                $layout = EntityReadRuntime::layoutFor(
                    $class,
                    $values,
                    $this->entityType->id(),
                    $this->entityType->getKeys(),
                    $this->fieldRegistry,
                    true,
                    $this->entityType->getFieldDefinitions(),
                );
                if ($this->hasImmutableLayoutSources($values)) {
                    $this->compiledLayouts[$layoutCacheKey] = [
                        'layout' => $layout,
                        'registryGeneration' => $registryGeneration,
                    ];
                }
            }

            return $this->instantiateSealed(
                $class,
                $values,
                $layout,
            );
        }

        throw new \RuntimeException(sprintf(
            'Registered entity class "%s" must extend %s for sealed V2 hydration.',
            $class,
            EntityBase::class,
        ));
    }

    /** @param class-string $class @param array<string, mixed> $values */
    private function layoutCacheKey(string $class, array $values): string
    {
        $fieldNames = array_keys($values);
        sort($fieldNames);
        $bundleKey = $this->entityType->getKeys()['bundle'] ?? 'bundle';
        $bundle = (string) ($values[$bundleKey] ?? $this->entityType->id());

        return implode("\0", [$class, $bundle, implode("\0", $fieldNames)]);
    }

    /** @param array<string, mixed> $values */
    private function hasImmutableLayoutSources(array $values): bool
    {
        foreach ($this->entityType->getFieldDefinitions() as $definition) {
            if (!$definition instanceof FieldDefinition) {
                return false;
            }
        }
        if ($this->fieldRegistry === null) {
            return true;
        }
        if (!$this->fieldRegistry instanceof FieldDefinitionRegistry) {
            return false;
        }

        $entityTypeId = $this->entityType->id();
        $bundleKey = $this->entityType->getKeys()['bundle'] ?? 'bundle';
        $bundle = (string) ($values[$bundleKey] ?? $entityTypeId);
        $definitions = array_merge(
            $this->fieldRegistry->coreFieldsFor($entityTypeId),
            $this->fieldRegistry->bundleFieldsFor($entityTypeId, $bundle),
        );
        foreach ($definitions as $definition) {
            if (!$definition instanceof FieldDefinition) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $values */
    private function registryGeneration(array $values): int
    {
        if (!$this->fieldRegistry instanceof FieldReadLayoutGenerationSourceInterface) {
            return 0;
        }
        $entityTypeId = $this->entityType->id();
        $bundleKey = $this->entityType->getKeys()['bundle'] ?? 'bundle';
        $bundle = (string) ($values[$bundleKey] ?? $entityTypeId);
        $bundle = $bundle !== '' ? $bundle : $entityTypeId;

        return $this->fieldRegistry->fieldReadLayoutGeneration($entityTypeId, $bundle)->current();
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
        if (isset($keys['uuid'])) {
            $uuidKey = $keys['uuid'];
            if (!isset($values[$uuidKey]) || $values[$uuidKey] === '') {
                $values[$uuidKey] = Uuid::v4()->toRfc4122();
            }
        }
        $structure = EntityReadRuntime::structureFor(
            $values,
            $this->entityType->id(),
            $keys,
            $layout,
        );
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
