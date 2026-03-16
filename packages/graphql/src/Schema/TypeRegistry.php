<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Schema;

use GraphQL\Type\Definition\Type;

/**
 * Memoized type store — prevents duplicate type names in the GraphQL schema.
 *
 * Types are registered on first creation and returned from cache on subsequent
 * requests. This handles circular references: ObjectType A is stored before its
 * lazy fields thunk runs, so when type B's fields reference A, getOrCreate()
 * returns the existing instance.
 */
final class TypeRegistry
{
    /** @var array<string, Type> */
    private array $types = [];

    /**
     * @param \Closure(): Type $factory
     */
    public function getOrCreate(string $name, \Closure $factory): Type
    {
        return $this->types[$name] ??= $factory();
    }

    public function has(string $name): bool
    {
        return isset($this->types[$name]);
    }

    public function get(string $name): ?Type
    {
        return $this->types[$name] ?? null;
    }
}
