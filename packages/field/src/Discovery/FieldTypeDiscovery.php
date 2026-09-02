<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Discovery;

use Waaseyaa\Field\Attribute\FieldType;
use Waaseyaa\Field\Exception\DuplicateFieldTypeException;
use Waaseyaa\Field\Exception\InvalidFieldTypePluginException;
use Waaseyaa\Field\FieldTypeInterface;
use Waaseyaa\Plugin\Definition\PluginDefinition;
use Waaseyaa\Plugin\Discovery\AttributeDiscovery;
use Waaseyaa\Plugin\Discovery\PluginDiscoveryInterface;

/**
 * Field-type plugin discovery: the built-in plugins found by scanning the
 * field package's own directories, plus downstream plugins admitted by class
 * name (the package manifest's `field_types` inventory).
 *
 * Each downstream class is validated when definitions are resolved — it must
 * load, be concrete, carry `#[FieldType]`, and implement `FieldTypeInterface`.
 * An id claimed by two different classes is refused with both names; the same
 * class reaching the registry twice (a built-in also listed by the manifest)
 * is one plugin, not a collision.
 *
 * @internal Composed by {@see \Waaseyaa\Field\FieldTypeManager}.
 */
final class FieldTypeDiscovery implements PluginDiscoveryInterface
{
    /**
     * @param list<string>             $directories      Directories scanned for built-in `#[FieldType]` plugins.
     * @param array<int|string,string> $extensionClasses Manifest id => class pairs, or an internal class list.
     */
    public function __construct(
        private readonly array $directories,
        private readonly array $extensionClasses = [],
    ) {}

    public function getDefinitions(): array
    {
        $definitions = new AttributeDiscovery(
            directories: $this->directories,
            attributeClass: FieldType::class,
        )->getDefinitions();

        $extensions = $this->extensionClasses;
        ksort($extensions, SORT_STRING);

        foreach ($extensions as $expectedId => $class) {
            $definition = self::describe($class);
            if (is_string($expectedId) && $definition->id !== $expectedId) {
                throw InvalidFieldTypePluginException::idMismatch($expectedId, $definition->id, $class);
            }
            $registered = $definitions[$definition->id] ?? null;
            if ($registered !== null && $registered->class !== $definition->class) {
                throw DuplicateFieldTypeException::for($definition->id, $registered->class, $definition->class);
            }
            $definitions[$definition->id] = $definition;
        }

        return $definitions;
    }

    private static function describe(string $class): PluginDefinition
    {
        if (!class_exists($class)) {
            throw InvalidFieldTypePluginException::missingClass($class);
        }

        $reflection = new \ReflectionClass($class);
        $attributes = $reflection->getAttributes(FieldType::class, \ReflectionAttribute::IS_INSTANCEOF);
        if ($attributes === []) {
            throw InvalidFieldTypePluginException::missingAttribute($class);
        }
        if ($reflection->isAbstract() || !$reflection->implementsInterface(FieldTypeInterface::class)) {
            throw InvalidFieldTypePluginException::notAFieldType($class);
        }

        $attribute = $attributes[0]->newInstance();

        return new PluginDefinition(
            id: $attribute->id,
            label: $attribute->label,
            class: $class,
            description: $attribute->description,
            package: $attribute->package,
        );
    }
}
