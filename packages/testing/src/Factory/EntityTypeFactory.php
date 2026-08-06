<?php

declare(strict_types=1);

namespace Waaseyaa\Testing\Factory;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Field\FieldDefinitionInterface;

/**
 * Builds synthetic entity-type shapes without consuming another package's
 * test-only autoload namespace.
 *
 * @api
 */
final class EntityTypeFactory
{
    /**
     * @param array<string, FieldDefinitionInterface|array<string, mixed>> $fieldDefinitions
     * @param array<string, string> $keys
     * @param class-string<EntityInterface>|null $class
     */
    public static function create(
        string $id,
        array $fieldDefinitions = [],
        array $keys = ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label'],
        ?string $class = null,
        ?string $label = null,
    ): EntityType {
        $class ??= self::syntheticClassName($id);
        $label ??= \ucfirst(\str_replace('_', ' ', $id));

        return new EntityType(
            id: $id,
            label: $label,
            class: $class,
            keys: $keys,
            _fieldDefinitions: $fieldDefinitions,
        );
    }

    /** @return class-string<EntityInterface> */
    private static function syntheticClassName(string $id): string
    {
        $studly = \str_replace([' ', '_'], '', \ucwords($id, '_'));

        /** @var class-string<EntityInterface> $class */
        $class = 'Waaseyaa\\Testing\\Fixture\\SyntheticEntity\\' . $studly;

        return $class;
    }
}
