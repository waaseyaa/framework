<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Attribute;

use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Field\FieldDefinition;

/**
 * Resolves {@see ContentEntityType}, {@see ContentEntityKeys}, and {@see Field}
 * attributes for a class with per-class caching.
 * @api
 */
final class EntityMetadataReader
{
    /** @var array<string, EntityClassMetadata> */
    private static array $cache = [];

    /**
     * @param class-string $class
     */
    public static function forClass(string $class): EntityClassMetadata
    {
        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }

        if (!class_exists($class)) {
            return self::$cache[$class] = new EntityClassMetadata(null, []);
        }

        $typeId = self::resolveTypeId($class);
        $keys = self::resolveKeys($class);
        $labelDescription = self::resolveLabelAndDescription($class);
        $fields = self::resolveFields($class, $typeId);

        return self::$cache[$class] = new EntityClassMetadata(
            typeId: $typeId,
            keys: $keys,
            label: $labelDescription['label'],
            description: $labelDescription['description'],
            api: $labelDescription['api'],
            fields: $fields,
        );
    }

    /**
     * @param class-string $class
     */
    public static function clearCacheForClass(string $class): void
    {
        unset(self::$cache[$class]);
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Resolve the field map for a class by walking the hierarchy from the first
     * concrete class below {@see ContentEntityBase} down to $class. Child classes
     * override parent fields with the same property name.
     *
     * @param class-string $class
     * @param string|null $entityTypeId Resolved entity type id to stamp on each
     *   FieldDefinition. When null, fields are produced with an empty
     *   targetEntityTypeId (matching the pre-FR-1 default for callers that
     *   don't yet know the id).
     * @return array<string, FieldDefinition>
     */
    public static function resolveFields(string $class, ?string $entityTypeId = null): array
    {
        if (!is_subclass_of($class, ContentEntityBase::class)) {
            return [];
        }

        $chain = [];
        $r = new \ReflectionClass($class);
        while ($r->getName() !== ContentEntityBase::class) {
            $chain[] = $r;
            $parent = $r->getParentClass();
            if ($parent === false) {
                break;
            }
            $r = $parent;
        }

        $chain = array_reverse($chain);

        $fields = [];
        foreach ($chain as $ref) {
            foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                // Only consider properties declared on this class — inheritance is
                // handled by walking the chain itself, so we avoid double-processing
                // properties surfaced by the child reflection.
                if ($property->getDeclaringClass()->getName() !== $ref->getName()) {
                    continue;
                }

                $attributes = $property->getAttributes(Field::class);
                if ($attributes === []) {
                    continue;
                }

                $field = $attributes[0]->newInstance();
                $inferred = FieldTypeInferrer::infer($property, $field);

                $fields[$property->getName()] = new FieldDefinition(
                    name: $property->getName(),
                    type: $inferred['type'],
                    cardinality: 1,
                    settings: $inferred['settings'],
                    targetEntityTypeId: $entityTypeId ?? '',
                    translatable: $field->translatable,
                    revisionable: $field->revisionable,
                    defaultValue: $field->default,
                    label: $field->label,
                    description: $field->description,
                    required: $inferred['required'],
                    readOnly: $field->readOnly,
                    stored: $field->stored,
                );
            }
        }

        return $fields;
    }

    /**
     * @param class-string $class
     */
    private static function resolveTypeId(string $class): ?string
    {
        $ref = new \ReflectionClass($class);
        while (true) {
            foreach ($ref->getAttributes(ContentEntityType::class) as $attr) {
                return $attr->newInstance()->id;
            }
            $parent = $ref->getParentClass();
            if ($parent === false || $parent->getName() === \Waaseyaa\Entity\EntityBase::class) {
                break;
            }
            $ref = $parent;
        }

        return null;
    }

    /**
     * Locate the nearest {@see ContentEntityType} attribute walking up the class
     * hierarchy and surface its label/description fields.
     *
     * @param class-string $class
     * @return array{label: string, description: string, api: bool}
     */
    private static function resolveLabelAndDescription(string $class): array
    {
        $ref = new \ReflectionClass($class);
        while (true) {
            foreach ($ref->getAttributes(ContentEntityType::class) as $attr) {
                $instance = $attr->newInstance();

                return [
                    'label' => $instance->label,
                    'description' => $instance->description,
                    'api' => $instance->api,
                ];
            }
            $parent = $ref->getParentClass();
            if ($parent === false || $parent->getName() === \Waaseyaa\Entity\EntityBase::class) {
                break;
            }
            $ref = $parent;
        }

        return ['label' => '', 'description' => '', 'api' => false];
    }

    /**
     * Walk from the first concrete ancestor below {@see ContentEntityBase} to $class; merge
     * {@see ContentEntityKeys} (later classes override), then apply identity defaults.
     *
     * @param class-string $class
     * @return array<string, string>
     */
    private static function resolveKeys(string $class): array
    {
        if (!is_subclass_of($class, ContentEntityBase::class)) {
            return [];
        }

        $chain = [];
        $r = new \ReflectionClass($class);
        while ($r->getName() !== ContentEntityBase::class) {
            $chain[] = $r;
            $parent = $r->getParentClass();
            if ($parent === false) {
                break;
            }
            $r = $parent;
        }

        $chain = array_reverse($chain);

        $keys = [];
        foreach ($chain as $ref) {
            foreach ($ref->getAttributes(ContentEntityKeys::class) as $a) {
                $i = $a->newInstance();
                if ($i->id !== null) {
                    $keys['id'] = $i->id;
                }
                if ($i->uuid !== null) {
                    $keys['uuid'] = $i->uuid;
                }
                if ($i->label !== null) {
                    $keys['label'] = $i->label;
                }
                if ($i->bundle !== null) {
                    $keys['bundle'] = $i->bundle;
                }
                if ($i->revision !== null) {
                    $keys['revision'] = $i->revision;
                }
                if ($i->langcode !== null) {
                    $keys['langcode'] = $i->langcode;
                }
                if ($i->default_langcode !== null) {
                    $keys['default_langcode'] = $i->default_langcode;
                }
            }
        }

        foreach (['id' => 'id', 'uuid' => 'uuid', 'label' => 'label'] as $logical => $storage) {
            if (!isset($keys[$logical])) {
                $keys[$logical] = $storage;
            }
        }

        $ordered = [];
        foreach (['id', 'uuid', 'label', 'bundle', 'revision', 'langcode', 'default_langcode'] as $logical) {
            if (\array_key_exists($logical, $keys)) {
                $ordered[$logical] = $keys[$logical];
            }
        }

        return $ordered;
    }
}
