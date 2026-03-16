<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Catalog;

/**
 * Declarative builder for the admin entity catalog.
 *
 * Applications use this in their AdminSurfaceHost::buildCatalog() to
 * define which entity types, fields, and actions are available in the admin.
 *
 * Output matches AdminSurfaceCatalog in contract/types.ts.
 */
final class CatalogBuilder
{
    /** @var EntityDefinition[] */
    private array $entities = [];

    /**
     * Define an entity type in the catalog.
     */
    public function defineEntity(string $id, string $label): EntityDefinition
    {
        $entity = new EntityDefinition($id, $label);
        $this->entities[] = $entity;
        return $entity;
    }

    /**
     * Build the catalog as an array of entity definitions.
     *
     * @return array<int, array<string, mixed>>
     */
    public function build(): array
    {
        return array_map(
            fn(EntityDefinition $e) => $e->toArray(),
            $this->entities,
        );
    }
}
