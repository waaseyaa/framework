<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

/** Declarative storage foreign keys carried by an entity-type definition. */
interface EntityTypeForeignKeyDefinitionInterface
{
    /**
     * @return list<array{name: string, columns: list<string>, table: string, references: list<string>, options?: array<string, mixed>}>
     */
    public function getStorageForeignKeys(): array;
}
