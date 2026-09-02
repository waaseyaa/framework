<?php

declare(strict_types=1);

namespace Waaseyaa\Field;

use Waaseyaa\Cache\CacheBackendInterface;
use Waaseyaa\Field\Discovery\FieldTypeDiscovery;
use Waaseyaa\Field\Exception\UnknownFieldTypeException;
use Waaseyaa\Plugin\DefaultPluginManager;

/**
 * The field-type plugin registry.
 *
 * A kernel owns exactly one boot-scoped instance, built by
 * {@see fromManifest()} from the compiled package manifest: the built-in
 * plugins under this package plus every downstream `#[FieldType]` plugin the
 * manifest discovered. `AbstractKernel::bootEntityTypeManager()` hands that
 * instance to the canonical field registry and the runtime schema handlers,
 * and the kernel-services bus serves it to providers under both
 * `FieldTypeManagerInterface` and this class. Unknown ids fail closed
 * everywhere; there is no silent fallback roster.
 *
 * @api
 */
final class FieldTypeManager extends DefaultPluginManager implements FieldTypeManagerInterface
{
    private static ?self $default = null;

    /**
     * The process-static, built-ins-only registry.
     *
     * Isolated construction only: bare value objects, unit tests, and consumer
     * scripts that never boot a kernel. This instance can never learn a
     * downstream plugin, so kernel-wired code must consume the boot-scoped
     * registry instead (see {@see fromManifest()} and the kernel-services bus);
     * `tests/Architecture/FieldTypeManagerDefaultRosterTest` pins the closed
     * roster of call sites that may still reach for it.
     */
    public static function default(): self
    {
        return self::$default ??= new self();
    }

    /**
     * The boot-scoped registry for one kernel composition.
     *
     * Admits the built-in plugins plus the downstream `#[FieldType]` plugins
     * recorded in the package manifest's `field_types` inventory (id => class).
     * Definitions are resolved eagerly so a duplicate id or an unregistrable
     * class refuses boot here, with the offending classes named, rather than
     * surfacing as a wrapped admission failure at first schema projection.
     *
     * @param array<string, string> $manifestFieldTypes {@see \Waaseyaa\Foundation\Discovery\PackageManifest::$fieldTypes}
     */
    public static function fromManifest(array $manifestFieldTypes, ?CacheBackendInterface $cache = null): self
    {
        $manager = new self(cache: $cache, extensionClasses: $manifestFieldTypes);
        $manager->getDefinitions();

        return $manager;
    }

    /**
     * @param string[]|null $directories      Directories scanned for built-in plugins; `null` scans this
     *                                        package, an explicit empty list scans nothing.
     * @param array<int|string,string> $extensionClasses Manifest id => class pairs, or an internal class list.
     */
    public function __construct(
        ?array $directories = null,
        ?CacheBackendInterface $cache = null,
        array $extensionClasses = [],
    ) {
        ksort($extensionClasses, SORT_STRING);
        $discovery = new FieldTypeDiscovery(
            directories: $directories ?? [__DIR__],
            extensionClasses: $extensionClasses,
        );

        // A cached definition set is keyed by the extension roster it was
        // resolved for, so a built-ins-only registry and a manifest-fed one
        // sharing a cache backend can never serve each other's definitions.
        $cacheKey = 'field_type_definitions';
        if ($extensionClasses !== []) {
            $cacheKey .= ':' . hash('sha256', json_encode($extensionClasses, JSON_THROW_ON_ERROR));
        }

        parent::__construct(
            discovery: $discovery,
            cache: $cache,
            cacheKey: $cacheKey,
        );
    }

    public function getDefaultSettings(string $fieldType): array
    {
        $definition = $this->getDefinition($fieldType);
        $class = $definition->class;

        if (!is_subclass_of($class, FieldTypeInterface::class)) {
            return [];
        }

        return $class::defaultSettings();
    }

    public function getColumns(string $fieldType): array
    {
        $definition = $this->getDefinition($fieldType);
        $class = $definition->class;

        if (!is_subclass_of($class, FieldTypeInterface::class)) {
            return [];
        }

        return $class::schema();
    }

    /**
     * Resolve the JSON Schema fragment for a field definition by delegating
     * to the field type plugin's jsonSchemaFor() seam.
     */
    public function jsonSchemaFor(FieldDefinitionInterface $def): array
    {
        $class = $this->resolveItemClass($def->getType());

        if ($class === null) {
            throw UnknownFieldTypeException::for($def->getType());
        }

        return $class::jsonSchemaFor($def);
    }

    public function entityValueJsonSchemaFor(FieldDefinitionInterface $def): array
    {
        $class = $this->resolveItemClass($def->getType());
        if ($class === null) {
            throw UnknownFieldTypeException::for($def->getType());
        }

        return $class::entityValueJsonSchemaFor($def);
    }

    /**
     * Resolve the storage column shape for a field definition by delegating
     * to the field type plugin's schemaFor() seam.
     *
     * @return array<string, array<string, mixed>>
     */
    public function schemaFor(FieldDefinitionInterface $def): array
    {
        $class = $this->resolveItemClass($def->getType());

        if ($class === null) {
            throw UnknownFieldTypeException::for($def->getType());
        }

        return $class::schemaFor($def);
    }

    public function entityStorageColumnSchemaFor(FieldDefinitionInterface $def): array
    {
        $class = $this->resolveItemClass($def->getType());
        if ($class === null) {
            throw UnknownFieldTypeException::for($def->getType());
        }

        return $class::entityStorageColumnSchemaFor($def);
    }

    /**
     * @return class-string<FieldTypeInterface>|null
     */
    private function resolveItemClass(string $fieldType): ?string
    {
        if (!$this->hasDefinition($fieldType)) {
            return null;
        }

        $class = $this->getDefinition($fieldType)->class;

        if (!is_subclass_of($class, FieldTypeInterface::class)) {
            return null;
        }

        return $class;
    }

    public function blueprintFieldTypeIds(): array
    {
        $ids = [];
        foreach ($this->getDefinitions() as $id => $definition) {
            $class = $definition->class;
            if (is_subclass_of($class, FieldTypeInterface::class) && $class::supportsBlueprint()) {
                $ids[] = $id;
            }
        }
        sort($ids, SORT_STRING);

        return $ids;
    }
}
